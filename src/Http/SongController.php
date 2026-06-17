<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Services\AlbumArtService;
use PanicMic\Services\EventBus;
use PanicMic\Services\LastfmService;
use PanicMic\Services\SongService;
use PanicMic\Services\YouTubeService;
use PanicMic\Support\Request;
use PanicMic\Support\Response;
use PDO;

final class SongController
{
    public static function listAdmin(PDO $db): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        Response::json(SongService::search($db, $_GET));
    }

    /** @param array<string,mixed> $tenant */
    public static function create(PDO $db, array $tenant): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $input = Request::input();
        if (trim((string)($input['title'] ?? '')) === '' || trim((string)($input['artist'] ?? '')) === '') {
            Response::json(['error' => 'Title and artist are required'], 400);
        }
        $id = SongService::create($db, $input);
        EventBus::publish($db, 'song:created', ['songId' => $id]);
        Response::json(['id' => $id]);
    }

    /** @param array<string,mixed> $tenant */
    public static function update(PDO $db, array $tenant, int $songId): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $input = Request::input();
        if (trim((string)($input['title'] ?? '')) === '' || trim((string)($input['artist'] ?? '')) === '') {
            Response::json(['error' => 'Title and artist are required'], 400);
        }
        SongService::update($db, $songId, $input);
        EventBus::publish($db, 'song:updated', ['songId' => $songId]);
        Response::json(['ok' => true]);
    }

    public static function delete(PDO $db, int $songId): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        SongService::delete($db, $songId);
        EventBus::publish($db, 'song:deleted', ['songId' => $songId]);
        Response::json(['ok' => true]);
    }

    public static function importYouTubePlaylist(PDO $db): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        if (!YouTubeService::isEnabled()) {
            Response::json(['error' => 'YouTube API key is not configured'], 400);
        }
        $input = Request::input();
        $playlistId = YouTubeService::parsePlaylistId((string)($input['playlist'] ?? ''));
        if (!$playlistId) {
            Response::json(['error' => 'Could not extract a playlist ID from the input'], 400);
        }
        $rows = [];
        $skippedNoArtist = 0;
        try {
            foreach (YouTubeService::fetchPlaylist($playlistId) as $entry) {
                $parsed = YouTubeService::parseSongTitle($entry['video_title']);
                $artist = $parsed['artist'] !== '' ? $parsed['artist'] : $entry['channel'];
                $title = $parsed['title'];
                if ($artist === '' || $title === '') {
                    $skippedNoArtist++;
                    continue;
                }
                $rows[] = [
                    'title' => $title,
                    'artist' => $artist,
                    'video_url' => 'https://www.youtube.com/watch?v=' . rawurlencode($entry['video_id']),
                    'video_provider' => 'youtube',
                    'provider_track_id' => $entry['video_id'],
                    'provider_url' => 'https://www.youtube.com/watch?v=' . rawurlencode($entry['video_id']),
                    'provider_metadata' => json_encode([
                        'youtube' => [
                            'playlist_id' => $playlistId,
                            'channel' => $entry['channel'],
                            'original_title' => $entry['video_title'],
                        ],
                    ]),
                ];
            }
        } catch (\Throwable $error) {
            Response::json(['error' => $error->getMessage()], 502);
        }
        $result = SongService::bulkImport($db, $rows);
        EventBus::publish($db, 'song:imported', ['source' => 'youtube_playlist', 'imported' => $result['imported']]);
        Response::json([
            'imported' => $result['imported'],
            'skipped' => $result['skipped'] + $skippedNoArtist,
            'total_seen' => count($rows) + $skippedNoArtist,
        ]);
    }

    /**
     * Look up album art for a song via Last.fm, download it, cache it
     * locally under content/{slug}/album-art/, and update the DB record.
     *
     * POST /api/admin/songs/{id}/fetch-album-art
     *
     * @param array<string,mixed> $tenant
     */
    public static function fetchAlbumArt(PDO $db, array $tenant, int $songId): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');

        $song = SongService::find($db, $songId);
        if (!$song) {
            Response::json(['error' => 'Song not found'], 404);
        }

        if (!LastfmService::isEnabled()) {
            Response::json(['error' => 'Last.fm API key is not configured (set LASTFM_API_KEY in .env)'], 400);
        }

        $url = AlbumArtService::fetch(
            (string)$tenant['slug'],
            (string)$song['artist'],
            (string)$song['title']
        );

        if (!$url) {
            Response::json([
                'error' => LastfmService::lastError() ?? 'No album art found for this track',
            ], 404);
        }

        SongService::setAlbumArt($db, $songId, $url);
        EventBus::publish($db, 'song:updated', ['songId' => $songId]);
        Response::json(['album_art_url' => $url]);
    }

    /**
     * Accept a remote image URL (e.g. from the Spotify-backed album-art JS
     * library on the frontend), download it, cache it locally, and update
     * the DB record.
     *
     * POST /api/admin/songs/{id}/cache-album-art-url
     * Body: { "url": "https://…" }
     *
     * @param array<string,mixed> $tenant
     */
    public static function cacheAlbumArtUrl(PDO $db, array $tenant, int $songId): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');

        $song = SongService::find($db, $songId);
        if (!$song) {
            Response::json(['error' => 'Song not found'], 404);
        }

        $input = Request::input();
        $remoteUrl = trim((string)($input['url'] ?? ''));
        if (!filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
            Response::json(['error' => 'A valid image URL is required'], 400);
        }

        $url = AlbumArtService::cacheRemoteUrl(
            (string)$tenant['slug'],
            (string)$song['artist'],
            (string)$song['title'],
            $remoteUrl
        );

        if (!$url) {
            Response::json(['error' => 'Failed to download or cache the image from the provided URL'], 502);
        }

        SongService::setAlbumArt($db, $songId, $url);
        EventBus::publish($db, 'song:updated', ['songId' => $songId]);
        Response::json(['album_art_url' => $url]);
    }

    /** @param array<string,mixed> $tenant */
    public static function exportCatalog(PDO $db, array $tenant): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $filename = preg_replace('/[^A-Za-z0-9_-]+/', '-', strtolower((string)$tenant['slug'])) . '-songs.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['title', 'artist', 'genre', 'decade', 'popularity', 'external_id', 'video_url', 'video_provider', 'provider_track_id', 'provider_url', 'lyrics_url'], ';');
        $stmt = $db->query('SELECT title, artist, genre, decade, popularity, external_id, video_url, video_provider, provider_track_id, provider_url, lyrics_url FROM songs WHERE is_active = 1 ORDER BY artist, title');
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            fputcsv($out, $row, ';');
        }
        fclose($out);
        exit;
    }
}
