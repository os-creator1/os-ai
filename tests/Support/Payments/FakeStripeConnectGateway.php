<?php

namespace Tests\Support\Payments;

use App\Exceptions\Payments\StripeConnectException;
use App\Library\Payments\ConnectedAccountSnapshot;
use App\Library\Payments\StripeConnectGateway;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TEST-ONLY lane-B gateway. No network anywhere in this slice's suite.
 *
 * It also POLICES §7 for us: every method asserts DB::transactionLevel() is
 * at the test's own baseline, so a provider call made inside a transaction or
 * while a row lock is held fails loudly instead of silently holding a lock
 * across a network round trip.
 */
class FakeStripeConnectGateway implements StripeConnectGateway
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public int $accountSequence = 0;

    /** Transaction depth the test itself runs at (RefreshDatabase opens one). */
    public int $baselineTransactionLevel = 0;

    public bool $chargesEnabled = false;

    public bool $payoutsEnabled = false;

    public bool $detailsSubmitted = false;

    public ?string $disabledReason = null;

    public ?string $defaultCurrency = 'USD';

    public ?StripeConnectException $failWith = null;

    /**
     * Invoked DURING retrieveAccount(), i.e. in the exact window where the
     * provider call is in flight and another process could still change the
     * row. That window is the only place optimistic-lock staleness can really
     * arise, so a test simulates a concurrent writer here rather than by
     * pretending a caller held a stale version it could never have held.
     *
     * @var null|callable():void
     */
    public $duringRetrieve = null;

    public function createAccount(string $country, ?string $email, string $businessUid): ConnectedAccountSnapshot
    {
        $this->record('createAccount', ['country' => $country, 'business_uid' => $businessUid]);

        return $this->snapshot('acct_fake' . str_pad((string) (++$this->accountSequence), 6, '0', STR_PAD_LEFT));
    }

    public function createOnboardingLink(string $stripeAccountId, string $refreshUrl, string $returnUrl): string
    {
        $this->record('createOnboardingLink', [
            'account' => $stripeAccountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
        ]);

        return 'https://connect.stripe.test/setup/' . $stripeAccountId;
    }

    public function retrieveAccount(string $stripeAccountId): ConnectedAccountSnapshot
    {
        $this->record('retrieveAccount', ['account' => $stripeAccountId]);

        if ($this->duringRetrieve !== null) {
            ($this->duringRetrieve)();
        }

        return $this->snapshot($stripeAccountId);
    }

    /** Every account id this fake was ever asked about. */
    public function accountsTouched(): array
    {
        return array_values(array_filter(array_map(fn (array $call) => $call['args']['account'] ?? null, $this->calls)));
    }

    public function callNames(): array
    {
        return array_map(fn (array $call) => $call['method'], $this->calls);
    }

    private function snapshot(string $accountId): ConnectedAccountSnapshot
    {
        return new ConnectedAccountSnapshot(
            stripeAccountId: $accountId,
            chargesEnabled: $this->chargesEnabled,
            payoutsEnabled: $this->payoutsEnabled,
            detailsSubmitted: $this->detailsSubmitted,
            requirementsDisabledReason: $this->disabledReason,
            defaultCurrency: $this->defaultCurrency,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function record(string $method, array $args): void
    {
        $level = DB::transactionLevel();

        if ($level > $this->baselineTransactionLevel) {
            throw new RuntimeException(
                "Contract 17 §7 violated: {$method}() was called at DB::transactionLevel() {$level}, "
                . "above the baseline {$this->baselineTransactionLevel}. No provider call may happen inside a "
                . 'transaction or while a row lock is held.'
            );
        }

        $this->calls[] = ['method' => $method, 'args' => $args, 'transaction_level' => $level];

        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }
}
