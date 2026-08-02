-- Free-text song for KJ-entered walk-up requests.
--
-- A walk-up singer at the mic frequently names a song that isn't in the
-- tenant catalog or the shared catalog. Until now song_id/shared_song_id
-- were the only ways to identify a request, so the KJ simply could not
-- enter that singer without first creating a catalog row mid-show.
--
-- Both existing FK columns are already nullable; these two carry the
-- title/artist when neither catalog matched. QueueService::queue() falls
-- back to them when resolving the display title, so the queue, the
-- displays, and the now-playing card all render a walk-up normally.

ALTER TABLE song_requests
  ADD COLUMN custom_song_title VARCHAR(200) NULL AFTER shared_song_id,
  ADD COLUMN custom_song_artist VARCHAR(200) NULL AFTER custom_song_title;
