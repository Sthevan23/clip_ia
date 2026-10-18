<?php
declare(strict_types=1);

/**
 * CLI: importa o resultado real do teste gratuito para o banco SQLite/MySQL local.
 *
 * Uso (na raiz do projeto):
 *   php storage/import_audit_demo.php
 *   php storage/import_audit_demo.php worker_test@clipia.local
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Execute apenas via linha de comando.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

require_once $projectRoot . '/includes/repositories.php';
require_once $projectRoot . '/includes/audit_demo_importer.php';

UserRepository::ensureOwnerAccountIfEmpty();

$emailArg = $argv[1] ?? null;
$user = null;
if (is_string($emailArg) && trim($emailArg) !== '') {
    $user = UserRepository::findByEmail(trim($emailArg));
    if ($user === null) {
        fwrite(STDERR, "Usuário não encontrado: {$emailArg}\n");
        exit(1);
    }
} else {
    $pdo = Database::getConnection();
    if ($pdo === null) {
        fwrite(STDERR, Database::getPublicErrorMessage() . "\n");
        exit(1);
    }
    $row = $pdo->query('SELECT id, email FROM users ORDER BY id ASC LIMIT 1')->fetch();
    if (!$row) {
        fwrite(STDERR, "Nenhum usuário no banco. Faça login uma vez ou defina ADMIN_OWNER_*.\n");
        exit(1);
    }
    $user = $row;
}

$userId = (int)$user['id'];

try {
    $result = AuditDemoImporter::importForUser($userId);
    $baseUrl = clipia_env('CLIPIA_PUBLIC_BASE_URL', 'http://127.0.0.1:8080');
    $viewUrl = rtrim($baseUrl, '/') . '/' . ltrim($result['view_path'], '/');

    echo "OK audit_demo_import\n";
    echo "user_id={$userId} email=" . ($user['email'] ?? '') . "\n";
    echo 'project_id=' . $result['project_id'] . ' candidates=' . count($result['candidate_ids']) . "\n";
    echo 'created=' . ($result['created'] ? '1' : '0') . "\n";
    echo "view_url={$viewUrl}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}
