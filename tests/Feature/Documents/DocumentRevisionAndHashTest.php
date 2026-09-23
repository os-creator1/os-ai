<?php

namespace Tests\Feature\Documents;

use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\DocumentVersionState;
use App\Library\Documents\DocumentContentHasher;
use App\Library\Documents\DocumentManager;
use App\Library\Support\CanonicalJson;
use App\Models\BusinessDocumentLineItem;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessDocumentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §5.3.1 / §5.3.2 / §7.1 — revising a sent
 * document, superseding the old version, and the canonical content hash a
 * signature is bound to.
 */
class DocumentRevisionAndHashTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    // -----------------------------------------------------------------
    // Revision copies; it never mutates
    // -----------------------------------------------------------------

    public function test_revising_a_sent_document_creates_version_two_copying_lines_and_schedule_terms(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $first = BusinessDocumentVersion::findOrFail($document->current_version_id);
        $firstLineIds = $first->lineItems()->pluck('id')->all();
        $firstScheduleIds = $first->paymentScheduleItems()->pluck('id')->all();

        $second = app(DocumentManager::class)->revise($document, $tenant['customer']->user);

        $this->assertSame(2, (int) $second->version_number);
        $this->assertSame(DocumentVersionState::Draft, $second->state);
        $this->assertSame((int) $first->total_minor, (int) $second->total_minor);

        // NEW rows, not moved rows.
        $this->assertSame($firstLineIds, $first->lineItems()->pluck('id')->all());
        $this->assertSame($firstScheduleIds, $first->paymentScheduleItems()->pluck('id')->all());
        $this->assertSame([], array_intersect($firstLineIds, $second->lineItems()->pluck('id')->all()));
        $this->assertSame([], array_intersect($firstScheduleIds, $second->paymentScheduleItems()->pluck('id')->all()));

        // ...carrying the same commercial values.
        $this->assertSame(
            $first->lineItems()->orderBy('position')->get()->map(fn ($l) => [$l->name, $l->quantity, $l->unit_price_minor, $l->line_total_minor])->all(),
            $second->lineItems()->orderBy('position')->get()->map(fn ($l) => [$l->name, $l->quantity, $l->unit_price_minor, $l->line_total_minor])->all(),
        );
        $this->assertSame(
            $first->paymentScheduleItems()->orderBy('sequence')->get()->map(fn ($s) => [$s->sequence, $s->kind->value, $s->amount_minor, $s->currency_code])->all(),
            $second->paymentScheduleItems()->orderBy('sequence')->get()->map(fn ($s) => [$s->sequence, $s->kind->value, $s->amount_minor, $s->currency_code])->all(),
        );
    }

    public function test_schedule_progress_is_never_carried_into_the_new_version(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $first = BusinessDocumentVersion::findOrFail($document->current_version_id);
        DB::table('business_document_payment_schedule_items')
            ->where('business_document_version_id', $first->id)
            ->update(['status' => 'paid', 'paid_at' => now(), 'reminder_count' => 2]);

        $second = app(DocumentManager::class)->revise($document, $tenant['customer']->user);

        foreach ($second->paymentScheduleItems as $item) {
            $this->assertSame('pending', $item->status->value, 'Progress belongs to the version that earned it.');
            $this->assertNull($item->paid_at);
            $this->assertSame(0, (int) $item->reminder_count);
        }
    }

    public function test_the_existing_link_keeps_working_until_the_new_version_is_sent(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        app(DocumentManager::class)->revise($document, $tenant['customer']->user);

        $this->get($this->publicUrl($document->refresh(), $token))->assertOk();
        $this->assertSame(DocumentStatus::Sent, $document->refresh()->status);
    }

    public function test_sending_the_revision_supersedes_the_old_version_without_touching_its_terms(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $first = BusinessDocumentVersion::findOrFail($document->current_version_id);
        $frozen = [
            'content_hash' => $first->content_hash,
            'total' => (int) $first->total_minor,
            'lines' => $first->lineItems()->orderBy('position')->get()->map(fn ($l) => $l->only(['name', 'quantity', 'unit_price_minor', 'line_total_minor']))->all(),
            'schedule' => $first->paymentScheduleItems()->orderBy('sequence')->get()->map(fn ($s) => $s->only(['sequence', 'amount_minor', 'currency_code']))->all(),
        ];

        $second = app(DocumentManager::class)->revise($document, $tenant['customer']->user);
        app(DocumentManager::class)->addCustomLine($document->refresh(), 'Extra work', null, 1, 10000);
        app(DocumentManager::class)->setSchedule($document->refresh(), [['kind' => 'full', 'amount_minor' => 60000, 'currency_code' => 'USD']]);
        [$document] = $this->sendAndCaptureToken($document->refresh());

        $first->refresh();
        $second->refresh();

        $this->assertSame(DocumentVersionState::Superseded, $first->state);
        $this->assertNotNull($first->superseded_at);
        $this->assertSame(DocumentVersionState::Issued, $second->state);
        $this->assertSame((int) $second->id, (int) $document->current_version_id);

        // The old version's commercial content is byte-for-byte what it was.
        $this->assertSame($frozen['content_hash'], $first->content_hash);
        $this->assertSame($frozen['total'], (int) $first->total_minor);
        $this->assertEquals($frozen['lines'], $first->lineItems()->orderBy('position')->get()->map(fn ($l) => $l->only(['name', 'quantity', 'unit_price_minor', 'line_total_minor']))->all());
        $this->assertEquals($frozen['schedule'], $first->paymentScheduleItems()->orderBy('sequence')->get()->map(fn ($s) => $s->only(['sequence', 'amount_minor', 'currency_code']))->all());
        $this->assertNotSame($first->content_hash, $second->content_hash);
    }

    public function test_the_superseded_versions_pending_schedule_rows_are_no_longer_the_payable_ones(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $first = BusinessDocumentVersion::findOrFail($document->current_version_id);

        $second = app(DocumentManager::class)->revise($document, $tenant['customer']->user);
        app(DocumentManager::class)->setSchedule($document->refresh(), [['kind' => 'full', 'amount_minor' => 50000, 'currency_code' => 'USD']]);
        [$document, $token] = $this->sendAndCaptureToken($document->refresh());

        // The old rows still exist as history and are still `pending` — but
        // §7.3 resolves payability ONLY against current_version_id, which is
        // now the new version, so they can never be paid.
        $oldPending = $first->paymentScheduleItems()->where('status', 'pending')->pluck('id')->all();
        $this->assertNotEmpty($oldPending);
        $this->assertSame([], array_intersect($oldPending, $second->refresh()->paymentScheduleItems()->pluck('id')->all()));
        $this->assertSame((int) $second->id, (int) $document->current_version_id);

        // And the public page renders the CURRENT version's schedule only.
        $html = $this->get($this->publicUrl($document, $token))->assertOk()->getContent();
        $this->assertSame(
            $second->paymentScheduleItems()->count(),
            substr_count($html, 'data-role="schedule-item"'),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unrevisableStatuses(): array
    {
        return ['draft' => ['draft'], 'signed' => ['signed'], 'paid' => ['paid'], 'expired' => ['expired'], 'void' => ['void']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unrevisableStatuses')]
    public function test_only_a_sent_document_can_be_revised(string $status): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        DB::table('business_documents')->where('id', $document->id)->update(['status' => $status]);

        $this->expectException(ValidationException::class);
        app(DocumentManager::class)->revise($document->refresh(), $tenant['customer']->user);
    }

    public function test_a_signed_document_is_refused_even_if_its_status_were_forced_back(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        app(DocumentManager::class)->sign($document, [
            'signer_name' => 'Pat', 'signer_email' => 'p@example.test', 'typed_name' => 'Pat',
            'ip_address' => '127.0.0.1', 'user_agent' => null,
        ]);
        DB::table('business_documents')->where('id', $document->id)->update(['status' => 'sent']);

        try {
            app(DocumentManager::class)->revise($document->refresh(), $tenant['customer']->user);
            $this->fail('A signed agreement is renegotiated by voiding, never by superseding what was signed.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('signed', implode(' ', $e->errors()['document']));
        }
    }

    // -----------------------------------------------------------------
    // §5.3.1 — an issued version is not authored again
    // -----------------------------------------------------------------

    public function test_no_authoring_path_can_touch_a_document_once_every_version_is_issued(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $manager = app(DocumentManager::class);
        $version = BusinessDocumentVersion::findOrFail($document->current_version_id);
        $before = $this->fingerprint(['business_document_versions', 'business_document_line_items', 'business_document_payment_schedule_items']);

        foreach ([
            fn () => $manager->edit($document, ['content' => ['tampered' => true]]),
            fn () => $manager->addCustomLine($document, 'Sneaky', null, 1, 1),
            fn () => $manager->setSchedule($document, [['kind' => 'full', 'amount_minor' => 1, 'currency_code' => 'USD']]),
            fn () => $manager->removeLine($document, $version->lineItems()->first()),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An issued version must not be authorable — there is no open draft.');
            } catch (ValidationException) {
                // expected: "No open draft version."
            }
        }

        $this->assertSame($before, $this->fingerprint(['business_document_versions', 'business_document_line_items', 'business_document_payment_schedule_items']));
    }

    /**
     * The §13 immutability proof, at the level this sub-slice owns: whatever
     * happens to prices afterwards, the issued line carries its own
     * denormalized copy, so the hash the signature is bound to cannot move.
     * (The catalog-specific path is exercised by Sub-slice B's own suite,
     * which owns catalog lines and package snapshots.)
     */
    public function test_a_later_price_change_cannot_alter_the_signed_content_hash(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $signature = app(DocumentManager::class)->sign($document, [
            'signer_name' => 'Pat', 'signer_email' => 'p@example.test', 'typed_name' => 'Pat',
            'ip_address' => '127.0.0.1', 'user_agent' => null,
        ]);
        $version = BusinessDocumentVersion::findOrFail($document->refresh()->current_version_id);

        $signedLine = $version->lineItems()->orderBy('position')->first();
        $this->assertSame(25000, (int) $signedLine->unit_price_minor);

        // The Business re-prices its work and issues an entirely new document.
        $later = $this->draftDocument($tenant, ['title' => 'Repriced']);
        app(DocumentManager::class)->removeLine($later, $later->versions()->first()->lineItems()->first());
        app(DocumentManager::class)->addCustomLine($later, 'Design work', 'Initial drawings', 2, 99999);

        // The already-signed version, its line and its hash are untouched.
        $this->assertSame(25000, (int) $signedLine->refresh()->unit_price_minor);
        $this->assertSame($signature->signed_content_hash, $version->refresh()->content_hash);
        $this->assertSame($version->content_hash, app(DocumentContentHasher::class)->hash($version));
    }

    // -----------------------------------------------------------------
    // §5.3.2 — the canonical hash
    // -----------------------------------------------------------------

    public function test_key_order_in_content_does_not_change_the_hash(): void
    {
        $tenant = $this->sendableTenant();
        $hasher = app(DocumentContentHasher::class);

        $one = $this->draftDocument($tenant, ['title' => 'One']);
        app(DocumentManager::class)->edit($one, ['content' => ['b' => 1, 'a' => ['y' => 2, 'x' => 3]]]);
        $two = $this->draftDocument($tenant, ['title' => 'Two']);
        app(DocumentManager::class)->edit($two, ['content' => ['a' => ['x' => 3, 'y' => 2], 'b' => 1]]);

        $this->assertSame(
            $hasher->hash($one->refresh()->versions()->first()),
            $hasher->hash($two->refresh()->versions()->first()),
        );
    }

    public function test_a_meaningful_line_or_schedule_change_changes_the_hash(): void
    {
        $tenant = $this->sendableTenant();
        $hasher = app(DocumentContentHasher::class);
        $document = $this->draftDocument($tenant);
        $version = $document->versions()->first();
        $baseline = $hasher->hash($version);

        app(DocumentManager::class)->addCustomLine($document, 'Another line', null, 1, 500);
        $this->assertNotSame($baseline, $hasher->hash($version->refresh()));

        $withLine = $hasher->hash($version->refresh());
        app(DocumentManager::class)->setSchedule($document->refresh(), [
            ['kind' => 'deposit', 'amount_minor' => 20000, 'currency_code' => 'USD'],
            ['kind' => 'balance', 'amount_minor' => 30500, 'currency_code' => 'USD'],
        ]);
        $this->assertNotSame($withLine, $hasher->hash($version->refresh()));
    }

    public function test_payment_and_reminder_progress_never_changes_the_hash(): void
    {
        $tenant = $this->sendableTenant();
        $hasher = app(DocumentContentHasher::class);
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $version = BusinessDocumentVersion::findOrFail($document->current_version_id);
        $frozen = $hasher->hash($version);

        DB::table('business_document_payment_schedule_items')
            ->where('business_document_version_id', $version->id)
            ->update([
                'status' => 'paid',
                'paid_at' => now(),
                'reminder_last_sent_at' => now(),
                'reminder_count' => 3,
            ]);
        DB::table('business_document_versions')->where('id', $version->id)->update(['superseded_at' => now()]);

        $this->assertSame($frozen, $hasher->hash($version->refresh()),
            'Payment progress must never change a hash a signature is bound to.');
    }

    public function test_the_canonical_bytes_are_sorted_exclude_progress_and_hash_as_sha256(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $version = BusinessDocumentVersion::findOrFail($document->current_version_id);
        $hasher = app(DocumentContentHasher::class);
        $bytes = $hasher->canonicalBytes($version);

        $this->assertSame(hash('sha256', $bytes), $hasher->hash($version));
        $this->assertSame($bytes, CanonicalJson::encode(json_decode($bytes, true)), 'The bytes must already be canonical.');

        $decoded = json_decode($bytes, true);
        $this->assertSame(['content', 'currency_code', 'line_items', 'schedule_items', 'schema_version', 'subtotal_minor', 'total_minor'], array_keys($decoded));
        $this->assertSame(['amount_minor', 'currency_code', 'due_at', 'kind', 'sequence'], array_keys($decoded['schedule_items'][0]));

        foreach (['status', 'paid_at', 'reminder_count', 'reminder_last_sent_at', 'state', 'superseded_at', 'created_at', 'updated_at', 'id'] as $excluded) {
            $this->assertStringNotContainsString('"' . $excluded . '"', $bytes, "[{$excluded}] is progress or metadata and must be excluded.");
        }
    }

    // -----------------------------------------------------------------
    // Source boundary (§5.3.1)
    // -----------------------------------------------------------------

    public function test_only_the_document_manager_writes_version_commercial_fields(): void
    {
        $offenders = [];

        foreach (['app/Http/Controllers', 'app/Library', 'app/Jobs', 'app/Models', 'app/Events'] as $dir) {
            $base = dirname(__DIR__, 3) . '/' . $dir;

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());

                if (str_ends_with($path, 'app/Library/Documents/DocumentManager.php')) {
                    continue;
                }

                $code = (string) file_get_contents($path);

                if (preg_match('/->(content_hash|subtotal_minor|total_minor|issued_at|superseded_at)\s*=/', $code) === 1) {
                    $offenders[] = $path;
                }
            }
        }

        $this->assertSame([], $offenders, 'Only DocumentManager may write a version\'s commercial or lifecycle fields.');
    }

    public function test_the_authorized_issued_to_superseded_transition_still_works(): void
    {
        // §5.3.1 is explicit that the boundary test must NOT claim the row is
        // physically update-impossible — `state` has to transition.
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $first = BusinessDocumentVersion::findOrFail($document->current_version_id);

        app(DocumentManager::class)->revise($document, $tenant['customer']->user);
        $this->sendAndCaptureToken($document->refresh());

        $this->assertSame(DocumentVersionState::Superseded, $first->refresh()->state);
    }
}
