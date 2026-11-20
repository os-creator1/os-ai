<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Controllers\Customer\Workspace\Concerns\ResolvesAgencyProspectingWorkspace;
use App\Library\AgencyOutreach\Import\ProspectCsvReader;
use App\Library\AgencyOutreach\Import\ProspectFieldMapper;
use App\Library\AgencyOutreach\Import\ProspectImporter;
use App\Library\AgencyOutreach\OutreachExistingClients;
use App\Library\AgencyProspecting\AgencyProspectPhoneNormalizer;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectExclusion;
use App\Models\AgencyProspectImportBatch;
use App\Models\AgencyProspectImportRow;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use App\Library\Entitlement\EntitlementManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Outreach → Prospects: Existing clients, the STOP list, "Client won", and the smart CSV import.
 * Every action resolves the Workspace through the same owner/Admin + entitlement invariant as the rest
 * of Outreach, and every record is looked up INSIDE that Workspace (another Workspace's uid is a 404).
 */
class AgencyOutreachClientsController extends CustomerBaseController
{
    use ResolvesAgencyProspectingWorkspace;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly EntitlementManager $entitlementManager,
        private readonly OutreachExistingClients $clients,
        private readonly ProspectImporter $importer,
    ) {
    }

    // ---------------------------------------------------------------- Existing clients

    public function clients(Request $request, string $workspaceUid)
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $q = trim((string) $request->query('q', ''));
        $by = (string) $request->query('by', 'anyone');
        $digits = preg_replace('/\D/', '', $q) ?? '';

        $query = AgencyProspectExclusion::query()->where('workspace_id', $workspace->id)->whereNull('removed_at');

        if ($q !== '') {
            $query->where(function ($w) use ($q, $digits) {
                $w->where('business_name', 'like', '%' . addcslashes($q, '%_\\') . '%');

                if ($digits !== '') {
                    $w->orWhere('phone', 'like', '%' . $digits . '%');
                }
            });
        }

        if ($by === 'me') {
            $query->where('created_by_user_id', Auth::id());
        }

        $rows = $query->orderByDesc('id')->paginate(25)->withQueryString();

        return view('customer.workspaces.prospecting.clients', [
            'workspaceUid' => $workspaceUid,
            'rows' => $rows,
            'q' => $q,
            'by' => $by,
            'counts' => $this->counts($workspace),
            'reviewHints' => $this->similarNameHints($workspace),
            'ownerId' => (int) $workspace->owner_user_id,
            'userId' => (int) Auth::id(),
            'clientsService' => $this->clients,
            'workspace' => $workspace,
        ]);
    }

    public function addClient(Request $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:64'],
            'country_code' => ['nullable', 'string', 'regex:/^\+\d{1,3}$/'],
        ]);

        $typed = trim($data['phone']);
        if (($data['country_code'] ?? null) !== null && ! str_starts_with($typed, '+') && ! str_starts_with($typed, '00')) {
            $typed = $data['country_code'] . ' ' . $typed;
        }

        $result = $this->clients->add($workspace, $typed, $data['business_name'], AgencyProspectExclusion::REASON_MANUAL, 'Added by you', (int) Auth::id());

        if (! $result['ok']) {
            return back()->withInput()->withErrors(['phone' => $result['error']]);
        }

        return redirect()->route('customer.workspaces.prospecting.prospects.clients', $workspaceUid)
            ->with('flash_success', $result['created'] ? 'Existing client added. They will not be prospected.' : 'That number was already an existing client.');
    }

    public function removeClient(string $workspaceUid, string $exclusion): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $row = AgencyProspectExclusion::query()->where('workspace_id', $workspace->id)->where('uid', $exclusion)->whereNull('removed_at')->firstOrFail();

        abort_unless($this->clients->mayRemove($workspace, $row, (int) Auth::id()), 403);

        $this->clients->remove($workspace, $row, (int) Auth::id());

        return redirect()->route('customer.workspaces.prospecting.prospects.clients', $workspaceUid)
            ->with('flash_success', 'Removed from existing clients. If this person ever replied STOP, they stay opted out.');
    }

    public function markClientWon(string $workspaceUid, AgencyProspect $prospect): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        abort_unless((int) $prospect->workspace_id === (int) $workspace->id, 404);

        $this->clients->markClientWon($workspace, $prospect, (int) Auth::id());

        return redirect()->route('customer.workspaces.prospecting.prospects.show', [$workspaceUid, $prospect->uid])
            ->with('flash_success', 'Marked as client won. Outreach will not text them again.');
    }

    // ---------------------------------------------------------------- Excluded (STOP)

    public function excluded(string $workspaceUid)
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $rows = AgencyProspect::query()->where('workspace_id', $workspace->id)
            ->where('status', AgencyProspectStatus::Stopped->value)
            ->orderByDesc('stopped_at')->orderByDesc('id')->paginate(25);

        return view('customer.workspaces.prospecting.excluded', [
            'workspaceUid' => $workspaceUid, 'rows' => $rows, 'counts' => $this->counts($workspace),
        ]);
    }

    // ---------------------------------------------------------------- Smart CSV import

    public function importForm(string $workspaceUid)
    {
        $this->resolveEntitledWorkspace($workspaceUid);

        return view('customer.workspaces.prospecting.import-upload', ['workspaceUid' => $workspaceUid, 'step' => 1]);
    }

    public function importUpload(Request $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel,text/x-csv'],
            'kind' => ['nullable', 'in:prospects,existing_clients'],
        ]);

        $file = $request->file('file');
        abort_unless(in_array(strtolower((string) $file->getClientOriginalExtension()), ['csv', 'txt', 'tsv'], true), 422);

        $parsed = ProspectCsvReader::read($file->getRealPath());

        if (isset($parsed['error'])) {
            return back()->withErrors(['file' => $parsed['error']]);
        }

        if ($parsed['rows'] === []) {
            return back()->withErrors(['file' => 'That file has a header row but no data rows.']);
        }

        $batch = $this->importer->createBatch($workspace, $request->input('kind', 'prospects'), $file->getClientOriginalName(), $parsed, (int) Auth::id());

        return redirect()->route('customer.workspaces.prospecting.prospects.import.review', [$workspaceUid, $batch->uid]);
    }

    public function importReview(Request $request, string $workspaceUid, string $batch)
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $b = $this->batch($workspace, $batch);
        $outcome = (string) $request->query('outcome', '');

        $rows = AgencyProspectImportRow::query()->where('batch_id', $b->id)
            ->when(isset(ProspectImporter::OUTCOME_LABELS[$outcome]), fn ($q) => $q->where('outcome', $outcome))
            ->orderBy('row_number')->limit(100)->get();

        $firstRows = AgencyProspectImportRow::query()->where('batch_id', $b->id)->orderBy('row_number')->limit(3)->get(['original']);

        return view('customer.workspaces.prospecting.import-review', [
            'workspaceUid' => $workspaceUid,
            'batch' => $b,
            'summary' => (array) $b->summary,
            'rows' => $rows,
            'outcome' => $outcome,
            'samples' => $firstRows->map(fn ($r) => $r->original)->all(),
            'draftCampaigns' => AgencyProspectCampaign::query()->where('workspace_id', $workspace->id)
                ->where('status', AgencyProspectCampaignStatus::Draft->value)->orderBy('name')->get(['uid', 'name']),
            'regions' => self::REGIONS,
            'step' => $b->status === 'imported' ? 3 : 2,
        ]);
    }

    public function importRemap(Request $request, string $workspaceUid, string $batch): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $b = $this->batch($workspace, $batch);
        abort_if($b->status === 'imported', 409);

        $request->validate([
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:40'],
            'region' => ['nullable', 'string', 'size:2', 'in:' . implode(',', array_keys(self::REGIONS))],
        ]);

        $mapping = ProspectFieldMapper::sanitise((array) $request->input('mapping', []), (array) $b->headers);
        $this->importer->analyze($workspace, $b, $mapping, $request->input('region') ?: null);

        return redirect()->route('customer.workspaces.prospecting.prospects.import.review', [$workspaceUid, $b->uid])
            ->with('flash_success', 'Fields updated. Counts recalculated.');
    }

    public function importCommit(Request $request, string $workspaceUid, string $batch): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $b = $this->batch($workspace, $batch);

        $request->validate(['campaign_uid' => ['nullable', 'string', 'max:64']]);

        if (! in_array('phone', array_values((array) $b->mapping), true)) {
            return back()->with('flash_error', 'Choose which column holds the phone number before importing.');
        }

        if ($b->kind === 'existing_clients') {
            $r = $this->importer->commitClients($workspace, $b, (int) Auth::id());

            return redirect()->route('customer.workspaces.prospecting.prospects.clients', $workspaceUid)
                ->with('flash_success', $r['already_imported'] ? 'That file was already imported.' : "{$r['created']} existing clients added ({$r['already']} were already listed).");
        }

        $r = $this->importer->commitProspects($workspace, $b, $request->input('campaign_uid'));

        return redirect()->route('customer.workspaces.prospecting.prospects.index', $workspaceUid)
            ->with('flash_success', $r['already']
                ? 'That file was already imported.'
                : "Imported {$r['created']} new prospects ({$r['updated']} existing updated). No messages were sent.");
    }

    // ---------------------------------------------------------------- helpers

    private function batch(Workspace $workspace, string $uid): AgencyProspectImportBatch
    {
        return AgencyProspectImportBatch::query()->where('workspace_id', $workspace->id)->where('uid', $uid)->firstOrFail();
    }

    /** @return array{all: int, clients: int, excluded: int} */
    private function counts(Workspace $workspace): array
    {
        return [
            'all' => AgencyProspect::query()->where('workspace_id', $workspace->id)->count(),
            'clients' => AgencyProspectExclusion::query()->where('workspace_id', $workspace->id)->whereNull('removed_at')->count(),
            'excluded' => AgencyProspect::query()->where('workspace_id', $workspace->id)->where('status', AgencyProspectStatus::Stopped->value)->count(),
        ];
    }

    /**
     * "Looks similar" review hints: an ACTIVE prospect whose name resembles an existing client but whose
     * number is different. Hints only; nothing is ever blocked by name.
     *
     * @return list<array{prospect: string, client: string}>
     */
    private function similarNameHints(Workspace $workspace): array
    {
        $clients = AgencyProspectExclusion::query()->where('workspace_id', $workspace->id)->whereNull('removed_at')
            ->whereNotNull('business_name')->limit(300)->get(['phone', 'business_name']);

        if ($clients->isEmpty()) {
            return [];
        }

        $hints = [];

        AgencyProspect::query()->where('workspace_id', $workspace->id)->where('status', AgencyProspectStatus::Active->value)
            ->whereNull('client_won_at')->orderByDesc('id')->limit(500)->get(['company_name', 'phone'])
            ->each(function ($p) use ($clients, &$hints) {
                foreach ($clients as $c) {
                    if ((string) $c->phone !== (string) $p->phone
                        && \App\Library\AgencyOutreach\Import\ProspectRowNormalizer::namesSimilar($p->company_name, $c->business_name)) {
                        $hints[] = ['prospect' => $p->company_name, 'client' => $c->business_name];
                        break;
                    }
                }
            });

        return array_slice($hints, 0, 5);
    }

    public const REGIONS = ['US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom', 'AU' => 'Australia', 'NZ' => 'New Zealand',
        'IE' => 'Ireland', 'DE' => 'Germany', 'FR' => 'France', 'ES' => 'Spain', 'IT' => 'Italy', 'NL' => 'Netherlands', 'MX' => 'Mexico', 'BR' => 'Brazil', 'IN' => 'India'];
}
