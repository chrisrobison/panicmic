-- Phase 2.1: DB-backed login rate limiter.
-- Records each attempt by (remote_ip, email) bucket so we can throttle
-- credential stuffing without depending on session cookies.

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  bucket VARCHAR(190) NOT NULL,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempts_bucket_time (bucket, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
