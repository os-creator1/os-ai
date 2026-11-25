<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Controllers\Customer\Workspace\Concerns\ResolvesAgencyProspectingWorkspace;
use App\Library\AgencyOutreach\Import\ProspectCsvReader;
use App\Library\AgencyOutreach\Import\ProspectFieldMapper;
use App\Library\AgencyOutreach\Import\ProspectImporter;
use App\Library\AgencyOutreach\Import\ProspectGroupMaterializer;
use App\Library\AgencyOutreach\OutreachContactGroupBridge;
use App\Library\AgencyOutreach\OutreachExistingClients;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Models\Business;
use App\Models\ContactGroups;
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

        $fileRules = ['required', 'file', 'max:5120', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel,text/x-csv'];
        $multiple = $request->has('files');
        $request->validate([
            'file_count' => ['nullable', 'integer', 'min:1'],
            ...($multiple ? ['files' => ['required', 'array', 'min:1'], 'files.*' => $fileRules] : ['file' => $fileRules]),
            'kind' => ['nullable', 'in:prospects,existing_clients'],
        ]);

        $files = $multiple ? $request->file('files') : [$request->file('file')];
        if ($request->filled('file_count') && count($files) !== (int) $request->input('file_count')) {
            return back()->withErrors(['file' => 'Some selected files did not reach the server. Upload fewer files at a time.']);
        }

        $names = [];
        $parsed = ['rows' => [], 'headers' => [], 'truncated' => false];

        foreach ($files as $file) {
            $name = basename($file->getClientOriginalName());
            if (! in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt', 'tsv'], true)) {
                return back()->withErrors(['file' => "Choose a CSV, TXT or TSV file instead of {$name}."]);
            }

            $part = ProspectCsvReader::read($file->getRealPath());
            if (isset($part['error']) || $part['rows'] === []) {
                return back()->withErrors(['file' => $name . ': ' . ($part['error'] ?? 'That file has a header row but no data rows.')]);
            }

            if ($names !== [] && (array_diff($part['headers'], $parsed['headers']) || array_diff($parsed['headers'], $part['headers']))) {
                return back()->withErrors(['file' => "{$name} has different column names. Upload it separately and select the same contact group."]);
            }

            if (count($files) > 1 && ($part['truncated'] || count($parsed['rows']) + count($part['rows']) > ProspectCsvReader::MAX_ROWS)) {
                return back()->withErrors(['file' => 'Upload up to 5,000 rows at once. Add the remaining files to the same group in another import.']);
            }

            $names[] = $name;
            $parsed['headers'] = $part['headers'];
            $parsed['truncated'] = $part['truncated'];
            $parsed['rows'] = array_merge($parsed['rows'], $part['rows']);
        }

        $filename = count($names) === 1 ? $names[0] : $names[0] . ' + ' . (count($names) - 1) . ' more';
        $batch = $this->importer->createBatch($workspace, $request->input('kind', 'prospects'), $filename, $parsed, (int) Auth::id());

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
        $business = app(OutreachContactGroupBridge::class)->businessFor($workspace, Auth::user());

        return view('customer.workspaces.prospecting.import-review', [
            'workspaceUid' => $workspaceUid,
            'batch' => $b,
            'summary' => (array) $b->summary,
            'rows' => $rows,
            'outcome' => $outcome,
            'samples' => $firstRows->map(fn ($r) => $r->original)->all(),
            'regions' => self::REGIONS,
            'step' => $b->status === 'imported' ? 3 : 2,
            'business' => $business,
            'fieldPlan' => $business !== null && $b->kind === 'prospects' ? app(ProspectGroupMaterializer::class)->plan($business, $b) : [],
            'definitions' => $business === null ? collect() : app(CustomFieldDefinitionManager::class)->forBusiness($business),
            'groups' => $business !== null && $b->kind === 'prospects' && $b->status !== 'imported'
                ? ContactGroups::where('business_id', $business->id)->where('status', 1)->orderBy('name')->pluck('name', 'uid') : [],
            'defaultGroupName' => $b->group_name ?: trim((string) preg_replace('/\.(csv|txt|tsv)$/i', '', (string) $b->filename)) ?: 'Imported prospects',
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

        // A column may be saved as a built-in field, as a Custom Field (new, or one that already exists), or ignored.
        $submitted = (array) $request->input('mapping', []);
        $business = app(OutreachContactGroupBridge::class)->businessFor($workspace, Auth::user());
        $definitions = app(CustomFieldDefinitionManager::class);
        $extras = [];
        $builtin = [];

        foreach (array_values((array) $b->headers) as $i => $header) {
            $value = is_string($submitted[$i] ?? null) ? $submitted[$i] : '';

            if ($value === 'cf_new' && $business !== null) {
                $extras[$header] = ['action' => 'new', 'label' => mb_substr(trim($header), 0, CustomFieldDefinitionManager::MAX_LABEL)];
            } elseif (preg_match('/^cf:([A-Za-z0-9_-]{1,64})$/', $value, $m) === 1 && $business !== null && $definitions->findByUid($business, $m[1]) !== null) {
                $extras[$header] = ['action' => 'existing', 'definition_uid' => $m[1]];
            } else {
                $builtin[$i] = $value;
            }
        }

        $mapping = ProspectFieldMapper::sanitise($builtin, (array) $b->headers);
        $b->update(['extra_fields' => $extras]);
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

        $business = app(OutreachContactGroupBridge::class)->businessFor($workspace, Auth::user());

        if ($business === null) {
            return back()->with('flash_error', 'Your Outreach business is not set up, so a contact group cannot be created yet.');
        }

        // The import writes Contacts and Custom Field definitions/values, so it needs the same permissions as the normal CRM endpoints
        // (create_contact / update_contact), checked BEFORE anything is written.
        if (! Auth::user()->can('create_contact') || ! Auth::user()->can('update_contact')) {
            return back()->with('flash_error', 'You do not have permission to create or update contacts, so this import cannot create the contact group.');
        }

        $groupExists = $b->contact_group_id !== null;
        $destination = $request->validate(['contact_group_uid' => ['nullable', 'string', 'max:64']])['contact_group_uid'] ?? null;
        $groupName = $groupExists || $destination !== null
            ? (string) $b->group_name
            : trim((string) $request->validate(['group_name' => ['required', 'string', 'max:255']], ['group_name.required' => 'Name the contact group for this import.'])['group_name']);

        if (! $groupExists && $destination === null) {
            if (! Auth::user()->can('create_contact_group')) {
                return back()->withInput()->withErrors(['group_name' => 'You do not have permission to create contact groups.']);
            }

            if (ContactGroups::where('customer_id', $business->customer_id)->where('name', $groupName)->exists()) {
                return back()->withInput()->withErrors(['group_name' => 'A contact group with this name already exists. Choose a different name.']);
            }
        }

        try {
            $r = $this->importer->commitWithGroup($workspace, $business, $b, $groupName, $request->input('campaign_uid'), (int) Auth::id(), $destination);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return back()->with('flash_error', $e->getMessage() . ' Nothing was imported.');
        }

        if (! empty($r['name_conflict'])) {
            return back()->withInput()->withErrors(['group_name' => 'A contact group with this name already exists. Choose a different name. Nothing was imported.']);
        }

        if (! empty($r['prospects']['campaign_closed'])) {
            return redirect()->route('customer.workspaces.prospecting.prospects.import.review', [$workspaceUid, $b->uid])
                ->with('flash_error', 'That campaign has already started, so prospects cannot be added to it. Nothing was imported; choose another destination.');
        }

        return redirect()->route('customer.workspaces.prospecting.prospects.import.done', [$workspaceUid, $b->uid]);
    }

    public function importDone(string $workspaceUid, string $batch)
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $b = $this->batch($workspace, $batch);
        abort_unless($b->kind === 'prospects' && $b->status === 'imported', 404);

        $business = app(OutreachContactGroupBridge::class)->businessFor($workspace, Auth::user());
        $group = $business === null || $b->contact_group_id === null ? null
            : ContactGroups::query()->where('id', $b->contact_group_id)->where('business_id', $business->id)->first();

        return view('customer.workspaces.prospecting.import-done', [
            'workspaceUid' => $workspaceUid, 'batch' => $b, 'group' => $group, 'business' => $business,
            'report' => (array) $b->group_report, 'summary' => (array) $b->summary, 'step' => 3,
        ]);
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
