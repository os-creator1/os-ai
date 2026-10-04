<?php

namespace App\Library\AgencyOutreach;

use App\Exceptions\Usage\AgencyRebillRelationshipInvalidException;
use App\Library\Messaging\ManagedTransportBilling;
use App\Library\Money\CurrencyExponent;
use App\Library\Opportunity\ActionCostEstimator;
use App\Library\Usage\EffectivePayerResolver;
use App\Library\Usage\UsageBillingPresenter;
use App\Library\Workspace\WorkspaceManager;
use App\Models\AgencyProspectCampaignMember;
use App\Models\Business;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * THE Outreach checklist (contract section 12). Pure reads over canonical
 * seams: the Agency's own Business (AgencyOutreachBusinessResolver), its
 * messaging situation (MessagingReadinessReader), its wallet and payer
 * (UsageBillingPresenter / EffectivePayerResolver) and its script
 * (OutreachScript). Nothing here recomputes wallet math or mutates anything.
 */
class AgencyOutreachReadiness
{
    private const CALENDAR_TOKEN = '{{agency.calendar_link}}';

    public function __construct(
        private readonly AgencyOutreachBusinessResolver $businesses,
        private readonly MessagingReadinessReader $messaging,
        private readonly UsageBillingPresenter $billing,
        private readonly EffectivePayerResolver $payers,
        private readonly ManagedTransportBilling $transport,
        private readonly ActionCostEstimator $costs,
        private readonly WorkspaceManager $workspaceManager,
        private readonly BusinessUsageWalletRepository $wallets,
    ) {
    }

    public function forWorkspace(Workspace $workspace): OutreachReadiness
    {
        $business = $this->businesses->forWorkspace($workspace);
        $items = [];
        $info = ['business' => $business, 'number' => null, 'balance' => null, 'currency' => null, 'auto_recharge' => null, 'cost_per_message' => null, 'metered' => false];

        $items[] = $this->scriptItem($workspace, $business);

        if ($business === null) {
            $items[] = $this->item('business', 'Your own business account', false, 'Outreach sends from your agency\'s own business account, and none could be found. An agency needs exactly one active business of its own.');
            foreach ([['number', 'Sending number'], ['verification', 'Messaging verification'], ['wallet', 'Funds for sending']] as [$key, $label]) {
                $items[] = $this->item($key, $label, false, 'Needs your own business account first.');
            }
        } else {
            $items[] = $this->item('business', 'Your own business account', true, null);

            $situation = $this->messaging->situation($business);
            $info['number'] = $situation['phoneNumber'];
            $textingUrl = $this->linkFor($workspace, $business, 'customer.workspaces.businesses.text-messaging.show');

            $items[] = $situation['phoneNumber'] !== null
                ? $this->item('number', 'Sending number', true, null, $textingUrl)
                : $this->item('number', 'Sending number', false, 'You do not have a sending number yet. Get one in Text messaging.', $textingUrl);

            $items[] = $situation['state'] === 'ready'
                ? $this->item('verification', 'Messaging verification', true, null, $textingUrl)
                : $this->item('verification', 'Messaging verification', false, match ($situation['state']) {
                    'no_number' => 'Messaging verification cannot start until you have a sending number.',
                    'number_required' => 'Verification is approved. Get a sending number to finish setup.',
                    default => 'Messaging verification is not complete yet. Finish it in Text messaging before sending.',
                }, $textingUrl);

            $items[] = $this->walletItem($workspace, $business, $info);
        }

        $enrolled = AgencyProspectCampaignMember::where('workspace_id', $workspace->id)->exists();
        $items[] = $enrolled
            ? $this->item('prospects', 'Prospects added', true, null)
            : $this->item('prospects', 'Prospects added', false, 'Add at least one prospect and put them in a campaign.');

        return new OutreachReadiness($items, $info);
    }

