<?php

namespace Tests\Feature\Documents;

use App\Enums\Documents\DocumentStatus;
use App\Events\DocumentExpired;
use App\Events\DocumentSent;
use App\Events\DocumentSigned;
use App\Events\DocumentVoided;
use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\Support\Documents\ShownVersion;
use Tests\TestCase;

/**
 * Implementation Contract 17 §7.1 / §8.3 / §8.6 — ONE authoritative lifecycle.
 *
 * `DocumentStatus::allowedTransitions()` is the single statement of which
 * status may follow which, and DocumentManager checks every move it makes
 * against it on the locked row. These tests pin the matrix itself, then prove
 * the manager refuses every impossible move it can be asked for.
 */
class DocumentLifecycleStateMachineTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    private function evidence(BusinessDocument $document): array
    {
        return [
            'displayed_version_uid' => ShownVersion::uid($document),
            'signer_name' => 'Pat Rivera', 'signer_email' => 'pat@example.test', 'typed_name' => 'Pat Rivera',
            'ip_address' => '203.0.113.7', 'user_agent' => null,
        ];
    }

    public function test_the_transition_matrix_is_exactly_the_contracted_one(): void
    {
        $matrix = [];
        foreach (DocumentStatus::cases() as $status) {
            $matrix[$status->value] = array_map(fn (DocumentStatus $to) => $to->value, $status->allowedTransitions());
        }

        $this->assertSame([
            'draft' => ['sent', 'void'],
            // sent -> sent is the re-issue of a revised version.
            'sent' => ['sent', 'signed', 'paid', 'expired', 'void'],
            // A signed agreement never expires (§8.6).
            'signed' => ['paid', 'void'],
            'paid' => [],
            'expired' => [],
            'void' => [],
        ], $matrix);
    }

    public function test_terminal_states_never_move_and_nothing_returns_to_draft(): void
    {
        foreach ([DocumentStatus::Paid, DocumentStatus::Expired, DocumentStatus::Void] as $terminal) {
            $this->assertTrue($terminal->isTerminal());
            foreach (DocumentStatus::cases() as $to) {
                $this->assertFalse($terminal->canTransitionTo($to), "{$terminal->value} -> {$to->value}");
            }
        }

        foreach (DocumentStatus::cases() as $from) {
            $this->assertFalse($from->canTransitionTo(DocumentStatus::Draft), "{$from->value} -> draft");
        }

        // No "viewed" state: the public GET is side-effect-free (§6.3).
        $this->assertNull(DocumentStatus::tryFrom('viewed'));
    }

    #[DataProvider('statusesThatCannotBeSent')]
    public function test_send_refuses_every_state_the_map_does_not_allow(string $status): void
    {
        Event::fake([DocumentSent::class]);
        $document = $this->draftDocument($this->sendableTenant());
        DB::table('business_documents')->where('id', $document->id)->update(['status' => $status]);

        try {
            app(DocumentManager::class)->send($document->refresh());
            $this->fail("send() from {$status} must be refused.");
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($status, $document->fresh()->status->value);
        $this->assertNull($document->fresh()->current_version_id);
        Event::assertNotDispatched(DocumentSent::class);
    }

    public static function statusesThatCannotBeSent(): array
    {
        return ['signed' => ['signed'], 'paid' => ['paid'], 'expired' => ['expired'], 'void' => ['void']];
    }

    public function test_a_draft_cannot_be_signed_even_with_a_forged_current_version(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $evidence = $this->evidence($document);
        DB::table('business_documents')->where('id', $document->id)->update(['status' => 'draft']);

        $this->expectException(ValidationException::class);
        try {
            app(DocumentManager::class)->sign($document->refresh(), $evidence);
        } finally {
            $this->assertSame(0, BusinessDocumentSignature::query()->count());
        }
    }

    #[DataProvider('statusesThatCannotBeVoided')]
    public function test_void_is_refused_from_every_terminal_state(string $status): void
    {
        Event::fake([DocumentVoided::class]);
        $document = $this->draftDocument($this->sendableTenant());
        DB::table('business_documents')->where('id', $document->id)->update(['status' => $status]);

        try {
            app(DocumentManager::class)->void($document->refresh(), 'changed my mind');
            $this->fail("void() from {$status} must be refused.");
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($status, $document->fresh()->status->value);
        Event::assertNotDispatched(DocumentVoided::class);
    }

    public static function statusesThatCannotBeVoided(): array
    {
        return ['paid' => ['paid'], 'expired' => ['expired'], 'void' => ['void']];
    }

    public function test_a_signed_document_is_never_expired_and_an_expired_one_is_never_signed(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $evidence = $this->evidence($document);

        // sent -> expired is allowed ...
        DB::table('business_documents')->where('id', $document->id)->update(['expires_at' => now()->subMinute()]);
        Event::fake([DocumentExpired::class, DocumentSigned::class]);

        // ... but an expired offer can no longer be signed.
        try {
            app(DocumentManager::class)->sign($document->refresh(), $evidence);
            $this->fail('An expired offer must not be signable.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(1, app(DocumentManager::class)->expireDue(10));
        $this->assertSame('expired', $document->fresh()->status->value);

        // Once expired it is terminal: it cannot be signed, sent or voided.
        foreach ([
            fn () => app(DocumentManager::class)->sign($document->refresh(), $evidence),
            fn () => app(DocumentManager::class)->send($document->refresh()),
            fn () => app(DocumentManager::class)->void($document->refresh(), 'x'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A terminal document must refuse every transition.');
            } catch (ValidationException) {
                // expected
            }
        }

        Event::assertDispatchedTimes(DocumentExpired::class, 1);
        Event::assertNotDispatched(DocumentSigned::class);
        $this->assertSame(0, BusinessDocumentSignature::query()->count());
    }

    public function test_every_status_write_in_the_manager_goes_through_the_transition_guard(): void
    {
        $source = file_get_contents((new \ReflectionClass(DocumentManager::class))->getFileName());
        $this->assertIsString($source);

        // Each status assignment is paired with assertTransition() on the same
        // locked row; no per-method `in_array` status list decides a transition.
        $this->assertSame(substr_count($source, '$document->status = DocumentStatus::'), substr_count($source, '->assertTransition($document, DocumentStatus::') );
        $this->assertStringNotContainsString("in_array(\$document->status, [DocumentStatus::Draft, DocumentStatus::Sent, DocumentStatus::Signed], true), 'Document cannot be voided.'", $source);
    }
}
