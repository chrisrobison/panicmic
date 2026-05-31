-- 007_standard_plan.sql
--
-- The product is sold to professional KJs. Pricing: a single Standard
-- plan at $9/month that includes up to 5 venues and 1 KJ user, plus
-- $2.50/month for each additional KJ user. The per-KJ overage is metered
-- read-only for now (see BillingService); the venue cap is enforced at
-- venue-create time.
--
-- The features JSON carries the plan limits the app reads:
--   max_venues          — hard cap on active venues (enforced).
--   included_kj         — KJ seats bundled in the base price.
--   additional_kj_cents — monthly price per KJ seat beyond included_kj.
--
-- MariaDB's JSON type is an alias for LONGTEXT with an implicit
-- JSON_VALID() check; a plain string literal works on MariaDB and MySQL 8.
INSERT IGNORE INTO plans (code, name, monthly_cents, features) VALUES
  ('standard', 'Standard', 900, '{"max_venues": 5, "included_kj": 1, "additional_kj_cents": 250}');
