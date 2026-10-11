<?php

namespace Tests\Feature\Security;

use App\Library\Uploads\SenderIdDocumentStore;
use App\Models\Customer;
use App\Models\Senderid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

    private function customer(array $permissions = ['create_sender_id', 'view_sender_id']): Customer
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

    private function docxBytes(array $entries = null): string
    {
        $entries ??= [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Letter of authorisation</w:t></w:r></w:p></w:body></w:document>',
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $body) {
            $zip->addFromString($name, $body);
        }
        $zip->close();

        return (string) file_get_contents($tmp);
    }

    /** A minimal but structurally valid OLE2 compound file (512-byte sectors) with a WordDocument stream entry. */
    private function docBytes(bool $withWordStream = true): string
    {
        $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 16)
            . pack('vvvvv', 0x3E, 3, 0xFFFE, 9, 6) . str_repeat("\0", 6)
            . pack('VVVVVVVVVV', 0, 1, 1, 0, 4096, 0xFFFFFFFE, 0, 0xFFFFFFFE, 0, 0) // dirsectors,numFAT,firstDir,txn,cutoff,miniFAT,numMini,DIFAT,numDIFAT,DIFAT[0]
            . str_repeat(pack('V', 0xFFFFFFFF), 108);
        $header = substr($header, 0, 512);
        $fat = pack('VV', 0xFFFFFFFD, 0xFFFFFFFE) . str_repeat(pack('V', 0xFFFFFFFF), 126);
        $entry = function (string $name, int $type, int $start, int $size) {
            $utf16 = mb_convert_encoding($name, 'UTF-16LE', 'UTF-8') . "\0\0";

            return str_pad($utf16, 64, "\0") . pack('v', strlen($utf16)) . chr($type) . "\1"
                . pack('VVV', 0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF) . str_repeat("\0", 16) . pack('VVVV', 0, 0, $start, $size) . pack('V', 0);
        };
        $dir = $entry('Root Entry', 5, 0xFFFFFFFE, 0) . ($withWordStream ? $entry('WordDocument', 2, 0xFFFFFFFE, 0) : $entry('Workbook', 2, 0xFFFFFFFE, 0));
        $dir = str_pad($dir, 512, "\0");

        return $header . $fat . $dir;
    }

    public function test_legitimate_word_documents_are_accepted_and_stored_with_a_safe_extension(): void
    {
        $this->customer();

        $this->submit($this->realUpload('letter.docx', $this->docxBytes()))->assertSessionDoesntHaveErrors('document');
        $this->submit($this->realUpload('LETTER.DOC', $this->docBytes()))->assertSessionDoesntHaveErrors('document');

        $docx = SenderIdDocumentStore::store($this->realUpload('letter.docx', $this->docxBytes()));
        $doc = SenderIdDocumentStore::store($this->realUpload('letter.doc', $this->docBytes()));
        $this->assertMatchesRegularExpression('#\.docx$#', $docx);
        $this->assertMatchesRegularExpression('#\.doc$#', $doc);
    }

    /** @dataProvider disguisedContainers */
    public function test_disguised_or_arbitrary_containers_are_rejected(string $name, string $kind): void
    {
        $this->customer();
        $body = match ($kind) {
            'empty-zip-as-docx' => $this->docxBytes(['readme.txt' => 'hello']),
            'zip-with-php-as-docx' => $this->docxBytes(['shell.php' => '<?php system($_GET[0]);']),
            'macro-docx' => $this->docxBytes(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>', 'word/vbaProject.bin' => 'MZ']),
            'random-binary' => random_bytes(2048),
            'ole-without-word' => $this->docBytes(false),
            'docx-named-doc' => $this->docxBytes(),
            'doc-named-docx' => $this->docBytes(),
            'plain-text' => 'just text',
            'pdf-named-php' => "%PDF-1.4\n%%EOF\n",
        };

        $this->submit($this->realUpload($name, $body))->assertSessionHasErrors('document');
        $this->assertSame([], Storage::disk('local')->allFiles(SenderIdDocumentStore::DIR));
    }

    public static function disguisedContainers(): array
    {
        return [
            'arbitrary zip as docx' => ['a.docx', 'empty-zip-as-docx'],
            'zip with php as docx' => ['b.docx', 'zip-with-php-as-docx'],
            'macro-bearing docx' => ['c.docx', 'macro-docx'],
            'random binary as doc' => ['d.doc', 'random-binary'],
            'random binary as docx' => ['d.docx', 'random-binary'],
            'ole container that is not word' => ['e.doc', 'ole-without-word'],
            'docx bytes named .doc' => ['f.doc', 'docx-named-doc'],
            'doc bytes named .docx' => ['g.docx', 'doc-named-docx'],
            'text named doc' => ['h.doc', 'plain-text'],
            'pdf content with php extension' => ['i.php', 'pdf-named-php'],
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

        Senderid::create(['user_id' => $row->user_id, 'sender_id' => 'DRY2', 'status' => 'pending', 'document' => $url]);

        $this->artisan('senderid:migrate-documents')->expectsOutputToContain('Dry run: 1 moved, 0 quarantined, 0 missing, 0 failed')->assertSuccessful();

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

    public function test_failed_private_storage_write_on_upload_throws_and_cleans_up(): void
    {
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once();
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        $this->expectException(\RuntimeException::class);
        SenderIdDocumentStore::store($this->realUpload('proof.pdf', self::PDF));
    }

    /** @dataProvider partialWrites */
    public function test_partial_or_unverifiable_writes_are_removed_and_rejected(string $mode): void
    {
        $upload = $this->realUpload('proof.pdf', self::PDF);
        $removed = [];
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(true);   // the filesystem claims success
        match ($mode) {
            'truncated' => $disk->shouldReceive('size')->once()->andReturn(strlen(self::PDF) - 5),
            'empty' => $disk->shouldReceive('size')->once()->andReturn(0),
            'size-throws' => $disk->shouldReceive('size')->once()->andThrow(new \RuntimeException('stat failed')),
        };
        $disk->shouldReceive('delete')->once()->andReturnUsing(function ($path) use (&$removed) {
            $removed[] = $path;

            return true;
        });
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        try {
            SenderIdDocumentStore::store($upload);
            $this->fail('a truncated or unverifiable private file must not be accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Unable to store', $e->getMessage());
        }

        $this->assertCount(1, $removed);
        $this->assertMatchesRegularExpression('#^senderid-docs/[A-Za-z0-9]{40}\.pdf$#', $removed[0]);
    }

    public static function partialWrites(): array
    {
        return ['truncated' => ['truncated'], 'zero bytes' => ['empty'], 'size check fails' => ['size-throws']];
    }

    public function test_cleanup_failure_after_a_partial_write_still_reports_the_original_error(): void
    {
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('size')->once()->andReturn(1);
        $disk->shouldReceive('delete')->once()->andThrow(new \RuntimeException('cannot delete'));
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        $this->expectExceptionMessage('Unable to store sender-ID document in private storage.');
        SenderIdDocumentStore::store($this->realUpload('proof.pdf', self::PDF));
    }

    public function test_a_partial_write_during_a_full_request_leaves_no_row_and_the_user_is_told(): void
    {
        $c = $this->customer();
        $this->subscribe($c);
        $plan = $this->plan();

        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('size')->once()->andReturn(3);
        $disk->shouldReceive('delete')->once();
        Storage::shouldReceive('disk')->andReturn($disk);

        $this->post(route('customer.senderid.store'), ['sender_id' => 'PARTIAL', 'plan' => $plan->id, 'document' => $this->realUpload('proof.pdf', self::PDF)])
            ->assertSessionHas('status', 'error');

        $this->assertSame(0, Senderid::where('sender_id', 'PARTIAL')->count());
    }

    public function test_complete_upload_still_stores_exactly_the_uploaded_bytes(): void
    {
        $value = SenderIdDocumentStore::store($this->realUpload('proof.pdf', self::PDF));

        $this->assertSame(self::PDF, Storage::disk('local')->get(substr($value, strlen(SenderIdDocumentStore::PREFIX))));
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

    public function test_owner_without_view_sender_id_permission_cannot_download(): void
    {
        $owner = $this->customer(['create_sender_id', 'view_sender_id']);
        $value = SenderIdDocumentStore::store($this->realUpload('proof.pdf', self::PDF));
        $senderid = Senderid::create(['user_id' => $owner->user_id, 'sender_id' => 'ACME', 'status' => 'pending', 'document' => $value]);

        $this->get(route('customer.senderid.document', $senderid->uid))->assertOk();

        // Same owner, permission revoked.
        $this->withSession(['permissions' => collect(['access_backend', 'create_sender_id'])]);
        $this->get(route('customer.senderid.document', $senderid->uid))->assertUnauthorized(); // app maps authorisation denial to 401
    }

    public function test_database_failure_after_quarantine_move_restores_the_file_and_keeps_the_row(): void
    {
        [$row, $path, $url] = $this->legacyRow($this->customer(), 'senderid_1700000036.php', '<?php echo 1;', 'DBF');

        Senderid::updating(function () {
            throw new \RuntimeException('simulated database failure');
        });

        $this->artisan('senderid:migrate-documents', ['--apply' => true])->assertFailed();

        $this->assertFileExists($path);                                   // file restored to the exact original path
        $this->assertSame('<?php echo 1;', file_get_contents($path));
        $this->assertSame($url, \Illuminate\Support\Facades\DB::table('senderid')->where('id', $row->id)->value('document'));
        $this->assertSame([], Storage::disk('local')->allFiles('senderid-quarantine'));
    }

    private function plan(): \App\Models\SenderidPlan
    {
        $currency = \App\Models\Currency::query()->first()
            ?? \App\Models\Currency::create(['name' => 'Test Dollar', 'code' => 'CGT', 'format' => '$', 'status' => true]);

        return \App\Models\SenderidPlan::create(['price' => 5, 'billing_cycle' => 'monthly', 'frequency_amount' => 1, 'frequency_unit' => 'month', 'currency_id' => $currency->id]);
    }

    private function subscribe(Customer $customer): void
    {
        $currency = \App\Models\Currency::query()->first()
            ?? \App\Models\Currency::create(['name' => 'Test Dollar', 'code' => 'CGT', 'format' => '$', 'status' => true]);
        $plan = \App\Models\Plan::create([
            'user_id' => $customer->user_id, 'name' => 'P', 'price' => 10, 'billing_cycle' => 'monthly', 'frequency_amount' => 1,
            'frequency_unit' => 'month', 'currency_id' => $currency->id, 'options' => json_encode([]), 'status' => true,
        ]);
        \App\Models\Subscription::create([
            'user_id' => $customer->user_id, 'plan_id' => $plan->id, 'status' => \App\Models\Subscription::STATUS_ACTIVE,
            'paid' => true, 'start_at' => now(), 'end_at' => null,
        ]);
    }

    public function test_full_sender_id_request_with_document_creates_row_and_private_file_atomically(): void
    {
        $c = $this->customer();
        $this->subscribe($c);
        $plan = $this->plan();

        $this->post(route('customer.senderid.store'), ['sender_id' => 'ATOMIC', 'plan' => $plan->id, 'document' => $this->realUpload('proof.pdf', self::PDF)]);

        $row = Senderid::where('sender_id', 'ATOMIC')->first();
        $this->assertNotNull($row);
        $this->assertStringStartsWith(SenderIdDocumentStore::PREFIX, $row->document);
        Storage::disk('local')->assertExists(substr($row->document, strlen(SenderIdDocumentStore::PREFIX)));
    }

    public function test_database_failure_during_request_creation_leaves_no_row_and_no_private_file(): void
    {
        $c = $this->customer();
        $this->subscribe($c);
        $plan = $this->plan();

        // Fails on the second write (document attachment), after the row insert, inside the same transaction.
        Senderid::updating(function () {
            throw new \RuntimeException('simulated database failure');
        });

        try {
            $this->withoutExceptionHandling()->post(route('customer.senderid.store'), ['sender_id' => 'ROLLBK', 'plan' => $plan->id, 'document' => $this->realUpload('proof.pdf', self::PDF)]);
            $this->fail('expected the simulated failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated database failure', $e->getMessage());
        }

        $this->assertSame(0, Senderid::where('sender_id', 'ROLLBK')->count());   // row rolled back
        $this->assertSame([], Storage::disk('local')->allFiles(SenderIdDocumentStore::DIR)); // private file cleaned up
    }

    public function test_shared_file_save_failure_rolls_back_all_rows_and_keeps_the_original(): void
    {
        $c = $this->customer();
        [$first, $path, $url] = $this->legacyRow($c, 'senderid_1700000037.pdf', self::PDF, 'SHA');
        $second = Senderid::create(['user_id' => $c->user_id, 'sender_id' => 'SHB', 'status' => 'pending', 'document' => $url]);

        Senderid::updating(function (Senderid $row) {
            if ($row->sender_id === 'SHB') {
                throw new \RuntimeException('simulated failure on the second shared row');
            }
        });

        $this->artisan('senderid:migrate-documents', ['--apply' => true])->assertFailed();

        $this->assertFileExists($path);
        $this->assertSame($url, DB::table('senderid')->where('id', $first->id)->value('document'));   // first row rolled back too
        $this->assertSame($url, DB::table('senderid')->where('id', $second->id)->value('document'));
        $this->assertSame([], Storage::disk('local')->allFiles(SenderIdDocumentStore::DIR));
    }

    public function test_shared_file_original_delete_failure_restores_every_row(): void
    {
        $c = $this->customer();
        [$first, $path, $url] = $this->legacyRow($c, 'senderid_1700000038.pdf', self::PDF, 'SHC');
        $second = Senderid::create(['user_id' => $c->user_id, 'sender_id' => 'SHD', 'status' => 'pending', 'document' => $url]);

        File::partialMock()->shouldReceive('delete')->atLeast()->once()->andReturn(false);

        $this->artisan('senderid:migrate-documents', ['--apply' => true])->assertFailed();

        $this->assertFileExists($path);
        $this->assertSame($url, DB::table('senderid')->where('id', $first->id)->value('document'));
        $this->assertSame($url, DB::table('senderid')->where('id', $second->id)->value('document'));
        $this->assertSame([], Storage::disk('local')->allFiles(SenderIdDocumentStore::DIR));
    }

    public function test_shared_rows_in_one_chunk_are_not_falsely_reported_missing_or_double_processed(): void
    {
        $c = $this->customer();
        [$a, $path, $url] = $this->legacyRow($c, 'senderid_1700000039.pdf', self::PDF, 'CH1');
        $b = Senderid::create(['user_id' => $c->user_id, 'sender_id' => 'CH2', 'status' => 'pending', 'document' => $url]);
        $d = Senderid::create(['user_id' => $c->user_id, 'sender_id' => 'CH3', 'status' => 'pending', 'document' => $url]);
        [$bad1, , $badUrl] = $this->legacyRow($c, 'senderid_1700000040.php', '<?php echo 1;', 'CH4');
        $bad2 = Senderid::create(['user_id' => $c->user_id, 'sender_id' => 'CH5', 'status' => 'pending', 'document' => $badUrl]);

        $this->artisan('senderid:migrate-documents', ['--apply' => true])
            ->expectsOutputToContain('1 moved, 1 quarantined, 0 missing, 0 failed')
            ->assertSuccessful();

        $private = $a->fresh()->document;
        $this->assertStringStartsWith(SenderIdDocumentStore::PREFIX, $private);
        $this->assertSame($private, $b->fresh()->document);
        $this->assertSame($private, $d->fresh()->document);
        $this->assertCount(1, Storage::disk('local')->allFiles(SenderIdDocumentStore::DIR)); // one copy, not three
        $this->assertNull($bad1->fresh()->document);
        $this->assertNull($bad2->fresh()->document);
        $this->assertCount(1, Storage::disk('local')->allFiles('senderid-quarantine'));
    }

    public function test_migration_streams_the_original_and_closes_the_handle_even_when_the_write_fails(): void
    {
        [$row, $path, $url] = $this->legacyRow($this->customer(), 'senderid_1700000041.pdf', self::PDF, 'STRM');

        $captured = null;
        $wasStream = false;
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnUsing(function ($relative, $contents) use (&$captured, &$wasStream) {
            $captured = $contents;
            $wasStream = is_resource($contents) && get_resource_type($contents) === 'stream'; // open stream, not a string

            return false;
        });
        $disk->shouldReceive('delete')->zeroOrMoreTimes();
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        $this->artisan('senderid:migrate-documents', ['--apply' => true])->assertFailed();

        $this->assertTrue($wasStream, 'storage must receive an open stream, never the file contents as a string');
        $this->assertSame('Unknown', get_resource_type($captured), 'the read handle must be closed after a failed write');
        $this->assertFileExists($path);
        $this->assertSame($url, DB::table('senderid')->where('id', $row->id)->value('document'));
    }

    public function test_streamed_migration_copies_content_exactly_and_leaves_no_open_handle(): void
    {
        $body = self::PDF . str_repeat('A', 300000); // ~300 KB: multi-buffer, still tiny
        [$row, $path] = $this->legacyRow($this->customer(), 'senderid_1700000042.pdf', $body, 'BIG');

        $this->artisan('senderid:migrate-documents', ['--apply' => true])->assertSuccessful();

        $value = $row->fresh()->document;
        $this->assertSame($body, Storage::disk('local')->get(substr($value, strlen(SenderIdDocumentStore::PREFIX))));
        $this->assertFileDoesNotExist($path);
    }

    public function test_size_mismatch_after_write_is_treated_as_failure_and_keeps_original(): void
    {
        [$row, $path, $url] = $this->legacyRow($this->customer(), 'senderid_1700000043.pdf', self::PDF, 'SIZE');

        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('size')->once()->andReturn(1);
        $disk->shouldReceive('delete')->once();
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        $this->artisan('senderid:migrate-documents', ['--apply' => true])->assertFailed();

        $this->assertFileExists($path);
        $this->assertSame($url, DB::table('senderid')->where('id', $row->id)->value('document'));
    }

    /** A second, independent DB session standing in for another deployment node / process. */
    private function otherNode(): \Illuminate\Database\Connection
    {
        config(['database.connections.other_node' => config('database.connections.' . config('database.default'))]);
        DB::purge('other_node');

        return DB::connection('other_node');
    }

    private function lockName(): string
    {
        return 'senderid-migrate:' . substr(md5((string) DB::connection()->getDatabaseName()), 0, 16);
    }

    public function test_overlapping_apply_run_is_refused_without_touching_anything(): void
    {
        [$row, $path, $url] = $this->legacyRow($this->customer(), 'senderid_1700000044.pdf', self::PDF, 'LCK1');

        $other = $this->otherNode();
        $this->assertSame(1, (int) $other->selectOne('SELECT GET_LOCK(?, 0) AS l', [$this->lockName()])->l); // the "other node" is mid-migration

        $this->artisan('senderid:migrate-documents', ['--apply' => true])
            ->expectsOutputToContain('already running')
            ->assertFailed();

        $this->assertFileExists($path);
        $this->assertSame($url, DB::table('senderid')->where('id', $row->id)->value('document'));
        $this->assertSame([], Storage::disk('local')->allFiles(SenderIdDocumentStore::DIR));

        $other->selectOne('SELECT RELEASE_LOCK(?) AS l', [$this->lockName()]);
    }

    public function test_lock_is_released_after_success_and_after_failure_so_the_next_run_proceeds(): void
    {
        [$row, $path] = $this->legacyRow($this->customer(), 'senderid_1700000045.pdf', self::PDF, 'LCK2');

        // A failing run (original cannot be deleted) must still release the lock...
        File::partialMock()->shouldReceive('delete')->atLeast()->once()->andReturn(false);
        $this->artisan('senderid:migrate-documents', ['--apply' => true])->assertFailed();

        $other = $this->otherNode();
        $this->assertSame(1, (int) $other->selectOne('SELECT GET_LOCK(?, 0) AS l', [$this->lockName()])->l, 'lock leaked after a failed run');
        $other->selectOne('SELECT RELEASE_LOCK(?) AS l', [$this->lockName()]);
        $this->assertFileExists($path);

        // ...and so must a successful one.
        \Mockery::close();
        $this->app->forgetInstance('files');
        File::clearResolvedInstance('files');
        $this->artisan('senderid:migrate-documents', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1, (int) $other->selectOne('SELECT GET_LOCK(?, 0) AS l', [$this->lockName()])->l, 'lock leaked after a successful run');
        $other->selectOne('SELECT RELEASE_LOCK(?) AS l', [$this->lockName()]);
    }

    public function test_dry_run_does_not_need_or_take_the_lock(): void
    {
        $this->legacyRow($this->customer(), 'senderid_1700000046.pdf', self::PDF, 'LCK3');
        $other = $this->otherNode();
        $other->selectOne('SELECT GET_LOCK(?, 0) AS l', [$this->lockName()]);

        $this->artisan('senderid:migrate-documents')->assertSuccessful();

        $other->selectOne('SELECT RELEASE_LOCK(?) AS l', [$this->lockName()]);
    }

    private const GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF!\xF9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";

    public function test_new_gif_uploads_stay_blocked(): void
    {
        $this->customer();

        $this->submit($this->realUpload('anim.gif', self::GIF))->assertSessionHasErrors('document');
        $this->assertSame([], Storage::disk('local')->allFiles(SenderIdDocumentStore::DIR));
        $this->assertNull(SenderIdDocumentStore::safeExtension($this->realUpload('anim.gif', self::GIF)));
    }

    public function test_genuine_legacy_gif_remains_downloadable_with_safe_headers_and_for_the_owner_only(): void
    {
        $owner = $this->customer();
        [$row] = $this->legacyRow($owner, 'senderid_1700000050.gif', self::GIF, 'GIF1');

        $ok = $this->get(route('customer.senderid.document', $row->uid));
        $ok->assertOk();
        $this->assertStringContainsString('image/gif', $ok->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $ok->headers->get('Content-Disposition'));
        $ok->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->customer(); // another tenant
        $this->get(route('customer.senderid.document', $row->uid))->assertNotFound();
    }

    public function test_disguised_legacy_gif_is_not_served(): void
    {
        $owner = $this->customer();
        [$row] = $this->legacyRow($owner, 'senderid_1700000051.gif', '<html><script>alert(1)</script></html>', 'GIF2');

        $this->get(route('customer.senderid.document', $row->uid))->assertNotFound();
    }

    public function test_migration_moves_genuine_gifs_and_quarantines_disguised_ones(): void
    {
        $c = $this->customer();
        [$good, $goodPath] = $this->legacyRow($c, 'senderid_1700000052.gif', self::GIF, 'GIF3');
        [$bad, $badPath] = $this->legacyRow($c, 'senderid_1700000053.gif', '<?php echo 1; ?>', 'GIF4');

        $this->artisan('senderid:migrate-documents', ['--apply' => true])->assertSuccessful();

        $value = $good->fresh()->document;
        $this->assertMatchesRegularExpression('#^private:senderid-docs/[A-Za-z0-9]{40}\.gif$#', $value);
        $this->assertSame(self::GIF, Storage::disk('local')->get(substr($value, strlen(SenderIdDocumentStore::PREFIX))));
        $this->assertFileDoesNotExist($goodPath);
        $this->get(route('customer.senderid.document', $good->uid))->assertOk();

        $this->assertNull($bad->fresh()->document);                    // not a GIF => quarantined, never public
        $this->assertFileDoesNotExist($badPath);
        $this->assertNotEmpty(Storage::disk('local')->allFiles('senderid-quarantine'));
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
