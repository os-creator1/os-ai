<?php

namespace Tests\Feature\Security;

use App\Library\Uploads\SenderIdDocumentStore;
use App\Models\Customer;
use App\Models\Senderid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Security fix F1: sender-ID supporting documents were validated only when TRAI DLT
 * was enabled and were written to public/senderid_docs under the client's extension.
 */
class SenderIdDocumentUploadSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        File::deleteDirectory(public_path('senderid_docs'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('senderid_docs'));
        parent::tearDown();
    }

    private function customer(array $permissions = ['create_sender_id']): Customer
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $customer->user->email_verified_at = now();
        $customer->user->save();
        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);

        return $customer;
    }

    private function submit(UploadedFile $file)
    {
        return $this->post(route('customer.senderid.store'), [
            'sender_id' => 'ACME',
            'plan'      => 999999,
            'document'  => $file,
        ]);
    }

    /** @dataProvider maliciousUploads */
    public function test_malicious_documents_are_rejected_and_nothing_is_written(string $name, string $body): void
    {
        $this->customer();

        $response = $this->submit(UploadedFile::fake()->createWithContent($name, $body));

        $response->assertSessionHasErrors('document');
        $this->assertFileDoesNotExist(public_path('senderid_docs'));
        $this->assertSame([], Storage::disk('local')->allFiles(SenderIdDocumentStore::DIR));
    }

    public static function maliciousUploads(): array
    {
        return [
            'php'                  => ['shell.php', '<?php system($_GET["c"]); ?>'],
            'phtml'                => ['shell.phtml', '<?php echo 1; ?>'],
            'pdf-named php'        => ['shell.pdf.php', self::PDF . '<?php echo 1; ?>'],
            'html'                 => ['x.html', '<script>alert(1)</script>'],
            'svg'                  => ['x.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'],
            'htaccess'             => ['.htaccess', 'AddType application/x-httpd-php .jpg'],
            'html disguised as pdf' => ['x.pdf', '<html><script>alert(1)</script></html>'],
        ];
    }

    public function test_valid_pdf_passes_validation_and_oversized_files_do_not(): void
    {
        $this->customer();

        $this->submit(UploadedFile::fake()->createWithContent('proof.pdf', self::PDF))
            ->assertSessionDoesntHaveErrors('document');

        $this->submit(UploadedFile::fake()->create('big.pdf', SenderIdDocumentStore::MAX_KB + 1, 'application/pdf'))
            ->assertSessionHasErrors('document');
    }

    public function test_store_uses_private_random_name_and_ignores_client_name(): void
    {
        $value = SenderIdDocumentStore::store(UploadedFile::fake()->createWithContent('../../evil name.pdf', self::PDF));

        $this->assertStringStartsWith(SenderIdDocumentStore::PREFIX . SenderIdDocumentStore::DIR . '/', $value);
        $this->assertMatchesRegularExpression('#^private:senderid-docs/[A-Za-z0-9]{40}\.pdf$#', $value);
        Storage::disk('local')->assertExists(substr($value, strlen(SenderIdDocumentStore::PREFIX)));
        $this->assertFileDoesNotExist(public_path('senderid_docs'));
    }

    public function test_owner_and_admin_can_download_but_other_tenant_cannot(): void
    {
        $owner = $this->customer(['create_sender_id', 'view_sender_id']);
        $value = SenderIdDocumentStore::store(UploadedFile::fake()->createWithContent('proof.pdf', self::PDF));
        $senderid = Senderid::create(['user_id' => $owner->user_id, 'sender_id' => 'ACME', 'status' => 'pending', 'document' => $value]);

        $ok = $this->get(route('customer.senderid.document', $senderid->uid));
        $ok->assertOk();
        $ok->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('application/pdf', $ok->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $ok->headers->get('Content-Disposition'));

        $this->customer(['view_sender_id']); // a second tenant
        $this->get(route('customer.senderid.document', $senderid->uid))->assertNotFound();
    }

    public function test_legacy_public_documents_are_still_served_only_when_allow_listed(): void
    {
        $owner = $this->customer();
        File::ensureDirectoryExists(public_path('senderid_docs'));
        File::put(public_path('senderid_docs/senderid_1700000000.pdf'), self::PDF);
        File::put(public_path('senderid_docs/senderid_1700000001.php'), '<?php echo 1;');

        $good = Senderid::create(['user_id' => $owner->user_id, 'sender_id' => 'OLD1', 'status' => 'pending', 'document' => url('/senderid_docs/senderid_1700000000.pdf')]);
        $bad  = Senderid::create(['user_id' => $owner->user_id, 'sender_id' => 'OLD2', 'status' => 'pending', 'document' => url('/senderid_docs/senderid_1700000001.php')]);
        $trav = Senderid::create(['user_id' => $owner->user_id, 'sender_id' => 'OLD3', 'status' => 'pending', 'document' => url('/senderid_docs/../../.env')]);

        $this->get(route('customer.senderid.document', $good->uid))->assertOk();
        $this->get(route('customer.senderid.document', $bad->uid))->assertNotFound();
        $this->get(route('customer.senderid.document', $trav->uid))->assertNotFound();
    }
}
