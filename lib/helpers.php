<?php

declare(strict_types=1);

namespace Gearsdigital\Periscope;

use Kirby\Exception\NotFoundException;
use Kirby\Toolkit\Str;

/**
 * Reads the configured file and folder list and normalizes it into a flat
 * list of ['id' => string, 'label' => string, 'path' => string, 'minLevel' => ?string].
 * Rebuilt fresh on every request (including folder globs) - new daily log
 * files therefore show up without a restart.
 *
 * @return array<int, array{id: string, label: string, path: string, minLevel: ?string}>
 */
function configuredFiles(): array
{
    $files   = [];
    $usedIds = [];

    foreach (kirby()->option('gearsdigital.periscope.files', []) as $entry) {
        if (empty($entry['path']) || empty($entry['label'])) {
            continue;
        }

        $path = $entry['path'] instanceof \Closure ? $entry['path']() : $entry['path'];

        addFile($files, $usedIds, $entry['label'], $path, $entry['minLevel'] ?? null, $entry['id'] ?? null);
    }

    foreach (kirby()->option('gearsdigital.periscope.folders', []) as $entry) {
        if (empty($entry['path']) || empty($entry['label'])) {
            continue;
        }

        $folder  = $entry['path'] instanceof \Closure ? $entry['path']() : $entry['path'];
        $pattern = $entry['pattern'] ?? '*.log';

        // GLOB_BRACE only when actually needed - not reliably supported on
        // every platform (e.g. some musl/Alpine builds), so a plain pattern
        // like '*.log' must not depend on it.
        $flags   = str_contains($pattern, '{') ? GLOB_BRACE : 0;
        $matches = glob(rtrim($folder, '/') . '/' . $pattern, $flags) ?: [];
        rsort($matches); // Newest first - works for date-based filenames like YYYY-MM-DD.log

        foreach ($matches as $match) {
            if (is_file($match) === false) {
                continue;
            }

            addFile($files, $usedIds, $entry['label'] . ' – ' . basename($match), $match, $entry['minLevel'] ?? null);
        }
    }

    return $files;
}

/**
 * Appends a file to $files with a unique, stable `id` (slug of the label,
 * numbered on collision).
 */
function addFile(array &$files, array &$usedIds, string $label, string $path, ?string $minLevel, ?string $id = null): void
{
    $id     = $id ?? Str::slug($label);
    $unique = $id;
    for ($i = 2; in_array($unique, $usedIds, true); $i++) {
        $unique = $id . '-' . $i;
    }
    $usedIds[] = $unique;

    $files[] = [
        'id'       => $unique,
        'label'    => $label,
        'path'     => $path,
        'minLevel' => $minLevel,
    ];
}

/**
 * Resolves an `id` sent by the panel against the configured file list on the
 * server. Throws if the id doesn't (or no longer) exist - so the API can
 * never be asked for an arbitrary file path.
 *
 * @return array{id: string, label: string, path: string}
 */
function resolveFile(string $id): array
{
    foreach (configuredFiles() as $file) {
        if ($file['id'] === $id) {
            return $file;
        }
    }

    throw new NotFoundException(t('periscope.unknownLogFile', 'Unknown log file: ') . $id);
}

function clampEntries(int $entries): int
{
    $max = (int)kirby()->option('gearsdigital.periscope.maxEntries', 2000);
    return max(1, min($entries, $max));
}

/**
 * Reads up to $maxBytes from the end of the file without loading the whole
 * file into memory - chunked, backwards from the end. `truncated` indicates
 * whether older data remains before the read window.
 *
 * @return array{text: string, truncated: bool}
 */
function readTailChunk(string $path, int $maxBytes): array
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return ['text' => '', 'truncated' => false];
    }

    $chunkSize = 8192;
    $pos       = filesize($path);
    $buffer    = '';
    $read      = 0;

    while ($pos > 0 && $read < $maxBytes) {
        $size    = min($chunkSize, $pos);
        $pos    -= $size;
        fseek($handle, $pos);
        $buffer  = fread($handle, $size) . $buffer;
        $read   += $size;
    }

    fclose($handle);

    return ['text' => $buffer, 'truncated' => $pos > 0];
}

