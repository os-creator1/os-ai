<?php

namespace App\Library\Website\GuidedGeneration;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One structured, sanitized log line for each generated batch the guided generator rejects
 * (`motiongrove.website_generation.batch_rejected`, searchable in the Forge site log).
 *
 * Without this, every rejection - an unusable provider response, truncated JSON, a missing or invented page, a
 * disallowed section, an over-long SEO field - ended as the same customer message and left nothing to diagnose.
 *
 * PRIVACY. Nothing the provider wrote and nothing the customer typed is ever logged: no prompt, no response text, no
 * validator message (those embed page keys and AI-authored values). Only values this code controls or strictly validates:
 * a fixed stage name, counts, a short fixed json error string, a refusal enum value, the exception class name, and the
 * validator's field PATHS (letters, digits, dots and underscores only, capped). Logging is best effort and never replaces
 * the handled failure.
 */
final class GenerationBatchDiagnostics
{
    public const STAGE_PROVIDER_NO_CONTENT = 'provider_no_content';
    public const STAGE_RESPONSE_NOT_JSON = 'response_not_json';
    public const STAGE_RESPONSE_MISSING_PAGES = 'response_missing_pages';
    public const STAGE_VALIDATION_FAILED = 'validation_failed';
    public const STAGE_UNEXPECTED_SHAPE = 'unexpected_shape';

    private const MAX_PATHS = 20;

    /**
     * @param  array<string, mixed>  $detail  already-sanitized scalars from the caller (counts, enum values, fixed strings)
     * @param  array<int, mixed>  $errorPaths  validator field paths
     */
    public static function log(int $websiteId, ?int $attemptId, int $try, string $stage, int $expectedPages, array $detail = [], array $errorPaths = [], ?Throwable $e = null): void
    {
        try {
            $entry = [
                'stage' => $stage,
                'website_id' => $websiteId,
                'attempt_id' => $attemptId,
                'try' => $try,
                'expected_pages' => $expectedPages,
            ] + $detail;

            $paths = self::paths($errorPaths);
            if ($paths !== []) {
                $entry['error_paths'] = $paths;
                $entry['error_path_count'] = count($errorPaths);
            }

            if ($e !== null) {
                $class = $e::class;
                $entry['exception'] = preg_match('/^[A-Za-z0-9_\\\\]+$/', $class) === 1 ? $class : 'anonymous';
            }

            Log::warning('motiongrove.website_generation.batch_rejected', $entry);
        } catch (Throwable) {
            // Diagnostics must never turn a handled failure into a second one.
        }
    }

    /** @return array<int, string> */
    private static function paths(array $paths): array
    {
        $safe = [];

        foreach ($paths as $path) {
            if (is_string($path) && strlen($path) <= 80 && preg_match('/^[A-Za-z0-9_.]+$/', $path) === 1) {
                $safe[$path] = true;
            }

            if (count($safe) >= self::MAX_PATHS) {
                break;
            }
        }

        return array_keys($safe);
    }
}
