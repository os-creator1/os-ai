<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Controllers\Customer\Workspace\Concerns\ResolvesAgencyProspectingWorkspace;
use App\Library\AgencyOutreach\OutreachConversationLinks;
use App\Library\AgencyOutreach\OutreachFaq;
use App\Library\AgencyOutreach\OutreachUnansweredQuestions;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyProspectUnansweredQuestion;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Outreach -> Unanswered questions: the prospect questions no approved answer covered, and
 * the owner teaching the bot from them (Add answer / Link existing answer / Dismiss).
 *
 * Workspace-scoped through the same resolver as every Outreach screen (owner or active Admin
 * + ProspectOutreach entitlement; anything else is a 404), and every item is looked up inside
 * that Workspace. Teaching changes FUTURE replies only: nothing here ever texts a prospect.
 */
class AgencyOutreachUnansweredController extends CustomerBaseController
{
    use ResolvesAgencyProspectingWorkspace;

    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly EntitlementManager $entitlementManager,
        private readonly OutreachUnansweredQuestions $queue,
    ) {
    }

    public function queue(string $workspaceUid)
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $items = AgencyProspectUnansweredQuestion::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', AgencyProspectUnansweredQuestion::STATUS_OPEN)
            ->with(['campaign:id,name', 'campaignMember'])
            ->orderByDesc('id')
            ->paginate(self::PAGE_SIZE);

        $members = $items->getCollection()->pluck('campaignMember')->filter()->unique('id');
        $links = app(OutreachConversationLinks::class)->forMembers($workspace, $members);
        $faq = OutreachFaq::forWorkspace($workspace);

        return view('customer.workspaces.prospecting.unanswered', [
            'workspaceUid' => $workspaceUid,
            'items' => $items,
            'links' => $links,
            'options' => $items->getCollection()->mapWithKeys(fn (AgencyProspectUnansweredQuestion $item) => [
                $item->id => array_map(
                    fn (array $e) => ['id' => $e['id'], 'question' => $e['question'] !== '' ? $e['question'] : mb_substr($e['answer'], 0, 60)],
                    $faq->applicable($item->campaign_id === null ? null : (int) $item->campaign_id),
                ),
            ])->all(),
            'niche' => $this->niche($workspace),
        ]);
    }

    public function addAnswer(Request $request, string $workspaceUid, string $item): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $row = $this->item($workspace, $item);

        $data = $request->validate([
            'question' => ['nullable', 'string', 'max:300'],
            'answer' => ['required', 'string', 'max:1000'],
            'scope' => ['nullable', 'in:all,campaign'],
        ]);

        $this->queue->addAnswer($workspace, $row, (string) ($data['question'] ?? ''), $data['answer'], ($data['scope'] ?? 'all') === 'campaign', Auth::user());

        return $this->back($workspaceUid, 'Answer saved. Future replies will use it; nothing was sent to this prospect.');
    }

    public function linkAnswer(Request $request, string $workspaceUid, string $item): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $row = $this->item($workspace, $item);

        $data = $request->validate(['faq_id' => ['required', 'string', 'max:64']]);

        $this->queue->linkAnswer($workspace, $row, $data['faq_id'], Auth::user());

        return $this->back($workspaceUid, 'Linked. This wording will get that answer from now on; nothing was sent to this prospect.');
    }

    public function dismiss(string $workspaceUid, string $item): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $this->queue->dismiss($workspace, $this->item($workspace, $item), Auth::user());

        return $this->back($workspaceUid, 'Dismissed.');
    }

    private function item(Workspace $workspace, string $uid): AgencyProspectUnansweredQuestion
    {
        $row = AgencyProspectUnansweredQuestion::query()->where('workspace_id', $workspace->id)->where('uid', $uid)->first();

        abort_if($row === null, 404);

        return $row;
    }

    private function niche(Workspace $workspace): ?string
    {
        return trim((string) \App\Library\AgencyOutreach\OutreachScript::forWorkspace($workspace)->settings()?->niche) ?: null;
    }

    private function back(string $workspaceUid, string $message): RedirectResponse
    {
        return redirect()->route('customer.workspaces.prospecting.unanswered.index', $workspaceUid)->with('flash_success', $message);
    }
}
