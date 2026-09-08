<?php

declare(strict_types=1);

/**
 * Test-only fixture for the existing tenant 54 database (`akira`).
 * Run explicitly; this is not part of tenant provisioning. P3 should add governed content-role seeding.
 */
chdir(dirname(__DIR__, 2));
require_once 'bootstrap.php';

$config = require 'config/database.php';
$db = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=akira;charset=utf8mb4', $config['host'], $config['port']),
    (string) $config['username'],
    (string) $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$db->exec("ALTER TABLE users MODIFY role ENUM('contributor','author','editor','admin','administrator','superadmin','manager','viewer') NOT NULL DEFAULT 'viewer'");
$db->exec(<<<'SQL'
INSERT INTO users (username, email, password_hash, full_name, role, is_active, token_version)
SELECT 'akiraauthor', 'akiraauthor@example.com', password_hash, 'Akira Author (R2 test fixture)', 'author', 1, 0
FROM users WHERE username = 'charlienacario884'
ON DUPLICATE KEY UPDATE email = VALUES(email), password_hash = VALUES(password_hash),
    full_name = VALUES(full_name), role = VALUES(role), is_active = 1
SQL);

if ($db->query("SELECT COUNT(*) FROM users WHERE username = 'akiraauthor' AND role = 'author'")->fetchColumn() !== 1) {
    throw new RuntimeException('Unable to seed the Akira author fixture from the tenant administrator.');
}

echo "Seeded tenant 54 fixture: akiraauthor (author)\n";
