<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Library\Ai\AiAllowanceReader;
use App\Library\Assistant\AssistantActionService;
use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\AssistantPresenter;
use App\Library\Assistant\AssistantService;
use App\Library\Dashboard\Home\HomeActionRouter;
use App\Library\Dashboard\Home\HomeBookingReader;
use App\Library\Navigation\CustomerShellComposer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Ask MotionGrove on Home — the conversation, and the approval of the actions it prepares.
 *
 * Every request re-derives Workspace, Business and actor from the AUTHENTICATED route (the same chain Home's other
 * endpoints run), and every conversation and proposal is looked up by uid AND that triple, so a guessed uid from
 * another customer, Business or Workspace is a 404. Nothing here talks to a model or a module itself: the service
 * classes do, and the write itself is always a domain mechanism, run only after the customer's explicit approval.
 */
class AssistantController extends Controller
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly CustomerShellComposer $shell,
        private readonly HomeActionRouter $router,
        private readonly HomeBookingReader $booking,
        private readonly AssistantService $assistant,
        private readonly AssistantActionService $actions,
        private readonly AssistantPresenter $presenter,
        private readonly AiAllowanceReader $allowance,
    ) {
    }

    /** The newest conversation (or none yet) plus the current allowance state. Reads only; never calls a model. */
    public function show(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        $context = $this->context($workspaceUid, $businessUid);
        $request->validate([
            'conversation' => ['nullable', 'string', 'max:64'],
            // The cursor of an older page is a message uid; a page is bounded however it is asked for.
            'before' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:' . (int) config('assistant.message_page_max')],
        ]);
        $uid = $request->query('conversation');
        $before = $request->query('before');
        $limit = (int) ($request->query('limit') ?: config('assistant.message_page_size'));
        $conversation = is_string($uid) && $uid !== '' ? ($this->assistant->find($context, $uid) ?? abort(404)) : $this->assistant->latest($context);

        if (is_string($before) && $before !== '' && $conversation === null) {
            abort(404);
        }

        if ($conversation === null) {
            return response()->json([
                'uid' => null,
                // First use: tell the customer how AI is used before they send anything.
                'notice' => ['title' => __('ask_ai.data_notice.title'), 'body' => $this->assistant->noticeText()],
                'messages' => [],
                'allowance' => $this->presenter->allowanceView($context, $this->allowance->forWorkspace($context->workspace, $context->business)),
            ]);
        }

        return response()->json($this->presenter->conversation($context, $conversation, $limit, is_string($before) && $before !== '' ? $before : null));
    }

    /** The history panel: this customer's conversations in this Business, newest activity first, one page at a time. Reads only. */
    public function index(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        $request->validate(['after' => ['nullable', 'uuid']]);
        $context = $this->context($workspaceUid, $businessUid);
        $after = $request->query('after');

        return response()->json($this->assistant->history($context, is_string($after) && $after !== '' ? $after : null));
    }

    public function message(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:' . (int) config('assistant.question_max_chars')],
            'client_message_id' => ['required', 'uuid'],
            'conversation' => ['nullable', 'string', 'max:64'],
            'new' => ['sometimes', 'boolean'],
        ]);

        $context = $this->context($workspaceUid, $businessUid);

        $result = $this->assistant->send($context, $data['conversation'] ?? null, $data['q'], $data['client_message_id'], (bool) ($data['new'] ?? false));

        return response()->json($result, ($result['status'] ?? '') === 'busy' ? 409 : 200);
    }

    /** The customer deletes one of THEIR conversations (messages, summary, prepared-action text). */
    public function destroy(string $workspaceUid, string $businessUid, string $conversationUid): JsonResponse
    {
        $context = $this->context($workspaceUid, $businessUid);

        if (! $this->assistant->delete($context, $conversationUid)) {
            return response()->json(['deleted' => false, 'message' => 'This conversation is busy right now (a reply or an approved action is still in progress). Nothing was deleted — please try again in a moment.'], 409);
        }

        return response()->json(['deleted' => true]);
    }

    public function approve(string $workspaceUid, string $businessUid, string $proposalUid): JsonResponse
    {
        $context = $this->context($workspaceUid, $businessUid);
        $result = $this->actions->approve($context, $proposalUid);
        $this->assistant->recordOutcome($context, $result);

        return $this->proposalResponse($context, $result);
    }

    public function decline(string $workspaceUid, string $businessUid, string $proposalUid): JsonResponse
    {
        $context = $this->context($workspaceUid, $businessUid);
        $result = $this->actions->decline($context, $proposalUid);
        $this->assistant->recordOutcome($context, $result);

        return $this->proposalResponse($context, $result);
    }

    /** @param array{state: string, proposal: \App\Models\AssistantActionProposal, message: ?string, url: ?string} $result */
    private function proposalResponse(AssistantContext $context, array $result): JsonResponse
    {
        return response()->json([
            'state' => $result['state'],
            'proposal' => $this->presenter->proposal($result['proposal']),
            'message' => $result['message'],
            'url' => $result['url'],
        ], $result['state'] === 'busy' ? 409 : 200);
    }

    private function context(string $workspaceUid, string $businessUid): AssistantContext
    {
        [$workspace, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);

        /** @var User $user */
        $user = Auth::user();
        $customer = $this->shell->currentContext($user);

        // The context must be THIS route's Business — never "whatever is selected".
        abort_unless($customer->isBusinessFrame()
            && $customer->selectedBusiness !== null
            && $customer->selectedBusiness->uid === $businessUid
            && $customer->frameWorkspace()?->uid === $workspaceUid, 404);

        $entitlements = $this->shell->currentMenuEntitlements($customer);
        $summary = $entitlements->allows('calendar') && Gate::forUser($user)->allows('access_backend')
            ? $this->booking->summary($business, (int) $user->id)
            : null;

        return new AssistantContext(
            $workspace,
            $business,
            $user,
            $customer,
            $entitlements,
            $this->router->forActor($customer, $user, $entitlements, [$workspaceUid, $businessUid], $summary['firstLocationUid'] ?? null),
            [$workspaceUid, $businessUid],
        );
    }
}
