<?php
declare(strict_types=1);

/**
 * ClipIA - Conexão PDO com MySQL
 * Preparado para Hostinger Business (MySQL via PDO) com suporte a variáveis de ambiente
 * ou arquivo protegido `config/env.local.php`, sem expor credenciais no código ou erros ao usuário.
 */

require_once __DIR__ . '/app.php';

class Database
{
    private static ?PDO $instance = null;
    private static bool $connectionFailed = false;

    /**
     * Retorna as configurações de conexão obtidas de variáveis de ambiente ou de config/env.local.php.
     */
    private static function getConfig(): array
    {
        $dbName = clipia_env('DB_NAME', '');
        $defaultDriver = (defined('APP_ENV') && APP_ENV !== 'production' && $dbName === '')
            ? 'sqlite'
            : 'mysql';
        $defaultSqlitePath = (defined('APP_ENV') && APP_ENV !== 'production')
            ? (dirname(__DIR__) . '/storage/clipia_local.sqlite')
            : '';

        return [
            'driver'   => clipia_env('DB_DRIVER', $defaultDriver),
            'host'     => clipia_env('DB_HOST', '127.0.0.1'),
            'port'     => clipia_env('DB_PORT', '3306'),
            'dbname'   => $dbName,
            'username' => clipia_env('DB_USER', ''),
            'password' => clipia_env('DB_PASS', ''),
            'charset'  => 'utf8mb4',
            'sqlite'   => clipia_env('DB_SQLITE_PATH', $defaultSqlitePath),
        ];
    }

