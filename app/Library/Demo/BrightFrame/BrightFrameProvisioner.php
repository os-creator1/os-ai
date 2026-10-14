<?php

namespace App\Library\Demo\BrightFrame;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds (or tops up) the BrightFrame Photo Booths sales-demo tenant.
 *
 * Idempotent: every stage finds-or-creates by a stable natural key, so running
 * it twice produces the same rows. Additive only: it never deletes or edits a
 * row it did not create, and never touches any other Business.
 *
 * Stage order matters: later stages reference contacts, services, forms and
 * the website the earlier ones create.
 */
final class BrightFrameProvisioner
{
    /** @var array<int, class-string<DemoStage>> */
    private const STAGES = [
        ServicesStage::class,
        ContactsStage::class,
        CrmStage::class,
        ConversationsStage::class,
        CalendarStage::class,
        FormsStage::class,
        AutomationsStage::class,
        WebsiteStage::class,
        GoogleAndAdsStage::class,
        SeoStage::class,
        PaymentsStage::class,
    ];

    public function __construct(
        private readonly AccountStage $account,
        private readonly DemoSafetyAudit $audit,
    ) {
    }

    /**
     * @return array{context: DemoContext, password_set: bool}
     */
    public function provision(string $loginEmail, ?string $password, bool $rotatePassword): array
    {
        [$user, $customer, $workspace, $business, $location, $adminId, $passwordSet] = $this->account->run($loginEmail, $password, $rotatePassword);

        $context = new DemoContext($user, $customer, $workspace, $business, $location, $adminId, CarbonImmutable::now());

        foreach ($this->stages() as $stage) {
            DB::transaction(fn () => $stage->run($context));
        }

        $violations = $this->audit->violations($context);

        if ($violations !== []) {
            throw new \RuntimeException('Demo safety audit FAILED: ' . json_encode($violations));
        }

        $context->note('safety_audit', 'clear: no sender, sending server, email account, provider token, live Stripe or customer notification');

        return ['context' => $context, 'password_set' => $passwordSet];
    }

    /** @return array<int, DemoStage> */
    private function stages(): array
    {
        return array_map(fn (string $class): DemoStage => app($class), static::STAGES);
    }
}
