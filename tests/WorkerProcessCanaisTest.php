<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../server/includes/channel_processing_helpers.php';

final class WorkerProcessCanaisTest extends TestCase
{
    private function fixturePath(string $filename): string
    {
        $path = __DIR__ . '/../testes/' . $filename;
        $this->assertFileExists($path, sprintf('Fixture %s should exist', $filename));

        return $path;
    }

    /**
     * @return array<int, array{url: string, tvg_logo: string, group_title: string, tvg_name: string}>
     */
    private function collectEntries(string $playlist, int $limit = 5): array
    {
        $entries = [];
        foreach (extractChannelEntries($playlist) as $entry) {
            $entries[] = $entry;
            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    public function testXtreamGolplayVodPlaylistEntriesAreParsed(): void
    {
        $playlist = $this->fixturePath('302415.m3u');
        $entries = $this->collectEntries($playlist);

        $this->assertNotEmpty($entries, 'Expected at least one entry parsed from the Xtream Golplay playlist.');
        $firstEntry = $entries[0];

        $this->assertSame(
            'http://xtreamgolplay.loboservers.shop:80/movie/302415/690472/2.mp4',
            $firstEntry['url'],
            'The first entry should match the provided Xtream Golplay stream URL.'
        );
        $this->assertSame('Filmes | Guerra', $firstEntry['group_title'], 'Group title should be preserved from the playlist metadata.');

        $streamInfo = getStreamTypeByUrl($firstEntry['url']);
        $this->assertSame(0, $streamInfo['type'], 'Entries from the Xtream Golplay URL should be classified as VOD.');
        $this->assertSame('', $streamInfo['category_type']);
    }

    public function testHubbyRunVodPlaylistEntriesAreParsed(): void
    {
        $playlist = $this->fixturePath('tv_channels_bahds10-vods_plus.m3u');
        $entries = $this->collectEntries($playlist);

        $this->assertNotEmpty($entries, 'Expected entries from the Hubby.run playlist.');
        $firstEntry = $entries[0];

        $this->assertSame(
            'http://hubby.run:80/movie/bahds10-vods/j14Ok8Gn0T/663190.mp4',
            $firstEntry['url'],
            'The first entry should match the Hubby.run stream URL provided for tests.'
        );
        $this->assertStringContainsString('Adulto', $firstEntry['tvg_name']);

        $streamInfo = getStreamTypeByUrl($firstEntry['url']);
        $this->assertSame(0, $streamInfo['type'], 'Hubby.run playlist should be recognised as VOD items.');
        $this->assertSame('', $streamInfo['category_type']);
    }

    public function testLiveChannelsPlaylistMaintainsMetadata(): void
    {
        $playlist = $this->fixturePath('playlist_761966021_plus.m3u');
        $entries = $this->collectEntries($playlist);

        $this->assertNotEmpty($entries, 'Expected to parse live channel entries.');
        $firstEntry = $entries[0];

        $this->assertSame('SPORTV 2 HD', $firstEntry['tvg_name']);
        $this->assertSame('⚽ CANAIS • SPORTV', $firstEntry['group_title']);

        $streamInfo = getStreamTypeByUrl($firstEntry['url']);
        $this->assertSame(1, $streamInfo['type'], 'Live channel playlist entries should be classified as live streams.');
        $this->assertSame('live', $streamInfo['category_type']);
    }

    public function testBuildProgressUpdateUsesBrazilianFormatting(): void
    {
        $update = buildProgressUpdate(2500, 5000, 1000, 200, 10);

        $this->assertSame('Processando canais (2.500/5.000)...', $update['message']);
        $this->assertSame(10 + (int) floor((2500 / 5000) * (95 - 10)), $update['progress']);
        $this->assertSame(1000, $update['total_added']);
        $this->assertSame(200, $update['total_skipped']);
        $this->assertSame(10, $update['total_errors']);
    }
}
