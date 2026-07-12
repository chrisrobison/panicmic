-- Migration 014: local video cache for karaoke playback.
--
-- Rather than relying solely on the YouTube iframe embed at showtime, a
-- background worker (scripts/download-video.php, spawned via yt-dlp) can
-- mirror a request's YouTube video to this tenant's content directory once
-- it's promoted to up_next/now_singing, so playback runs off our own
-- <video> element instead of an embedded iframe. The cached file is
-- deleted again once the request leaves the stage (completed/skipped/
-- canceled) — see VideoCacheService::cleanup().
--
-- Once-only: idempotency is enforced by the schema_migrations ledger in
-- scripts/migrate.php.

ALTER TABLE song_requests
  ADD COLUMN cached_video_status ENUM('none','pending','ready','failed') NOT NULL DEFAULT 'none' AFTER is_priority,
  ADD COLUMN cached_video_path VARCHAR(512) NULL AFTER cached_video_status,
  ADD COLUMN cached_video_requested_at DATETIME NULL AFTER cached_video_path;