    private function scriptItem(Workspace $workspace, ?Business $business): array
    {
        $script = OutreachScript::forWorkspace($workspace);
        $url = route('customer.workspaces.prospecting.script.show', $workspace->uid);

        foreach (['message_1' => 'Message 1', 'message_2' => 'Message 2', 'message_3' => 'Message 3'] as $field => $label) {
            if (trim((string) $script->get($field)) === '') {
                return $this->item('script', 'Script', false, "{$label} is empty. Write it in Script & Settings.", $url);
            }
        }

        if (trim((string) $script->agencyName()) === '') {
            return $this->item('script', 'Script', false, 'Add your agency name in Script & Settings.', $url);
        }

        if ($script->calendarUrl() === null || trim((string) $script->calendarUrl()) === '') {
            return $this->item('script', 'Script', false, 'Add a calendar link (a valid http or https address) in Script & Settings.', $url);
        }

        if (! str_contains((string) $script->get('message_3'), self::CALENDAR_TOKEN)) {
            return $this->item('script', 'Script', false, 'Message 3 must include your calendar link so prospects can book.', $url);
        }

        return $this->item('script', 'Script', true, null, $url);
    }

    /** @param array<string, mixed> $info */
    private function walletItem(Workspace $workspace, Business $business, array &$info): array
    {
        $url = $this->linkFor($workspace, $business, 'customer.workspaces.businesses.usage-billing.show');
        $label = 'Funds for sending';

        try {
            $payer = $this->payers->resolve($business);
            $refusal = $this->payers->paidEffectRefusal($business, $payer);
        } catch (AgencyRebillRelationshipInvalidException) {
            return $this->item('wallet', $label, false, 'Usage is billed to a payer that can no longer be used. Review Usage & billing.', $url);
        }

        if ($refusal !== null) {
            return $this->item('wallet', $label, false, 'Paid activity is not allowed for this account right now. Review Usage & billing.', $url);
        }

        $view = $this->billing->buildDashboardViewModel($business);
        $wallet = $view->wallet;
        $info['auto_recharge'] = (bool) ($view->autoRecharge['enabled'] ?? false);

        if ($wallet === null) {
            return $this->item('wallet', $label, false, 'There is no wallet for this account yet. Open Usage & billing to add funds.', $url);
        }

        $info['currency'] = $wallet['currency_code'];
        $info['balance'] = $this->money($wallet['available_balance_micro'], $wallet['currency_code']);

        if (($view->business['billing_status'] ?? 'active') === 'suspended') {
            return $this->item('wallet', $label, false, 'Billing is paused on this account. Review Usage & billing.', $url);
        }

        if ($this->wallets->findByBusinessId((int) $business->id)?->paid_activity_paused_at !== null) {
            return $this->item('wallet', $label, false, 'Paid activity is paused on this account. Review Usage & billing.', $url);
        }

        if ((int) $wallet['debt_balance_micro'] > 0) {
            return $this->item('wallet', $label, false, 'There is an unpaid balance on this account. Clear it in Usage & billing.', $url);
        }

        // Cost is shown only when the platform has activated a real rate.
        $metered = $this->transport->isMetered();
        $info['metered'] = $metered;
        $estimate = null;

        if ($metered) {
            try {
                $estimate = $this->costs->estimate($business, 'messaging_transport', '1');
            } catch (Throwable) {
                $estimate = null;
            }

            if ($estimate !== null && $estimate->currencyCode !== null && $estimate->amountMinorUpperBound !== null) {
                $exponent = CurrencyExponent::for($estimate->currencyCode);
                $major = $exponent > 0
                    ? bcdiv((string) $estimate->amountMinorUpperBound, (string) (10 ** $exponent), max(2, $exponent))
                    : (string) $estimate->amountMinorUpperBound;
                $info['cost_per_message'] = $estimate->currencyCode . ' ' . $major;
            }

            $sufficient = $estimate !== null ? $estimate->walletSufficient : (int) $wallet['available_balance_micro'] > 0;

            if (! $sufficient) {
                return $this->item('wallet', $label, false, 'Your balance is too low to send. Add funds in Usage & billing.', $url);
            }
        }

        return $this->item('wallet', $label, true, null, $url);
    }

    private function money(string $micro, string $currency): string
    {
        $negative = str_starts_with($micro, '-');

        return ($negative ? '-' : '') . ($currency !== '' ? $currency . ' ' : '') . bcdiv(ltrim($micro, '-'), '1000000', 2);
    }

    /** A link only when the viewer may actually open that Business page. */
    private function linkFor(Workspace $workspace, Business $business, string $route): ?string
    {
        $userId = (int) Auth::id();

        if ($userId === 0 || ! $this->workspaceManager->userCanAccessBusiness($userId, $business)) {
            return null;
        }

        return route($route, [$workspace->uid, $business->uid]);
    }

    private function item(string $key, string $label, bool $ok, ?string $reason, ?string $url = null): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'reason' => $reason, 'url' => $url];
    }
}
