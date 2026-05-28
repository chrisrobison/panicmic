-- Phase 7.4: billing scaffolding.
-- Tracks subscription state on the super plane. The actual Stripe
-- integration is added in a follow-up commit; this migration provides
-- the columns + plans table so the rest of the app can read
-- subscription status today.

CREATE TABLE IF NOT EXISTS plans (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  monthly_cents INT UNSIGNED NOT NULL DEFAULT 0,
  features JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MariaDB's JSON type is an alias for LONGTEXT with an implicit
-- JSON_VALID() check; explicit CAST(... AS JSON) is unsupported there.
-- A plain string literal works against both MariaDB and MySQL 8.
INSERT IGNORE INTO plans (code, name, monthly_cents, features) VALUES
  ('trial',  'Free trial',  0,    '{"max_singers_per_night": 50}'),
  ('starter','Starter',     1900, '{"max_singers_per_night": 150}'),
  ('pro',    'Pro',         4900, '{"max_singers_per_night": 500}');

ALTER TABLE tenants
  ADD COLUMN plan_code VARCHAR(40) NOT NULL DEFAULT 'trial' AFTER status,
  ADD COLUMN subscription_status ENUM('trialing','active','past_due','canceled') NOT NULL DEFAULT 'trialing' AFTER plan_code,
  ADD COLUMN trial_ends_at TIMESTAMP NULL DEFAULT NULL AFTER subscription_status,
  ADD COLUMN stripe_customer_id VARCHAR(80) NULL AFTER trial_ends_at,
  ADD COLUMN stripe_subscription_id VARCHAR(80) NULL AFTER stripe_customer_id;

-- Existing tenants are auto-upgraded to "active" so they don't get
-- blocked when the paywall ships. New self-serve signups get the
-- default 14-day trial below.
UPDATE tenants SET subscription_status = 'active' WHERE subscription_status = 'trialing';
