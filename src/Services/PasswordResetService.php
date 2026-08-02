<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PDO;

final class PasswordResetService
{
    public const MIN_PASSWORD_LENGTH = 12;

    /**
     * Creates a one-time reset token. Only the SHA-256 digest is persisted;
     * the raw token exists long enough to be placed in the email.
     */
    public static function issue(PDO $db, int $userId, int $ttlSeconds = 3600): string
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);

        $db->beginTransaction();
        try {
            $db->prepare(
                'UPDATE password_reset_tokens
                 SET used_at = NOW()
                 WHERE user_id = ? AND used_at IS NULL'
            )->execute([$userId]);
            $expiresAt = date('Y-m-d H:i:s', time() + max(300, $ttlSeconds));
            $db->prepare(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                 VALUES (?, ?, ?)'
            )->execute([$userId, $hash, $expiresAt]);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }

        return $token;
    }

    /**
     * Sends a reset message when the address belongs to active staff.
     * The return value deliberately does not reveal whether it matched.
     */
    public static function request(PDO $db, string $email, string $resetBaseUrl): void
    {
        $stmt = $db->prepare(
            "SELECT id, email, display_name
             FROM users
             WHERE email = ? AND is_active = 1 AND role IN ('kj', 'tenant_admin')
             LIMIT 1"
        );
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();
        if (!$user) {
            return;
        }

        $token = self::issue($db, (int)$user['id']);
        $link = rtrim($resetBaseUrl, '/') . '/admin/reset-password?token=' . rawurlencode($token);
        Mailer::send(
            (string)$user['email'],
            'Reset your PanicMic password',
            "Hi {$user['display_name']},\n\n"
                . "Use this one-time link to choose a new PanicMic password:\n\n"
                . "{$link}\n\n"
                . "The link expires in one hour. If you did not request this, you can ignore this email.\n",
        );
    }

    public static function reset(PDO $db, string $token, string $password): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        self::validatePassword($password);
        $hash = hash('sha256', $token);

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT prt.id, prt.user_id
                 FROM password_reset_tokens prt
                 JOIN users u ON u.id = prt.user_id AND u.is_active = 1
                 WHERE prt.token_hash = ? AND prt.used_at IS NULL AND prt.expires_at > NOW()
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$hash]);
            $row = $stmt->fetch();
            if (!$row) {
                $db->rollBack();
                return false;
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare(
                'UPDATE users SET password_hash = ? WHERE id = ? AND is_active = 1'
            )->execute([$passwordHash, (int)$row['user_id']]);
            $db->prepare(
                'UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
            )->execute([(int)$row['user_id']]);
            $db->commit();
            return true;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    public static function validatePassword(string $password): void
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException(
                'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters'
            );
        }
        if (strlen($password) > 4096) {
            throw new \InvalidArgumentException('Password is too long');
        }
    }
}
