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
    public const RULE_MIMES = 'pdf,jpg,jpeg,png,doc,docx';

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

        if (in_array($detected, ['pdf', 'jpg', 'png'], true)) {
            return $detected;
        }

        // Office files are sniffed as zip/cdf/bin; accept them only when the declared
        // extension is doc/docx AND the content is one of those container types.
        $declared = strtolower($file->getClientOriginalExtension());
        if (in_array($declared, ['doc', 'docx'], true) && in_array($detected, ['doc', 'docx', 'zip', 'cdf', 'bin', 'msword', ''], true)) {
            return $declared;
        }

        return null;
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
