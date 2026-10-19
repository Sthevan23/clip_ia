<?php
declare(strict_types=1);

/**
 * Testes offline de bootstrap/login inicial (SQLite temporário).
 * Uso: php tests/owner_bootstrap_offline.php [all|case_name]
 */

$case = $argv[1] ?? 'all';
$projectRoot = dirname(__DIR__);

function clipia_test_run_case(string $projectRoot, string $caseName, callable $fn): bool
{
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clipia_ob_' . bin2hex(random_bytes(4));
    mkdir($tempDir, 0700, true);
    $sqlitePath = $tempDir . DIRECTORY_SEPARATOR . 'test.sqlite';

    try {
        $ok = (bool)$fn($projectRoot, $tempDir, $sqlitePath);
        echo $caseName . '=' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
        return $ok;
    } finally {
        foreach (glob($tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tempDir);
    }
}

function clipia_test_bootstrap_env(string $tempDir, string $sqlitePath, string $boot, array $extra = []): string
{
    $base = [
        'APP_ENV'                             => 'development',
        'DB_DRIVER'                           => 'sqlite',
        'DB_SQLITE_PATH'                      => str_replace('\\', '/', $sqlitePath),
        'WORKER_SHARED_SECRET'                => 'offline-test-secret-min-16-chars',
        'CLIPIA_BOOTSTRAP_OWNER_ON_FIRST_LOGIN' => $boot,
        'ADMIN_OWNER_EMAIL'                   => '',
        'ADMIN_OWNER_PASSWORD_HASH'           => '',
        'ADMIN_OWNER_PASSWORD'                => '',
    ];
    $merged = array_merge($base, $extra);
    $lines = ["<?php", 'return ['];
    foreach ($merged as $k => $v) {
        $lines[] = '    ' . var_export($k, true) . ' => ' . var_export($v, true) . ',';
    }
    $lines[] = '];';
    $envFile = $tempDir . DIRECTORY_SEPARATOR . 'env.php';
    file_put_contents($envFile, implode(PHP_EOL, $lines));
    foreach ($merged as $k => $v) {
        $exported = (string)$v;
        putenv($k . '=' . $exported);
        $_ENV[$k] = $exported;
    }
    return $envFile;
}

function clipia_test_reset_app(string $projectRoot, string $envFile): void
{
    chdir($projectRoot);
    putenv('CLIPIA_ENV_FILE=' . $envFile);
    $_ENV['CLIPIA_ENV_FILE'] = $envFile;
    require_once $projectRoot . '/config/database.php';
    $pdo = Database::getConnection();
    if ($pdo === null) {
        throw new RuntimeException('PDO null');
    }
    Database::ensureSqliteSchema($pdo);
    require_once $projectRoot . '/includes/repositories.php';
}

$cases = [
    'bootstrap_disabled_no_create' => function ($root, $tempDir, $sqlitePath) {
        $env = clipia_test_bootstrap_env($tempDir, $sqlitePath, '0');
        clipia_test_reset_app($root, $env);
        $created = UserRepository::bootstrapOwnerFromFirstLoginIfAllowed('owner@test.example.com', 'SenhaForte123');
        return !$created && UserRepository::countUsers() === 0;
    },
    'bootstrap_enabled_creates_user' => function ($root, $tempDir, $sqlitePath) {
        $env = clipia_test_bootstrap_env($tempDir, $sqlitePath, '1');
        clipia_test_reset_app($root, $env);
        $created = UserRepository::bootstrapOwnerFromFirstLoginIfAllowed('owner@test.example.com', 'SenhaForte123');
        $user = UserRepository::findByEmail('owner@test.example.com');
        $hash = (string)($user['password_hash'] ?? '');
        return $created
            && UserRepository::countUsers() === 1
            && str_starts_with($hash, '$')
            && password_verify('SenhaForte123', $hash);
    },
    'bootstrap_skips_existing_user' => function ($root, $tempDir, $sqlitePath) {
        $env = clipia_test_bootstrap_env($tempDir, $sqlitePath, '1');
        clipia_test_reset_app($root, $env);
        UserRepository::bootstrapOwnerFromFirstLoginIfAllowed('first@test.example.com', 'SenhaForte123');
        $again = UserRepository::bootstrapOwnerFromFirstLoginIfAllowed('second@test.example.com', 'OutraSenha123');
        return !$again && UserRepository::countUsers() === 1;
    },
    'bootstrap_rejects_short_password' => function ($root, $tempDir, $sqlitePath) {
        $env = clipia_test_bootstrap_env($tempDir, $sqlitePath, '1');
        clipia_test_reset_app($root, $env);
        $created = UserRepository::bootstrapOwnerFromFirstLoginIfAllowed('owner@test.example.com', 'curta');
        return !$created && UserRepository::countUsers() === 0;
    },
    'ensure_owner_from_env_hash' => function ($root, $tempDir, $sqlitePath) {
        $hash = password_hash('EnvSenha123456', PASSWORD_DEFAULT);
        $env = clipia_test_bootstrap_env($tempDir, $sqlitePath, '0', [
            'ADMIN_OWNER_EMAIL'         => 'admin-env@test.example.com',
            'ADMIN_OWNER_PASSWORD_HASH' => $hash,
        ]);
        clipia_test_reset_app($root, $env);
        UserRepository::ensureOwnerAccountIfEmpty();
        $user = UserRepository::findByEmail('admin-env@test.example.com');
        return $user !== null && password_verify('EnvSenha123456', (string)$user['password_hash']);
    },
];

$failures = 0;
if ($case === 'all') {
    $self = __FILE__;
    foreach (array_keys($cases) as $name) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($self) . ' ' . escapeshellarg($name);
        passthru($cmd, $exitCode);
        if ($exitCode !== 0) {
            $failures++;
        }
    }
} elseif (isset($cases[$case])) {
    if (!clipia_test_run_case($projectRoot, $case, $cases[$case])) {
        $failures = 1;
    }
} else {
    fwrite(STDERR, "Caso desconhecido: {$case}\n");
    exit(2);
}

exit($failures === 0 ? 0 : 1);
