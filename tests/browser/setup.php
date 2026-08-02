<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if (!defined('PANICMIC_TEST_DB_AVAILABLE')) {
    fwrite(STDERR, "Browser test database is unavailable.\n");
    exit(1);
}

/** @var PDO $rootPdo */
$tenant = connectTestDb($rootPdo, TEST_TENANT_DB);
$tenant->exec(
    "INSERT INTO users (email, password_hash, display_name, role, is_active)
     VALUES ('owner@test.local', '" . password_hash('browser-test-password', PASSWORD_DEFAULT) . "', 'Test Owner', 'tenant_admin', 1)"
);
$tenant->exec(
    "INSERT INTO songs (title, artist, genre, decade, popularity)
     VALUES ('Browser Anthem', 'Test Artist', 'Pop', 2020, 100)"
);
$tenant->exec(
    "INSERT INTO karaoke_sessions (name, starts_at, status)
     VALUES ('Browser Test Show', NOW(), 'live')"
);
$sessionId = (int)$tenant->lastInsertId();
$tenant->prepare(
    "INSERT INTO display_state (session_id, screen, mode) VALUES (?, 'main', 'idle')"
)->execute([$sessionId]);

echo "Browser test fixture ready.\n";
