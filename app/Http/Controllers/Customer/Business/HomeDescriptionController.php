<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Library\Navigation\CustomerShellComposer;
use App\Library\Opportunity\DescriptionImprover;
use App\Library\Opportunity\DescriptionProposal;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\User;
use App\Repositories\Contracts\OpportunityActionExecutionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Home — the inline "Complete your business description" proposal.
 *
 * Approve & save never writes Business.description itself. It drives the SAME trusted OpportunityManager workflow the
 * detail page uses (configureAction → requestApproval → confirmApproval → the queued ExecuteOpportunityAction, which
 * writes through BusinessManager and re-reads the saved value). Each step re-validates tenancy, the Opportunity's
 * current state, the registry parameter rules (50–5,000) and approval authority on the locked row. Success is
 * reported only once the Opportunity itself is Completed (verified); otherwise the genuine pending state is returned.
 */
class HomeDescriptionController extends Controller
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly CustomerShellComposer $shell,
        private readonly OpportunityManager $opportunities,
        private readonly OpportunityActionExecutionRepository $executions,
        private readonly DescriptionImprover $improver,
    ) {
    }

    public function improve(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        [$workspace, $business] = $this->authorized($workspaceUid, $businessUid);
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:' . DescriptionProposal::MAX],
            'request_id' => ['required', 'string', 'max:64'],
        ]);

        $result = $this->improver->improve($workspace, $business, (int) Auth::id(), (string) ($data['text'] ?? ''), (string) $data['request_id']);

        return response()->json($result, $result['ok'] ? 200 : 422)->header('Cache-Control', 'no-store');
    }

    public function approve(Request $request, string $workspaceUid, string $businessUid, string $opportunityUid): JsonResponse
    {
        [, $business] = $this->authorized($workspaceUid, $businessUid);
        $data = $request->validate([
            'value' => ['required', 'string', 'max:' . DescriptionProposal::MAX],
            // Hash of the description the customer saw; a newer saved description is never overwritten silently.
            'base' => ['present', 'nullable', 'string', 'size:64'],
        ]);
        $value = trim($data['value']);

        if (! DescriptionProposal::valid($value)) {
            return response()->json(['state' => 'invalid', 'message' => 'Write a description of at least ' . DescriptionProposal::MIN . ' characters.'], 422);
        }

        $opportunity = Opportunity::query()->where('business_id', $business->id)->where('uid', $opportunityUid)->first();

        if ($opportunity === null || ($opportunity->recommended_action['action_key'] ?? null) !== 'add_description') {
            abort(404);
        }

        $customer = Auth::user()->customer;
        abort_if($customer === null, 403);

        // One approval at a time per Opportunity (a double click or a second tab waits, never duplicates).
        $lock = Cache::lock('home-description-approve:' . $opportunity->id, 15);

        if (! $lock->get()) {
            return response()->json(['state' => 'busy', 'message' => 'Saving is already in progress.'], 409);
        }

        try {
            return $this->run($business->fresh(), $opportunity, $customer, $value, $data['base']);
        } finally {
            $lock->release();
        }
    }

    private function run(Business $business, Opportunity $opportunity, $customer, string $value, ?string $base): JsonResponse
    {
        // State is read AFTER the per-Opportunity lock is held, so a concurrent request's outcome is what is judged here.
        $opportunity = $opportunity->fresh();
        $status = $opportunity?->status;

        // Completed (already verified by the executor) and InProgress (the genuine pending state) never trigger another step.
        if ($status === OpportunityStatus::Completed || $status === OpportunityStatus::InProgress) {
            return $this->state($opportunity);
        }

        if (! in_array($status, [OpportunityStatus::Open, OpportunityStatus::AwaitingApproval], true)) {
            return response()->json(['state' => 'unavailable', 'message' => "This recommendation can't be approved right now."], 409);
        }

        // Stale: the saved description changed since this proposal was shown.
        if (hash('sha256', (string) $business->description) !== (string) $base) {
            return response()->json(['state' => 'stale', 'message' => 'The saved description has changed. Refresh to review the current one.'], 409);
        }

        try {
            if ($status === OpportunityStatus::Open) {
                // Configure and request approval under ONE row lock, and prove the persisted parameter is exactly the submitted
                // value before approval is requested. Another tab's configuration change cannot slip in between: it waits on
                // the lock, or (if it already won) is detected here and nothing is approved.
                $opportunity = DB::transaction(function () use ($opportunity, $business, $customer, $value) {
                    $locked = Opportunity::query()->whereKey($opportunity->id)->where('business_id', $business->id)->lockForUpdate()->first();

                    if ($locked === null || $locked->status !== OpportunityStatus::Open) {
                        return null;
                    }

                    $configured = $this->opportunities->configureAction($locked, $customer, ['value' => $value])->fresh();

                    if (($configured->recommended_action['parameters']['value'] ?? null) !== $value) {
                        return null;
                    }

                    return $this->opportunities->requestApproval($configured, $customer);
                });

                if ($opportunity === null) {
                    return response()->json(['state' => 'changed', 'message' => 'This recommendation was changed elsewhere. Refresh to review it.'], 409);
                }
            }

            // Whatever path led here, only the exact value the customer submitted may be confirmed (configuring is only
            // possible while Open, so once approval is requested the value is fixed).
            if (($opportunity->fresh()->recommended_action['parameters']['value'] ?? null) !== $value) {
                return response()->json(['state' => 'mismatch', 'message' => 'A different description is already awaiting approval. Refresh to review it.'], 409);
            }

            // The execution durably records what the customer saw; the worker refuses to write over a newer description.
            $this->opportunities->confirmApproval($opportunity->fresh(), $customer, expectedStateHash: (string) $base);
        } catch (\App\Library\Opportunity\Exceptions\InvalidOpportunityActionParametersException) {
            return response()->json(['state' => 'invalid', 'message' => 'That description is not valid.'], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['state' => 'unavailable', 'message' => "This recommendation can't be approved right now."], 409);
        }

        return $this->state($opportunity->fresh());
    }

    private function state(Opportunity $opportunity): JsonResponse
    {
        $execution = $this->executions->findLatestForOpportunity($opportunity->id);
        $state = match (true) {
            $opportunity->status === OpportunityStatus::Completed => 'completed',
            $execution?->status === OpportunityActionExecutionStatus::Failed => 'failed',
            default => 'pending',
        };

        return response()->json([
            'state' => $state,
            'message' => match ($state) {
                'completed' => 'Saved and verified in your MotionGrove business profile.',
                'failed' => 'We could not save the description. Nothing was changed.',
                default => 'Saving your description…',
            },
            'statusUrl' => route('customer.opportunities.execution-status', $opportunity->id),
        ])->header('Cache-Control', 'no-store');
    }

    /** @return array{0: \App\Models\Workspace, 1: Business} */
    private function authorized(string $workspaceUid, string $businessUid): array
    {
        abort_unless(config('opportunity.enabled', false), 404);
        [$workspace, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);

        /** @var User $user */
        $user = Auth::user();
        $context = $this->shell->currentContext($user);

        abort_unless($context->isBusinessFrame()
            && $context->selectedBusiness?->uid === $businessUid
            && $context->frameWorkspace()?->uid === $workspaceUid, 404);
        // Acting on a client's Business while viewing as them, or without the advisor ability, is never allowed here.
        abort_if($context->isViewingAsClient(), 403);
        abort_unless(Gate::forUser($user)->allows('business_advisor'), 403);

        return [$workspace, $business];
    }
}
