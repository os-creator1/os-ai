<?php

namespace App\Library\Uploads;

use App\Models\Senderid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sender-ID supporting documents: private storage, server-chosen names.
 *
 * New uploads are written to the private `local` disk (storage/app/senderid-docs),
 * never to public/, under a random name whose extension is derived from the file's
 * detected content - the client-supplied name and extension are never used. The
 * `senderid.document` column then holds "private:<relative path>". Legacy rows still
 * hold a public URL under /senderid_docs; those are only ever served through
 * response() below (allow-listed extensions, forced download), never linked directly.
 */
final class SenderIdDocumentStore
{
    public const PREFIX = 'private:';
    public const DIR = 'senderid-docs';
    public const LEGACY_DIR = 'senderid_docs';
    public const MAX_KB = 5120;

    /** extension => forced download content type */
    public const TYPES = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'png'  => 'image/png',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /** Safe extension for an uploaded file, or null when it is not an allowed document. */
    public static function safeExtension(UploadedFile $file): ?string
    {
        $detected = strtolower((string) $file->guessExtension());
        $detected = $detected === 'jpeg' ? 'jpg' : $detected;

        $declared = strtolower($file->getClientOriginalExtension());
        $declared = $declared === 'jpeg' ? 'jpg' : $declared;

        // Content-detected type must agree with the declared extension (so "x.php" holding a PDF is refused).
        if (in_array($detected, ['pdf', 'jpg', 'png'], true)) {
            return $declared === $detected ? $detected : null;
        }

        // Word files are sniffed by finfo as generic containers (zip / CDF / octet-stream), so the sniffed
        // type alone proves nothing. Accept them only when the declared extension matches AND the container
        // really is a Word document: a .docx must be a ZIP holding [Content_Types].xml and word/document.xml
        // (and no VBA project); a .doc must be an OLE2 compound file with a WordDocument stream.
        $path = $file->getRealPath();
        if ($path !== false && is_file($path)) {
            if ($declared === 'docx' && self::isDocx($path)) {
                return 'docx';
            }
            if ($declared === 'doc' && self::isDoc($path)) {
                return 'doc';
            }
        }

        return null;
    }

    /** A genuine OOXML Word package: ZIP magic + required parts, no macro project. Nothing is extracted. */
    public static function isDocx(string $path): bool
    {
        if (self::magic($path, 4) !== "PK\x03\x04") {
            return false;
        }

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            return $zip->locateName('[Content_Types].xml') !== false
                && $zip->locateName('word/document.xml') !== false
                && $zip->locateName('word/vbaProject.bin') === false;
        } finally {
            $zip->close();
        }
    }

    /** A genuine legacy Word file: OLE2 compound-file header and a UTF-16LE "WordDocument" directory entry. */
    public static function isDoc(string $path): bool
    {
        if (self::magic($path, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            return false;
        }

        $needle = mb_convert_encoding('WordDocument', 'UTF-16LE', 'UTF-8');
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $carry = '';
            while (! feof($handle)) {
                $chunk = fread($handle, 1048576);
                if ($chunk === false) {
                    return false;
                }
                if (str_contains($carry . $chunk, $needle)) {
                    return true;
                }
                $carry = substr($chunk, -(strlen($needle) - 1));
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    private static function magic(string $path, int $bytes): string
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return '';
        }
        $data = (string) fread($h, $bytes);
        fclose($h);

        return $data;
    }

    /** Store an upload privately; returns the value for `senderid.document`. */
    public static function store(UploadedFile $file): string
    {
        $extension = self::safeExtension($file);
        abort_if($extension === null, 422, 'Unsupported document type.');

        $path = self::DIR . '/' . Str::random(40) . '.' . $extension;
        if (Storage::disk('local')->put($path, $file->get()) !== true) {
            throw new \RuntimeException('Unable to store sender-ID document in private storage.');
        }

        return self::PREFIX . $path;
    }

    /** Remove a private file written by store() (used to clean up when the database write fails). */
    public static function discard(?string $value): void
    {
        if ($value !== null && str_starts_with($value, self::PREFIX)) {
            $relative = substr($value, strlen(self::PREFIX));
            if (preg_match('#^' . self::DIR . '/[A-Za-z0-9]{40}\.(pdf|jpg|png|doc|docx)$#', $relative)) {
                Storage::disk('local')->delete($relative);
            }
        }
    }

    /** Resolve the stored value to an absolute readable path, or null. */
    public static function resolve(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (str_starts_with($value, self::PREFIX)) {
            $relative = substr($value, strlen(self::PREFIX));
            if (! preg_match('#^' . self::DIR . '/[A-Za-z0-9]{40}\.(pdf|jpg|png|doc|docx)$#', $relative)) {
                return null;
            }
            $full = Storage::disk('local')->path($relative);

            return is_file($full) ? $full : null;
        }

        // Legacy public URL: only the bare file name under public/senderid_docs counts.
        $name = basename((string) parse_url($value, PHP_URL_PATH));
        if (! preg_match('/^[A-Za-z0-9_\-]+\.(pdf|jpg|jpeg|png|doc|docx)$/i', $name)) {
            return null;
        }
        $full = public_path(self::LEGACY_DIR . '/' . $name);

        return is_file($full) ? $full : null;
    }

    /** Forced-download response for a sender ID's document (caller has authorised). */
    public static function response(Senderid $senderid): BinaryFileResponse
    {
        $full = self::resolve($senderid->document);
        abort_if($full === null, 404);

        $extension = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        return response()->download($full, 'sender-id-document.' . $extension, [
            'Content-Type'           => self::TYPES[$extension],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
