<?php

declare(strict_types=1);

namespace Gearsdigital\Periscope;

final class HelpersTest extends \KirbyTestCase
{
    public function testDetectLevelMapsKnownMonologLevels(): void
    {
        $this->assertSame('critical', detectLevel('[2026-01-08 10:00:00] app.CRITICAL: boom'));
        $this->assertSame('error', detectLevel('[2026-01-08 10:00:00] app.ERROR: boom'));
        $this->assertSame('warning', detectLevel('[2026-01-08 10:00:00] app.WARNING: careful'));
        $this->assertSame('notice', detectLevel('[2026-01-08 10:00:00] app.NOTICE: fyi'));
        $this->assertSame('info', detectLevel('[2026-01-08 10:00:00] app.INFO: hi'));
        $this->assertSame('debug', detectLevel('[2026-01-08 10:00:00] app.DEBUG: trace'));
    }

    public function testDetectLevelFallsBackToPlain(): void
    {
        $this->assertSame('plain', detectLevel('just some text without a level'));
    }

    public function testLevelSeverityOrdersBySeverityAndUnknownSortsLast(): void
    {
        $this->assertTrue(levelSeverity('critical') < levelSeverity('error'));
        $this->assertTrue(levelSeverity('error') < levelSeverity('warning'));
        $this->assertTrue(levelSeverity('warning') < levelSeverity('plain'));
        $this->assertSame(levelSeverity('plain'), levelSeverity('totally-unknown-level'));
    }

    public function testParseEntriesKeepsMultiLineJsonAttachedToItsHeader(): void
    {
        $text = "[2026-01-08 10:00:00] app.ERROR: failed {\"context\":{\n    \"foo\": \"bar\"\n}}\n"
              . "[2026-01-08 10:00:01] app.INFO: next entry";

        $entries = parseEntries($text);

        $this->assertCount(2, $entries);
        $this->assertStringContainsString('"foo": "bar"', $entries[0]['raw']);
        $this->assertSame('error', $entries[0]['level']);
        $this->assertSame('info', $entries[1]['level']);
    }

    public function testParseEntriesTreatsEachLineAsOwnEntryWithoutHeaders(): void
    {
        $entries = parseEntries("first line\nsecond line");

        $this->assertCount(2, $entries);
        $this->assertSame('plain', $entries[0]['level']);
    }

    public function testParseEntriesDropsFirstEntryWhenTruncated(): void
    {
        $text = "[2026-01-08 10:00:00] app.INFO: fragment\n[2026-01-08 10:00:01] app.INFO: complete";

        $entries = parseEntries($text, truncated: true);

        $this->assertCount(1, $entries);
        $this->assertStringContainsString('complete', $entries[0]['raw']);
    }

    public function testParseEntriesReturnsEmptyArrayForEmptyText(): void
    {
        $this->assertSame([], parseEntries(''));
    }

    public function testClampEntriesRespectsConfiguredMaximum(): void
    {
        $this->kirbyWithOptions(['gearsdigital.periscope.maxEntries' => 500]);

        $this->assertSame(500, clampEntries(999));
        $this->assertSame(1, clampEntries(0));
    }

    public function testReadTailChunkReadsFromEndAndFlagsTruncation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'periscope-test-');
        file_put_contents($path, str_repeat('a', 20_000));

        $chunk = readTailChunk($path, 1_000);

        // Reads in 8 KB chunks, so it returns at least $maxBytes, rounded up
        // to the next chunk boundary - not an exact cut.
        $this->assertGreaterThanOrEqual(1_000, strlen($chunk['text']));
        $this->assertLessThan(20_000, strlen($chunk['text']));
        $this->assertTrue($chunk['truncated']);

        unlink($path);
    }

    public function testReadTailChunkReportsNotTruncatedWhenWholeFileFits(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'periscope-test-');
        file_put_contents($path, 'short content');

        $chunk = readTailChunk($path, 1_000_000);

        $this->assertSame('short content', $chunk['text']);
        $this->assertFalse($chunk['truncated']);

        unlink($path);
    }

    public function testConfiguredFilesReadsFilesAndFoldersNewestFirst(): void
    {
        $dir = sys_get_temp_dir() . '/periscope-folder-test-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/2026-01-01.log', 'old');
        file_put_contents($dir . '/2026-01-02.log', 'new');

        $this->kirbyWithOptions([
            'gearsdigital.periscope.files' => [
                ['label' => 'App', 'path' => '/var/log/app.log'],
            ],
            'gearsdigital.periscope.folders' => [
                ['label' => 'Rotating', 'path' => $dir],
            ],
        ]);

        $files = configuredFiles();

        $this->assertCount(3, $files);
        $this->assertSame('app', $files[0]['id']);
        $this->assertStringContainsString('2026-01-02.log', $files[1]['label']);
        $this->assertStringContainsString('2026-01-01.log', $files[2]['label']);

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    public function testConfiguredFilesMatchesBracePatternWithoutRequiringGlobBrace(): void
    {
        $dir = sys_get_temp_dir() . '/periscope-folder-test-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/app.log', 'log');
        file_put_contents($dir . '/app.txt', 'txt');
        file_put_contents($dir . '/app.json', 'json');

        $this->kirbyWithOptions([
            'gearsdigital.periscope.folders' => [
                ['label' => 'Mixed', 'path' => $dir, 'pattern' => '*.{log,txt}'],
            ],
        ]);

        $files = configuredFiles();

        $this->assertCount(2, $files);

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    public function testResolveFileThrowsForUnknownId(): void
    {
        $this->kirbyWithOptions(['gearsdigital.periscope.files' => []]);

        $this->expectException(\Kirby\Exception\NotFoundException::class);

        resolveFile('does-not-exist');
    }

    public function testEntryIndexBeforeFindsLastMatchingRaw(): void
    {
        $entries = [
            ['level' => 'info', 'raw' => 'a'],
            ['level' => 'info', 'raw' => 'b'],
            ['level' => 'info', 'raw' => 'b'],
            ['level' => 'info', 'raw' => 'c'],
        ];

        // Duplicate content: anchors on the occurrence closest to the end
        // (the one the client actually saw), not the first match.
        $this->assertSame(2, entryIndexBefore($entries, 'b'));
        $this->assertSame(3, entryIndexBefore($entries, 'c'));
        $this->assertNull(entryIndexBefore($entries, 'does-not-exist'));
    }
}
