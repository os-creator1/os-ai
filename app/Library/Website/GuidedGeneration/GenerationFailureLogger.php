<?php

namespace App\Library\Website\GuidedGeneration;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-side diagnostics for an unexpected failure while generating a website.
 *
 * `report($e)` silently drops whole exception classes (ModelNotFoundException,
 * ValidationException, AuthorizationException, … — see App\Exceptions\Handler::$dontReport),
 * which is exactly how a generation failure can reach the customer as a generic
 * message while leaving no log line and no attempt row. This writes one explicit,
 * sanitized entry regardless of the exception class. Exception reporting itself
 * is untouched.
 *
 * Nothing here ever reaches a browser. The entry carries the failing stage, the
 * exception class, its location and a bounded message with the application's
 * base path removed; it deliberately does not log request input, answers,
 * model attributes or the full stack trace.
 */
final class GenerationFailureLogger
{
    private const MAX_MESSAGE = 300;

    private const MAX_FRAMES = 8;

    public static function log(Throwable $e, string $stage, int $websiteId, int $responseId): void
    {
        try {
            Log::error('Website generation failed', [
                'stage' => $stage,
                'exception' => $e::class,
                'location' => self::relative($e->getFile()) . ':' . $e->getLine(),
                'message' => self::message($e),
                'frames' => self::frames($e),
                'website_id' => $websiteId,
                'questionnaire_response_id' => $responseId,
            ]);
        } catch (Throwable) {
            // Diagnostics must never turn a handled failure into a second one.
        }
    }

    private static function message(Throwable $e): string
    {
        // A database exception's message embeds the bound values (customer data); keep only the SQLSTATE code.
        if ($e instanceof \Illuminate\Database\QueryException) {
            return 'SQLSTATE[' . ($e->errorInfo[0] ?? '?') . '] driver code ' . ($e->errorInfo[1] ?? '?');
        }

        return mb_substr(str_replace(base_path(), '', $e->getMessage()), 0, self::MAX_MESSAGE);
    }

    /**
     * @return array<int, string> the first application frames only (vendor frames are noise)
     */
    private static function frames(Throwable $e): array
    {
        $frames = [];

        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $frames[] = self::relative($file) . ':' . ($frame['line'] ?? '?');

            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }
        }

        return $frames;
    }

    private static function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);
    }
}
