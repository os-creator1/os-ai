<?php

namespace App\Library\AgencyOutreach\Import;

/** Dependency-free CSV reader: BOM, UTF-8/Latin-1, delimiter sniffing, ragged rows, bounded size. */
final class ProspectCsvReader
{
    public const MAX_ROWS = 5000;

    /**
     * @return array{headers: list<string>, rows: list<array<string,string>>, truncated: bool, delimiter: string}|array{error: string}
     */
    public static function read(string $path): array
    {
        $raw = @file_get_contents($path);

        if ($raw === false || trim($raw) === '') {
            return ['error' => 'That file is empty.'];
        }

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($raw, "\r\n") ?: '';
        $delimiter = ',';
        $best = 0;

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = substr_count($firstLine, $candidate);

            if ($count > $best) {
                $best = $count;
                $delimiter = $candidate;
            }
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $raw);
        rewind($stream);

        $headers = null;
        $rows = [];
        $truncated = false;

        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            if ($headers === null) {
                $headers = array_map(fn ($h) => trim((string) $h), $cells);

                if (array_filter($headers, fn ($h) => $h !== '') === []) {
                    $headers = null;
                }

                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }

            $row = [];

            foreach ($headers as $i => $header) {
                $key = $header !== '' ? $header : 'column_' . ($i + 1);
                // Duplicate header names stay distinct so no original value is ever lost.
                while (array_key_exists($key, $row)) {
                    $key .= '_' . ($i + 1);
                }
                $row[$key] = trim((string) ($cells[$i] ?? ''));
            }

            $rows[] = $row;
        }

        fclose($stream);

        if ($headers === null) {
            return ['error' => 'No header row was found in that file.'];
        }

        $keys = $rows === [] ? array_map(fn ($h, $i) => $h !== '' ? $h : 'column_' . ($i + 1), $headers, array_keys($headers)) : array_keys($rows[0]);

        return ['headers' => array_values($keys), 'rows' => $rows, 'truncated' => $truncated, 'delimiter' => $delimiter];
    }
}
