<?php

declare(strict_types=1);

use PanicMic\Database\Connection;
use PanicMic\Services\ContentService;
use PanicMic\Support\Env;

require dirname(__DIR__) . '/src/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

// seed.php does DDL (CREATE DATABASE, CREATE TABLE via migrations) so
// always use the elevated provisioning credential.
$superDbName = (string)(Env::get('SUPER_DB_NAME', 'panicmic_super') ?? 'panicmic_super');
$super = Connection::provisioner($superDbName);

/** Apply every tenant migration in order to a fresh tenant DB. */
function applyAllTenantMigrations(PDO $db): void
{
    $files = glob(dirname(__DIR__) . '/migrations/tenant/*.sql') ?: [];
    sort($files, SORT_STRING);
    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Unable to read {$file}");
        }
        $db->exec($sql);
    }
}

$superPassword = password_hash('password123', PASSWORD_DEFAULT);
$super->prepare('INSERT IGNORE INTO super_admin_users (email, password_hash, display_name) VALUES (?, ?, ?)')
    ->execute(['super@panicmic.com', $superPassword, 'Super Admin']);

$tenants = [
    [
        'slug' => 'bluebird',
        'venue_name' => 'Bluebird Bar',
        'night_name' => 'Bluebird Karaoke',
        'database_name' => 'panicmic_bluebird',
        'domain' => 'bluebird.panicmic.com',
        'aliases' => ['bluebird.local'],
        'primary_color' => '#23d18b',
        'accent_color' => '#ffd166',
    ],
    [
        'slug' => 'neon',
        'venue_name' => 'Neon Room',
        'night_name' => 'Neon Karaoke Club',
        'database_name' => 'panicmic_neon',
        'domain' => 'neon.panicmic.com',
        'aliases' => ['neon.local'],
        'primary_color' => '#38bdf8',
        'accent_color' => '#f472b6',
    ],
];

foreach ($tenants as $tenant) {
    $stmt = $super->prepare(
        'INSERT INTO tenants (slug, venue_name, night_name, database_name, primary_color, accent_color, public_request_url, projection_url)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE venue_name = VALUES(venue_name), night_name = VALUES(night_name)'
    );
    $stmt->execute([
        $tenant['slug'],
        $tenant['venue_name'],
        $tenant['night_name'],
        $tenant['database_name'],
        $tenant['primary_color'],
        $tenant['accent_color'],
        "http://{$tenant['domain']}:8000/",
        "http://{$tenant['domain']}:8000/display",
    ]);
    $tenantId = (int)$super->query("SELECT id FROM tenants WHERE slug = " . $super->quote($tenant['slug']))->fetchColumn();
    $super->prepare('INSERT IGNORE INTO tenant_domains (tenant_id, domain, is_primary) VALUES (?, ?, 1)')
        ->execute([$tenantId, $tenant['domain']]);
    foreach ($tenant['aliases'] as $alias) {
        $super->prepare('INSERT IGNORE INTO tenant_domains (tenant_id, domain, is_primary) VALUES (?, ?, 0)')
            ->execute([$tenantId, $alias]);
    }

    $super->exec("CREATE DATABASE IF NOT EXISTS `{$tenant['database_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    // Tenant schema migrations run as the provisioner; the runtime user
    // (panicmic) lacks CREATE TABLE.
    $db = Connection::provisioner($tenant['database_name']);
    applyAllTenantMigrations($db);

    $adminEmail = "admin@{$tenant['domain']}";
    $adminPassword = password_hash('password123', PASSWORD_DEFAULT);
    $db->prepare('INSERT IGNORE INTO users (email, password_hash, display_name, role) VALUES (?, ?, ?, ?)')
        ->execute([$adminEmail, $adminPassword, 'KJ Admin', 'tenant_admin']);
    seedSongs($db);
    // MariaDB doesn't support CAST(... AS JSON); plain string literals
    // satisfy the implicit JSON_VALID() check on JSON-typed columns.
    $db->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('prevent_duplicate_requests', 'true')")->execute();
    ContentService::ensureTenantDirectory($tenant['slug']);
}

echo "Seeded super admin and demo tenants.\n";

function seedSongs(PDO $db): void
{
    $songs = [
        ['Don\'t Stop Believin\'', 'Journey', 'Rock', 1980, 98],
        ['Mr. Brightside', 'The Killers', 'Rock', 2000, 97],
        ['I Wanna Dance with Somebody', 'Whitney Houston', 'Pop', 1980, 96],
        ['Before He Cheats', 'Carrie Underwood', 'Country', 2000, 92],
        ['No Scrubs', 'TLC', 'R&B', 1990, 89],
        ['Wonderwall', 'Oasis', 'Rock', 1990, 88],
        ['Shallow', 'Lady Gaga & Bradley Cooper', 'Pop', 2010, 91],
        ['Sweet Caroline', 'Neil Diamond', 'Pop', 1960, 95],
        ['Bohemian Rhapsody', 'Queen', 'Rock', 1970, 99],
        ['Uptown Funk', 'Mark Ronson feat. Bruno Mars', 'Pop', 2010, 94],
        ['Friends in Low Places', 'Garth Brooks', 'Country', 1990, 90],
        ['Valerie', 'Amy Winehouse', 'R&B', 2000, 87],
    ];
    $stmt = $db->prepare(
        'INSERT INTO songs (title, artist, genre, decade, popularity)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE popularity = VALUES(popularity)'
    );
    foreach ($songs as $song) {
        $stmt->execute($song);
    }
}
