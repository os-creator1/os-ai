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
use Tests\Feature\Security\Concerns\PreparesSecurityTenants;
use Tests\TestCase;

/**
 * Security fix F1: sender-ID supporting documents were validated only when TRAI DLT
 * was enabled and were written to public/senderid_docs under the client's extension.
 */
class SenderIdDocumentUploadSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use PreparesSecurityTenants;

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPlaceholderSuperAdmin();
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

    /** A real temp file (fake() derives its MIME from the file name, which would hide content sniffing). */
    private function realUpload(string $name, string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sidtest');
        file_put_contents($path, $body);

        return new UploadedFile($path, $name, null, null, true);
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

        $response = $this->submit($this->realUpload($name, $body));

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

        $this->submit($this->realUpload('proof.pdf', self::PDF))
            ->assertSessionDoesntHaveErrors('document');

        $this->submit(UploadedFile::fake()->create('big.pdf', SenderIdDocumentStore::MAX_KB + 1, 'application/pdf'))
            ->assertSessionHasErrors('document');
    }

    public function test_store_uses_private_random_name_and_ignores_client_name(): void
    {
        $value = SenderIdDocumentStore::store($this->realUpload('../../evil name.pdf', self::PDF));

        $this->assertStringStartsWith(SenderIdDocumentStore::PREFIX . SenderIdDocumentStore::DIR . '/', $value);
        $this->assertMatchesRegularExpression('#^private:senderid-docs/[A-Za-z0-9]{40}\.pdf$#', $value);
        Storage::disk('local')->assertExists(substr($value, strlen(SenderIdDocumentStore::PREFIX)));
        $this->assertFileDoesNotExist(public_path('senderid_docs'));
    }

    public function test_private_upload_rejects_a_failed_storage_write(): void
    {
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        $this->expectException(\RuntimeException::class);
        SenderIdDocumentStore::store($this->realUpload('proof.pdf', self::PDF));
    }

    public function test_failed_private_migration_preserves_the_legacy_file_and_document_reference(): void
    {
        $customer = $this->customer();
        File::ensureDirectoryExists(public_path('senderid_docs'));
        $path = public_path('senderid_docs/senderid_1700000020.pdf');
        File::put($path, self::PDF);
        $url = url('/senderid_docs/senderid_1700000020.pdf');
        $senderid = Senderid::create([
            'user_id' => $customer->user_id,
            'sender_id' => 'FAIL1',
            'status' => 'pending',
            'document' => $url,
        ]);

        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        $this->artisan('senderid:migrate-documents', ['--apply' => true])->assertFailed();
        $this->assertSame($url, $senderid->fresh()->document);
        $this->assertFileExists($path);
    }

    public function test_failed_quarantine_move_preserves_the_original_and_document_reference(): void
    {
        $customer = $this->customer();
        File::ensureDirectoryExists(public_path('senderid_docs'));
        $path = public_path('senderid_docs/senderid_1700000021.php');
        File::put($path, '<?php echo "unsafe";');
        $url = url('/senderid_docs/senderid_1700000021.php');
        $senderid = Senderid::create([
            'user_id' => $customer->user_id,
            'sender_id' => 'FAIL2',
            'status' => 'pending',
            'document' => $url,
        ]);

        File::partialMock()->shouldReceive('move')->once()->andReturn(false);

        $this->artisan('senderid:migrate-documents', ['--apply' => true])->assertFailed();
        $this->assertSame($url, $senderid->fresh()->document);
        $this->assertFileExists($path);
    }

    private function legacyRow(Customer $c, string $file, string $body, string $sid): array
    {
        File::ensureDirectoryExists(public_path('senderid_docs'));
        $path = public_path('senderid_docs/' . $file);
        File::put($path, $body);
        $url = url('/senderid_docs/' . $file);

        return [Senderid::create(['user_id' => $c->user_id, 'sender_id' => $sid, 'status' => 'pending', 'document' => $url]), $path, $url];
    }

    public function test_migration_dry_run_changes_nothing(): void
    {
        [$row, $path, $url] = $this->legacyRow($this->customer(), 'senderid_1700000030.pdf', self::PDF, 'DRY');

        $this->artisan('senderid:migrate-documents')->assertSuccessful();

        $this->assertSame($url, $row->fresh()->document);
        $this->assertFileExists($path);
        $this->assertSame([], Storage::disk('local')->allFiles(SenderIdDocumentStore::DIR));
    }

    public function test_migration_apply_moves_valid_files_and_quarantines_unsafe_ones_without_deleting_either(): void
    {
        $c = $this->customer();
        [$good, $goodPath] = $this->legacyRow($c, 'senderid_1700000031.pdf', self::PDF, 'GOOD');
        [$bad, $badPath] = $this->legacyRow($c, 'senderid_1700000032.php', '<?php echo 1;', 'BAD');

        $this->artisan('senderid:migrate-documents', ['--apply' => true])->assertSuccessful();

        $value = $good->fresh()->document;
        $this->assertStringStartsWith(SenderIdDocumentStore::PREFIX, $value);
        Storage::disk('local')->assertExists(substr($value, strlen(SenderIdDocumentStore::PREFIX)));
        $this->assertFileDoesNotExist($goodPath);
        $this->get(route('customer.senderid.document', $good->uid))->assertOk();

        $this->assertNull($bad->fresh()->document);
        $this->assertFileDoesNotExist($badPath);
        $quarantined = Storage::disk('local')->allFiles('senderid-quarantine');
        $this->assertCount(1, $quarantined);
        $this->assertStringContainsString('row-' . $bad->id . '-', $quarantined[0]);
        $this->assertSame('<?php echo 1;', Storage::disk('local')->get($quarantined[0]));
    }

    public function test_failed_original_delete_rolls_back_so_the_row_stays_retryable(): void
    {
        [$row, $path, $url] = $this->legacyRow($this->customer(), 'senderid_1700000033.pdf', self::PDF, 'DEL');

        File::partialMock()->shouldReceive('delete')->atLeast()->once()->andReturn(false);

        $this->artisan('senderid:migrate-documents', ['--apply' => true])->assertFailed();

        $this->assertFileExists($path);                              // original untouched
        $this->assertSame($url, $row->fresh()->document);            // still on the legacy reference => retried next run
        $this->assertSame([], Storage::disk('local')->allFiles(SenderIdDocumentStore::DIR)); // no orphan private copy
    }

    public function test_rows_sharing_one_legacy_file_all_follow_the_same_outcome(): void
    {
        $c = $this->customer();
        [$first, $path, $url] = $this->legacyRow($c, 'senderid_1700000034.pdf', self::PDF, 'SH1');
        $second = Senderid::create(['user_id' => $c->user_id, 'sender_id' => 'SH2', 'status' => 'pending', 'document' => $url]);

        $this->artisan('senderid:migrate-documents', ['--apply' => true])->assertSuccessful();

        $this->assertFileDoesNotExist($path);
        $this->assertStringStartsWith(SenderIdDocumentStore::PREFIX, $first->fresh()->document);
        $this->assertSame($first->fresh()->document, $second->fresh()->document);
        $this->get(route('customer.senderid.document', $second->uid))->assertOk();
    }

    public function test_every_row_is_migrated_beyond_one_thousand_because_paging_is_by_id(): void
    {
        $c = $this->customer();
        [, , $url] = $this->legacyRow($c, 'senderid_1700000035.pdf', self::PDF, 'BULK0');
        $now = now();
        $rows = [];
        for ($i = 1; $i <= 1100; $i++) {
            $rows[] = ['user_id' => $c->user_id, 'uid' => (string) \Illuminate\Support\Str::uuid(), 'sender_id' => 'B' . $i, 'status' => 'pending', 'document' => $url, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            \Illuminate\Support\Facades\DB::table('senderid')->insert($chunk);
        }

        $this->artisan('senderid:migrate-documents', ['--apply' => true])->assertSuccessful();

        $this->assertSame(0, Senderid::where('document', 'not like', SenderIdDocumentStore::PREFIX . '%')->count());
    }

    public function test_failed_private_storage_write_on_upload_throws_and_stores_nothing(): void
    {
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        $this->expectException(\RuntimeException::class);
        SenderIdDocumentStore::store($this->realUpload('proof.pdf', self::PDF));
    }

    public function test_owner_and_admin_can_download_but_other_tenant_cannot(): void
    {
        $owner = $this->customer(['create_sender_id', 'view_sender_id']);
        $value = SenderIdDocumentStore::store($this->realUpload('proof.pdf', self::PDF));
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