    /**
     * Garante que o banco SQLite local possua todas as tabelas necessárias para desenvolvimento/testes.
     */
    public static function ensureSqliteSchema(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                plan_tier TEXT NOT NULL DEFAULT 'starter',
                default_aspect_ratio TEXT NOT NULL DEFAULT '9:16',
                default_clips_count INTEGER NOT NULL DEFAULT 5,
                default_min_duration INTEGER NOT NULL DEFAULT 30,
                default_max_duration INTEGER NOT NULL DEFAULT 60,
                email_notifications INTEGER NOT NULL DEFAULT 1,
                accepted_terms_at TEXT NULL,
                last_login_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                target_clips_count INTEGER NOT NULL DEFAULT 5,
                min_duration_seconds INTEGER NOT NULL DEFAULT 30,
                max_duration_seconds INTEGER NOT NULL DEFAULT 60,
                aspect_ratio TEXT NOT NULL DEFAULT '9:16',
                subtitle_config_json TEXT NULL,
                authorized_source_confirmed INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT 'awaiting_source',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS source_videos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                provider TEXT NOT NULL DEFAULT 'youtube',
                source_mode TEXT NOT NULL DEFAULT 'url_only',
                source_url TEXT NOT NULL,
                external_video_id TEXT NOT NULL,
                video_title TEXT NULL,
                author_name TEXT NULL,
                thumbnail_url TEXT NULL,
                embed_url TEXT NULL,
                embed_allowed INTEGER NOT NULL DEFAULT 1,
                uploaded_file_path TEXT NULL,
                uploaded_file_name TEXT NULL,
                uploaded_file_mime TEXT NULL,
                uploaded_file_size INTEGER NULL,
                duration_seconds INTEGER NULL,
                created_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS clip_candidates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                source_video_id INTEGER NULL,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                short_description TEXT NULL,
                hashtags TEXT NULL,
                category TEXT NOT NULL DEFAULT 'explicacao',
                start_time_seconds REAL NOT NULL DEFAULT 0,
                end_time_seconds REAL NOT NULL DEFAULT 30,
                duration_seconds REAL NOT NULL DEFAULT 30,
                aspect_ratio TEXT NOT NULL DEFAULT '9:16',
                summary TEXT NULL,
                rationale TEXT NULL,
                transcript_excerpt TEXT NULL,
                relevance_score INTEGER NOT NULL DEFAULT 70,
                storage_url TEXT NULL,
                srt_storage_url TEXT NULL,
                srt_content TEXT NULL,
                subtitles_burned INTEGER NOT NULL DEFAULT 1,
                subtitle_style_json TEXT NULL,
                status TEXT NOT NULL DEFAULT 'candidate',
                created_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS processing_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                job_type TEXT NOT NULL DEFAULT 'find_highlights',
                external_job_id TEXT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 3,
                payload_json TEXT NULL,
                result_summary_json TEXT NULL,
                error_message TEXT NULL,
                started_at TEXT NULL,
                finished_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS usage_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                project_id INTEGER NULL,
                action_type TEXT NOT NULL,
                description TEXT NOT NULL,
                units_consumed INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS monthly_budgets (
                month_key TEXT PRIMARY KEY,
                spent_brl REAL NOT NULL DEFAULT 0.0,
                reserved_brl REAL NOT NULL DEFAULT 0.0,
                calls_count INTEGER NOT NULL DEFAULT 0,
                prompt_tokens INTEGER NOT NULL DEFAULT 0,
                completion_tokens INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS budget_reservations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reservation_token TEXT NOT NULL UNIQUE,
                month_key TEXT NOT NULL,
                job_id INTEGER NOT NULL,
                project_id INTEGER NOT NULL,
                reserved_brl REAL NOT NULL DEFAULT 0.0,
                settled_brl REAL NULL,
                status TEXT NOT NULL DEFAULT 'reserved',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
        ");
        // Garante colunas de legendas e metadados em bancos SQLite criados anteriormente
        try {
            $projCols = $pdo->query("PRAGMA table_info(projects)")->fetchAll();
            $projColNames = array_map(static fn($c) => (string)($c['name'] ?? ''), $projCols);
            if (!in_array('subtitle_config_json', $projColNames, true)) {
                $pdo->exec("ALTER TABLE projects ADD COLUMN subtitle_config_json TEXT NULL;");
            }

            $cols = $pdo->query("PRAGMA table_info(clip_candidates)")->fetchAll();
            $candColNames = array_map(static fn($c) => (string)($c['name'] ?? ''), $cols);
            $candMigrations = [
                'storage_url'         => 'TEXT NULL',
                'short_description'   => 'TEXT NULL',
                'hashtags'            => 'TEXT NULL',
                'srt_storage_url'     => 'TEXT NULL',
                'srt_content'         => 'TEXT NULL',
                'subtitles_burned'    => 'INTEGER NOT NULL DEFAULT 1',
                'subtitle_style_json' => 'TEXT NULL',
            ];
            foreach ($candMigrations as $colName => $colDef) {
                if (!in_array($colName, $candColNames, true)) {
                    $pdo->exec("ALTER TABLE clip_candidates ADD COLUMN {$colName} {$colDef};");
                }
            }
        } catch (Throwable $e) {
            // Ignora se já existir
        }
    }

    /**
     * Obtém uma instância PDO ativa usando prepared statements nativos.
     */
    public static function getConnection(): ?PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $cfg = self::getConfig();

        try {
            if ($cfg['driver'] === 'sqlite' && $cfg['sqlite'] !== '') {
                $sqliteDir = dirname($cfg['sqlite']);
                if (!is_dir($sqliteDir)) {
                    @mkdir($sqliteDir, 0750, true);
                }
                $dsn = 'sqlite:' . $cfg['sqlite'];
                self::$instance = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                self::ensureSqliteSchema(self::$instance);
                self::$connectionFailed = false;
                return self::$instance;
            }

            if ($cfg['dbname'] === '' || $cfg['username'] === '') {
                self::$connectionFailed = true;
                return null;
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $cfg['host'],
                $cfg['port'],
                $cfg['dbname'],
                $cfg['charset']
            );

            self::$instance = new PDO($dsn, $cfg['username'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
            self::$connectionFailed = false;
            return self::$instance;
        } catch (PDOException $e) {
            // Registra no log interno do servidor sem jamais expor credenciais ou detalhes do banco na tela
            error_log('[ClipIA Database Error] Falha ao conectar ao banco de dados.');
            self::$connectionFailed = true;
            return null;
        }
    }

    /**
     * Permite injetar ou redefinir a conexão em testes automatizados.
     */
    public static function setConnection(?PDO $pdo): void
    {
        self::$instance = $pdo;
        self::$connectionFailed = ($pdo === null);
    }

    /**
     * Informa se o banco de dados está acessível no momento.
     */
    public static function isConnected(): bool
    {
        return self::getConnection() !== null;
    }

    /**
     * Mensagem amigável e segura para o usuário caso o banco ainda não esteja configurado.
     */
    public static function getPublicErrorMessage(): string
    {
        return 'O serviço de banco de dados está temporariamente indisponível ou aguarda configuração. Verifique as instruções de instalação.';
    }
}
