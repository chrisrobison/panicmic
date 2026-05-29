<?php

declare(strict_types=1);

namespace NextUp\Services;

use NextUp\Database\Connection;
use NextUp\Support\Env;
use PDO;

/**
 * Self-serve tenant signup. Creates the tenant + domain + invite in one
 * transaction; provisioning the per-tenant schema happens out-of-band
 * via scripts/migrate.php (or the super-admin "Provision" button) so a
 * web request never holds a long-running DDL connection open.
 */
final class SignupService
{
    public const SUBDOMAIN_PATTERN = '/^[a-z][a-z0-9-]{1,40}[a-z0-9]$/';

    /**
     * @param array{venue_name:string, email:string, subdomain:string, night_name?:string} $input
     * @return array{tenant_id:int, slug:string, invite_url:string}
     */
    public static function register(PDO $superDb, array $input): array
    {
        $venue = trim($input['venue_name']);
        $email = strtolower(trim($input['email']));
        $subdomain = strtolower(trim($input['subdomain']));
        $night = trim((string)($input['night_name'] ?? 'Karaoke Night'));

        if ($venue === '') {
            throw new \InvalidArgumentException('Venue name is required');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email is required');
        }
        if (!preg_match(self::SUBDOMAIN_PATTERN, $subdomain)) {
            throw new \InvalidArgumentException('Subdomain must be lowercase letters/digits/hyphens, 3-42 chars');
        }
        if (in_array($subdomain, ['www', 'mail', 'admin', 'super', 'api', 'app', 'static'], true)) {
            throw new \InvalidArgumentException('That subdomain is reserved');
        }

        $rootDomain = (string)(Env::get('SIGNUP_ROOT_DOMAIN', 'panicmic.com') ?? 'panicmic.com');
        $fullDomain = $subdomain . '.' . $rootDomain;
        $databaseName = 'nextup_' . str_replace('-', '_', $subdomain);

        // Reject duplicates up-front so users get a friendly error.
        $taken = $superDb->prepare('SELECT id FROM tenant_domains WHERE domain = ? LIMIT 1');
        $taken->execute([$fullDomain]);
        if ($taken->fetch()) {
            throw new \InvalidArgumentException('That subdomain is already taken');
        }
        $takenSlug = $superDb->prepare('SELECT id FROM tenants WHERE slug = ? LIMIT 1');
        $takenSlug->execute([$subdomain]);
        if ($takenSlug->fetch()) {
            throw new \InvalidArgumentException('That subdomain is already taken');
        }

        $superDb->beginTransaction();
        try {
            $stmt = $superDb->prepare(
                "INSERT INTO tenants
                 (slug, venue_name, night_name, database_name, signup_mode, status,
                  plan_code, subscription_status, trial_ends_at,
                  public_request_url, projection_url)
                 VALUES (?, ?, ?, ?, 'both', 'provisioning',
                         'trial', 'trialing', DATE_ADD(NOW(), INTERVAL 14 DAY),
                         ?, ?)"
            );
            $publicUrl = "https://{$fullDomain}/";
            $projUrl = "https://{$fullDomain}/display";
            $stmt->execute([$subdomain, $venue, $night, $databaseName, $publicUrl, $projUrl]);
            $tenantId = (int)$superDb->lastInsertId();

            $superDb->prepare('INSERT INTO tenant_domains (tenant_id, domain, is_primary) VALUES (?, ?, 1)')
                ->execute([$tenantId, $fullDomain]);

            $token = bin2hex(random_bytes(32));
            $superDb->prepare(
                'INSERT INTO tenant_invites (tenant_id, email, token, expires_at)
                 VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))'
            )->execute([$tenantId, $email, $token]);

            $superDb->commit();
        } catch (\Throwable $e) {
            $superDb->rollBack();
            throw $e;
        }

        // Auto-provision the tenant database so the invite acceptance
        // step can connect immediately. Failure is non-fatal — the
        // tenant row stays in status='provisioning' and a super-admin
        // can retry via the dashboard. This is the PLAN.md Phase 7
        // automation gap the audit flagged.
        $provisioned = false;
        try {
            TenantProvisioner::provision([
                'slug' => $subdomain,
                'database_name' => $databaseName,
            ]);
            $provisioned = true;
        } catch (\Throwable $e) {
            // Log into the structured error stream but don't bubble — we
            // still want to send the invite so the operator isn't stranded.
            \NextUp\Support\ErrorReporter::report($e, "signup auto-provision failed for {$subdomain}");
        }

        if ($provisioned) {
            // Flip out of 'provisioning'. We hold off on 'active' until
            // the invite is accepted (an admin user actually exists);
            // 'provisioning' was the wrong terminal state once the DB
            // exists. Re-use the existing status vocabulary: leave the
            // row as 'provisioning' if you want super-admin gating, or
            // pre-activate if not. We pre-activate so the new tenant
            // can log in immediately after accepting the invite.
            $superDb->prepare("UPDATE tenants SET status = 'active' WHERE id = ?")
                ->execute([$tenantId]);
        }

        $inviteUrl = "https://{$fullDomain}/signup/accept?token=" . urlencode($token);
        self::sendInvite($email, $venue, $inviteUrl);

        return [
            'tenant_id' => $tenantId,
            'slug' => $subdomain,
            'invite_url' => $inviteUrl,
            'provisioned' => $provisioned,
        ];
    }

    private static function sendInvite(string $email, string $venue, string $inviteUrl): void
    {
        Mailer::send(
            $email,
            "Activate your NextUp account for {$venue}",
            "Welcome to NextUp.\n\n"
                . "Your venue '{$venue}' is being set up. Click the link below to set your password and start hosting karaoke nights:\n\n"
                . "  {$inviteUrl}\n\n"
                . "This link expires in 7 days.\n",
        );
    }

    /** @return array<string,mixed>|null */
    public static function findInvite(PDO $superDb, string $token): ?array
    {
        $stmt = $superDb->prepare(
            'SELECT i.*, t.slug, t.venue_name, t.database_name
             FROM tenant_invites i
             JOIN tenants t ON t.id = i.tenant_id
             WHERE i.token = ?
               AND i.expires_at > NOW()
               AND i.accepted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed> */
    public static function acceptInvite(PDO $superDb, string $token, string $displayName, string $password): array
    {
        $invite = self::findInvite($superDb, $token);
        if (!$invite) {
            throw new \InvalidArgumentException('Invite is invalid or expired');
        }
        if (strlen($password) < 10) {
            throw new \InvalidArgumentException('Password must be at least 10 characters');
        }

        $tenantDb = Connection::tenant((string)$invite['database_name']);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $tenantDb->prepare(
            "INSERT INTO users (email, password_hash, display_name, role, is_active)
             VALUES (?, ?, ?, 'tenant_admin', 1)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), display_name = VALUES(display_name), role = VALUES(role), is_active = 1"
        )->execute([$invite['email'], $hash, $displayName]);

        $superDb->prepare('UPDATE tenant_invites SET accepted_at = NOW() WHERE id = ?')->execute([(int)$invite['id']]);
        $superDb->prepare("UPDATE tenants SET status = 'active' WHERE id = ?")->execute([(int)$invite['tenant_id']]);

        return [
            'tenant_id' => (int)$invite['tenant_id'],
            'slug' => $invite['slug'],
            'email' => $invite['email'],
        ];
    }
}
