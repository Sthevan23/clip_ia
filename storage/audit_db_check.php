<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/repositories.php';
UserRepository::ensureOwnerAccountIfEmpty();
$pdo = Database::getConnection();
if ($pdo === null) {
    echo "DB_FAIL\n";
    exit(1);
}
echo 'DB_OK driver=' . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
if (!$tables && strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql') {
    echo "DB_OK mysql_connected\n";
    exit(0);
}
echo 'TABLES=' . implode(',', $tables ?: []) . "\n";