/**
 * Splits a text excerpt into individual log entries (oldest first).
 *
 * Recognizes header lines in the format `[...something with digits...] ...`
 * (covers Monolog `[2026-01-08 15:22:26] INFO …`, PHP's error_log
 * `[08-Jan-2026 15:22:26 UTC] PHP Warning: …`, and Apache logs
 * `[Wed Jan 08 …]`) and appends subsequent lines without a header (e.g.
 * embedded multi-line JSON) to the last entry.
 *
 * If the excerpt contains no such header lines at all (e.g. plain logs
 * without a timestamp bracket), each line is treated as its own entry
 * instead.
 *
 * If the excerpt read was truncated at the start ($truncated), the first
 * entry is discarded since it can only be a fragment.
 *
 * @return array<int, array{level: string, raw: string}>
 */
function parseEntries(string $text, bool $truncated = false): array
{
    if ($text === '') {
        return [];
    }

    $lines = explode("\n", $text);

    $hasHeaders = false;
    foreach ($lines as $line) {
        if (isEntryHeader($line)) {
            $hasHeaders = true;
            break;
        }
    }

    if ($hasHeaders === false) {
        $entries = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $entries[] = ['level' => detectLevel($line), 'raw' => $line];
        }
    } else {
        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            if (isEntryHeader($line)) {
                if ($current !== null) {
                    $entries[] = finalizeEntry($current);
                }
                $current = [$line];
            } elseif ($current !== null) {
                $current[] = $line;
            } else {
                // Text before the first recognized header line: a truncated fragment.
                $current = [$line];
            }
        }

        if ($current !== null) {
            $entries[] = finalizeEntry($current);
        }
    }

    if ($truncated && count($entries) > 0) {
        array_shift($entries);
    }

    return $entries;
}

function isEntryHeader(string $line): bool
{
    return (bool)preg_match('/^\[[^\]\n]{0,40}\d[^\]\n]{0,40}\]/', $line);
}

function finalizeEntry(array $lines): array
{
    $raw = rtrim(implode("\n", $lines), "\r\n");

    return [
        'level' => detectLevel($lines[0]),
        'raw'   => $raw,
    ];
}

/**
 * Severity rank of a level, 0 = most severe. Unknown levels (including
 * 'plain') sort to the bottom - so with an active minimum level they get
 * discarded too, instead of incorrectly slipping through as "important enough".
 */
function levelSeverity(string $level): int
{
    static $order = ['critical', 'error', 'warning', 'notice', 'info', 'debug', 'plain'];

    $index = array_search(strtolower($level), $order, true);

    return $index === false ? count($order) - 1 : $index;
}

/**
 * Finds the last entry whose raw text matches $raw (the oldest entry the
 * client already has), so pagination can anchor on content instead of a
 * position counted from the file's end - a file that keeps growing between
 * requests would otherwise shift what "offset N" points at and produce
 * duplicated/skipped entries on "load older".
 */
function entryIndexBefore(array $entries, string $raw): ?int
{
    for ($i = count($entries) - 1; $i >= 0; $i--) {
        if ($entries[$i]['raw'] === $raw) {
            return $i;
        }
    }

    return null;
}

function detectLevel(string $header): string
{
    static $map = [
        'EMERGENCY' => 'critical',
        'ALERT'     => 'critical',
        'CRITICAL'  => 'critical',
        'FATAL'     => 'critical',
        'ERROR'     => 'error',
        'EXCEPTION' => 'error',
        'WARNING'   => 'warning',
        'WARN'      => 'warning',
        'NOTICE'    => 'notice',
        'INFO'      => 'info',
        'DEBUG'     => 'debug',
    ];

    foreach ($map as $needle => $level) {
        if (stripos($header, $needle) !== false) {
            return $level;
        }
    }

    return 'plain';
}
