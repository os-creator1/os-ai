<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\BusinessEmail\BusinessEmailContactResolver;
use App\Library\Automation\Workflow\Triggers\TriggerCause;
use App\Library\Documents\DocumentManager;
use App\Models\AutomationEnrollment;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Automations — creates a document THROUGH the Documents domain's own manager and
 * sends it, for the two actions that do (Create & send proposal; Request payment for
 * a new invoice).
 *
 * NOTHING IS HAND-BUILT. A document is DocumentManager::create(), its lines are
 * DocumentManager::addCatalogLine() / addCustomLine() (which take the catalog's
 * frozen price snapshot and recompute the version total), its payment schedule is
 * DocumentManager::setSchedule(), its delivery identity is DocumentManager::edit(),
 * and the send is DocumentManager::send() — which freezes the issued version,
 * mints the secure link, emails it after commit and emits DocumentSent. No raw model
 * write, no HTML, no line items of this class's own.
 *
 * THE DOCUMENT'S LOCATION is the journey's PINNED Location. With none pinned, the
 * Business's single active Location; with several, the step stops
 * (`document_location_unresolved`) rather than infer one from where the Contact lives
 * today. The deal it is linked to is the one the triggering fact names, when that deal
 * is this Contact's and not another Location's; otherwise none.
 *
 * EVERYTHING THAT CAN BE REFUSED IS PROVED BEFORE ANYTHING IS CREATED — the Location,
 * the catalog item, the recipient's one valid address — so a refusal leaves no stray
 * draft behind. DocumentManager's own checks (a total, a schedule that sums, a valid
 * recipient) still run and still win.
 *
 * AT MOST ONCE. The caller is an External step the engine claims once per (enrollment,
 * node) and never re-runs; a duplicate delivery of the job finds the step already
 * recorded and creates and sends nothing.
 *
 * Delivery is the Documents domain's: an EMAIL carrying the secure link. The link's
 * token exists only inside that email — DocumentManager never returns it — so a text
 * of it is not possible, and none is attempted.
 */
class AutomationDocumentFactory
{
    public function __construct(
        private readonly DocumentManager $documents,
        private readonly BusinessEmailContactResolver $emails,
        private readonly TriggerFactResolver $facts,
    ) {
    }

    /**
     * @param array{catalog_item_id?: int|null, quantity?: int, amount_minor?: int|null} $line
     * @param 'full'|'deposit' $schedule
     */
    public function createAndSend(
        string $kind,
        string $title,
        array $line,
        string $schedule,
        ?int $depositPercent,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
        ?int $stepRunId,
    ): BusinessDocument|NodeExecutionOutcome {
        $location = $this->location($enrollment, $business);

        if ($location === null) {
            return NodeExecutionOutcome::failed('document_location_unresolved');
        }

        $address = $this->emails->singleAddressFor($contact);

        if ($address === null) {
            return NodeExecutionOutcome::skipped('contact_email_unavailable');
        }

        // The Business's owner: businesses.customer_id is that account's USER id.
        $actor = User::query()->find((int) $business->customer_id);

        if ($actor === null) {
            return NodeExecutionOutcome::failed('document_actor_unavailable');
        }

        $item = null;

        if (($line['catalog_item_id'] ?? null) !== null) {
            $item = CatalogItem::query()
                ->where('business_id', (int) $business->id)
                ->where('lifecycle_state', 'active')
                ->whereNull('archived_at')
                ->find((int) $line['catalog_item_id']);

            if ($item === null) {
                return NodeExecutionOutcome::failed('catalog_item_unavailable');
            }
        }

        $opportunity = $this->opportunity($enrollment, $business, $contact, $location);

        try {
            $document = $this->documents->create($business, $location, $contact, $opportunity, $kind, $title, $actor);

            if ($item !== null) {
                $this->documents->addCatalogLine($document, $item, max(1, (int) ($line['quantity'] ?? 1)), $actor);
            } else {
                $this->documents->addCustomLine($document, $title, null, 1, (int) ($line['amount_minor'] ?? 0));
            }

            $total = (int) $document->versions()->where('state', 'draft')->value('total_minor');
            $this->documents->setSchedule($document, $this->terms($document, $total, $schedule, $depositPercent));

            $name = trim((string) $contact->getFullName(''));
            $this->documents->edit($document, [
                'recipient_email_snapshot' => $address,
                'recipient_name_snapshot' => $name === '' ? null : mb_substr($name, 0, 191),
            ]);

            $this->documents->send($document, origin: $stepRunId === null ? null : TriggerCause::originFor($stepRunId));
        } catch (ValidationException $exception) {
            // The Documents domain refused (no resolvable total, a schedule that does
            // not sum, ...). Its message is its own bounded, PII-free sentence.
            $message = (string) (collect($exception->errors())->flatten()->first() ?? 'refused');

            return NodeExecutionOutcome::failed('document_refused: ' . mb_substr($message, 0, 200));
        } catch (Throwable $exception) {
            return NodeExecutionOutcome::failed('document_exception: ' . class_basename($exception));
        }

        return $document->refresh();
    }

    /** The pinned Location, else the Business's one active Location, else null. */
    private function location(AutomationEnrollment $enrollment, Business $business): ?BusinessLocation
    {
        $pinned = PinnedRunLocation::pinned($enrollment);
        $active = BusinessLocation::query()
            ->where('business_id', (int) $business->id)
            ->where('lifecycle_state', \App\Enums\Business\BusinessLocationLifecycleState::Active->value)
            ->orderBy('id')
            ->get();

        if ($pinned !== null) {
            return $active->first(fn (BusinessLocation $location): bool => (int) $location->id === $pinned);
        }

        return $active->count() === 1 ? $active->first() : null;
    }

    /** The deal the fact names, when it is this Contact's and not another Location's. */
    private function opportunity(AutomationEnrollment $enrollment, Business $business, Contacts $contact, BusinessLocation $location): ?CrmOpportunity
    {
        $id = $this->facts->forEnrollment($enrollment)->opportunityId;

        if ($id === null) {
            return null;
        }

        $deal = CrmOpportunity::query()
            ->where('business_id', (int) $business->id)
            ->where('contact_id', (int) $contact->id)
            ->find($id);

        if ($deal === null || ($deal->location_id !== null && (int) $deal->location_id !== (int) $location->id)) {
            return null;
        }

        return $deal;
    }

    /**
     * The payment schedule the document may carry: the whole amount, or a deposit then
     * the balance, summing exactly to the total.
     *
     * @return list<array{kind: string, amount_minor: int, currency_code: string}>
     */
    private function terms(BusinessDocument $document, int $total, string $schedule, ?int $depositPercent): array
    {
        $currency = (string) $document->currency_code;

        if ($schedule !== 'deposit') {
            return [['kind' => 'full', 'amount_minor' => $total, 'currency_code' => $currency]];
        }

        $deposit = max(1, min($total - 1, intdiv($total * (int) $depositPercent, 100)));

        return [
            ['kind' => 'deposit', 'amount_minor' => $deposit, 'currency_code' => $currency],
            ['kind' => 'balance', 'amount_minor' => $total - $deposit, 'currency_code' => $currency],
        ];
    }
}
