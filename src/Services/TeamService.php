<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PDO;

final class TeamService
{
    /** @return list<array<string,mixed>> */
    public static function list(PDO $db): array
    {
        return $db->query(
            "SELECT id, email, display_name, role, is_active, created_at, updated_at
             FROM users
             WHERE role IN ('kj', 'tenant_admin')
             ORDER BY is_active DESC, display_name ASC"
        )->fetchAll();
    }

    /** @param array<string,mixed> $data @return array{user_id:int,token:string} */
    public static function create(PDO $db, array $data): array
    {
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $displayName = trim((string)($data['display_name'] ?? ''));
        $role = self::role((string)($data['role'] ?? 'kj'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            throw new \InvalidArgumentException('Enter a valid email address');
        }
        if ($displayName === '' || strlen($displayName) > 160) {
            throw new \InvalidArgumentException('Display name is required');
        }

        try {
            $db->prepare(
                'INSERT INTO users (email, password_hash, display_name, role, is_active)
                 VALUES (?, ?, ?, ?, 1)'
            )->execute([
                $email,
                password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                $displayName,
                $role,
            ]);
        } catch (\PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                throw new \InvalidArgumentException('A team member already uses that email');
            }
            throw $error;
        }
        $userId = (int)$db->lastInsertId();
        return ['user_id' => $userId, 'token' => PasswordResetService::issue($db, $userId, 604800)];
    }

    /** @param array<string,mixed> $data */
    public static function update(PDO $db, int $userId, array $data, int $actorId): bool
    {
        $stmt = $db->prepare(
            "SELECT id, role, is_active FROM users
             WHERE id = ? AND role IN ('kj', 'tenant_admin') LIMIT 1"
        );
        $stmt->execute([$userId]);
        $current = $stmt->fetch();
        if (!$current) {
            return false;
        }

        $displayName = trim((string)($data['display_name'] ?? ''));
        $role = self::role((string)($data['role'] ?? $current['role']));
        $active = array_key_exists('is_active', $data) ? !empty($data['is_active']) : (bool)$current['is_active'];
        if ($displayName === '' || strlen($displayName) > 160) {
            throw new \InvalidArgumentException('Display name is required');
        }
        if ($userId === $actorId && (!$active || $role !== 'tenant_admin')) {
            throw new \InvalidArgumentException('You cannot deactivate or demote your own administrator account');
        }
        if ((string)$current['role'] === 'tenant_admin'
            && (int)$current['is_active'] === 1
            && (!$active || $role !== 'tenant_admin')
            && self::activeAdminCount($db) <= 1
        ) {
            throw new \InvalidArgumentException('At least one active administrator is required');
        }

        $db->prepare(
            'UPDATE users SET display_name = ?, role = ?, is_active = ? WHERE id = ?'
        )->execute([$displayName, $role, $active ? 1 : 0, $userId]);
        return true;
    }

    public static function activeAdminCount(PDO $db): int
    {
        return (int)$db->query(
            "SELECT COUNT(*) FROM users WHERE role = 'tenant_admin' AND is_active = 1"
        )->fetchColumn();
    }

    private static function role(string $role): string
    {
        if (!in_array($role, ['kj', 'tenant_admin'], true)) {
            throw new \InvalidArgumentException('Role must be KJ or administrator');
        }
        return $role;
    }
}
