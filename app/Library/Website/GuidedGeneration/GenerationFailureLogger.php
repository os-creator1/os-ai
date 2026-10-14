<?php

namespace App\Library\Website\GuidedGeneration;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-side diagnostics for an unexpected failure while generating a website.
 *
 * `report($e)` silently drops whole exception classes (ModelNotFoundException,
 * ValidationException, AuthorizationException, … — see App\Exceptions\Handler::$dontReport),
 * which is exactly how a generation failure can reach the customer as a generic
 * message while leaving no log line and no attempt row. This writes one explicit
 * entry regardless of the exception class. Exception reporting itself is untouched.
 *
 * PRIVACY. An exception message is untrusted free text: it can carry customer
 * answers, e-mail addresses, URLs, tokens, SQL values. It is therefore NEVER
 * logged, in whole or in part, and no attempt is made to "sanitize" it. The entry
 * is built only from values the code itself controls or can validate strictly:
 *
 *   - the stage name (a fixed literal chosen by the controller);
 *   - the exception class name (anonymous classes, whose name embeds a file path, are reduced to a marker);
 *   - a validated SQLSTATE class/code and integer driver code for database errors — never the SQL or bindings;
 *   - the throw site and up to MAX_FRAMES caller frames as application-relative `file:line`
 *     (a path outside the application directory becomes `external`);
 *   - the website and questionnaire-response ids (integers).
 *
 * It never logs answers, request input, model attributes or a full stack trace.
 * Logging is best effort: a failure here must never replace the handled failure.
 */
final class GenerationFailureLogger
{
    private const MAX_FRAMES = 8;

    public static function log(Throwable $e, string $stage, int $websiteId, int $responseId): void
    {
        try {
            $entry = [
                'stage' => $stage,
                'exception' => self::className($e),
                'location' => self::location($e->getFile(), $e->getLine()),
                'frames' => self::frames($e),
                'website_id' => $websiteId,
                'questionnaire_response_id' => $responseId,
            ];

            if ($e instanceof QueryException) {
                $entry['sqlstate'] = self::sqlState($e);
                $entry['driver_code'] = self::driverCode($e);
            }

            Log::error('Website generation failed', $entry);
        } catch (Throwable) {
            // Diagnostics must never turn a handled failure into a second one.
        }
    }

    private static function className(Throwable $e): string
    {
        $class = $e::class;

        // An anonymous class name embeds the declaring file's absolute path.
        return str_contains($class, '@anonymous') || ! preg_match('/^[A-Za-z0-9_\\\\]+$/', $class) ? 'anonymous' : $class;
    }

    /** Only a well-formed five-character SQLSTATE; anything else is dropped, never echoed. */
    private static function sqlState(QueryException $e): ?string
    {
        $state = $e->errorInfo[0] ?? null;

        return is_string($state) && preg_match('/^[0-9A-Z]{5}$/', $state) ? $state : null;
    }

    private static function driverCode(QueryException $e): ?int
    {
        $code = $e->errorInfo[1] ?? null;

        return is_int($code) || (is_string($code) && ctype_digit($code)) ? (int) $code : null;
    }

    /**
     * @return array<int, string> the first application frames only (vendor frames are noise)
     */
    private static function frames(Throwable $e): array
    {
        $frames = [];

        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? null;

            if (! is_string($file) || str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $location = self::location($file, (int) ($frame['line'] ?? 0));

            if ($location === 'external') {
                continue;
            }

            $frames[] = $location;

            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }
        }

        return $frames;
    }

    /** `relative/path.php:line` for a file inside the application directory; `external` for anything else. */
    private static function location(string $file, int $line): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (! str_starts_with($file, $base) || str_contains($file, '..')) {
            return 'external';
        }

        return substr($file, strlen($base)) . ':' . $line;
    }
}
