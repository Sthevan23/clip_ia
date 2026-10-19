-- ============================================================================
-- ClipIA — Script Reproduzível de Instalação do Banco de Dados MySQL
-- Compatível com MySQL 8.0+ / MariaDB 10.6+ (Hostinger Business)
--
-- ORDEM DE INSTALAÇÃO DAS TABELAS (respeitando chaves estrangeiras):
--   1. users
--   2. projects
--   3. source_videos
--   4. clip_candidates
--   5. processing_jobs
--   6. usage_logs
--   7. monthly_budgets
--   8. budget_reservations
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '-03:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. Tabela: users
-- Armazena contas de usuários, senhas com hash (password_hash) e preferências.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `email` VARCHAR(190) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `plan_tier` VARCHAR(40) NOT NULL DEFAULT 'starter',
    `default_aspect_ratio` VARCHAR(10) NOT NULL DEFAULT '9:16',
    `default_clips_count` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `default_min_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `default_max_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    `email_notifications` TINYINT(1) NOT NULL DEFAULT 1,
    `accepted_terms_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. Tabela: projects
-- Armazena os projetos criados e pertence exclusivamente ao usuário (user_id).
-- Estados oficiais:
--   - awaiting_source     (Aguardando fonte autorizada)
--   - awaiting_processing (Aguardando processamento)
--   - processing          (Processando)
--   - completed           (Concluído)
--   - failed              (Falhou)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `projects` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `target_clips_count` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `min_duration_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `max_duration_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    `aspect_ratio` VARCHAR(10) NOT NULL DEFAULT '9:16',
    `subtitle_config_json` TEXT DEFAULT NULL,
    `authorized_source_confirmed` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('awaiting_source', 'awaiting_processing', 'processing', 'completed', 'failed', 'draft', 'queued') NOT NULL DEFAULT 'awaiting_source',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_projects_user_created` (`user_id`, `created_at`),
    KEY `idx_projects_user_status` (`user_id`, `status`),
    CONSTRAINT `fk_projects_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. Tabela: source_videos
-- Armazena a referência do vídeo do YouTube e eventual arquivo de vídeo
-- autorizado enviado pelo usuário quando a importação por URL não se aplica.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `source_videos` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `provider` VARCHAR(32) NOT NULL DEFAULT 'youtube',
    `source_mode` VARCHAR(32) NOT NULL DEFAULT 'url_only',
    `source_url` VARCHAR(500) NOT NULL,
    `external_video_id` VARCHAR(64) NOT NULL,
    `video_title` VARCHAR(255) DEFAULT NULL,
    `author_name` VARCHAR(180) DEFAULT NULL,
    `thumbnail_url` VARCHAR(500) DEFAULT NULL,
    `embed_url` VARCHAR(500) DEFAULT NULL,
    `embed_allowed` TINYINT(1) NOT NULL DEFAULT 1,
    `uploaded_file_path` VARCHAR(500) DEFAULT NULL,
    `uploaded_file_name` VARCHAR(255) DEFAULT NULL,
    `uploaded_file_mime` VARCHAR(100) DEFAULT NULL,
    `uploaded_file_size` BIGINT UNSIGNED DEFAULT NULL,
    `authorized_media_url` VARCHAR(500) DEFAULT NULL,
    `duration_seconds` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_source_videos_project` (`project_id`),
    KEY `idx_source_videos_user` (`user_id`),
    KEY `idx_source_videos_ext_id` (`external_video_id`),
    CONSTRAINT `fk_source_videos_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_source_videos_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. Tabela: clip_candidates
-- Armazena os candidatos de cortes analisados (início, fim, título, resumo,
-- justificativa, transcrição, categoria e pontuação estimada de interesse).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clip_candidates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_id` BIGINT UNSIGNED NOT NULL,
    `source_video_id` BIGINT UNSIGNED DEFAULT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `short_description` TEXT DEFAULT NULL,
    `hashtags` TEXT DEFAULT NULL,
    `category` VARCHAR(60) NOT NULL DEFAULT 'explicacao',
    `start_time_seconds` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `end_time_seconds` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `duration_seconds` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `aspect_ratio` VARCHAR(10) NOT NULL DEFAULT '9:16',
    `summary` TEXT DEFAULT NULL,
    `rationale` TEXT DEFAULT NULL,
    `transcript_excerpt` TEXT DEFAULT NULL,
    `relevance_score` DECIMAL(5,2) DEFAULT NULL,
    `storage_url` VARCHAR(500) DEFAULT NULL,
    `srt_storage_url` VARCHAR(500) DEFAULT NULL,
    `srt_content` MEDIUMTEXT DEFAULT NULL,
    `subtitles_burned` TINYINT(1) NOT NULL DEFAULT 1,
    `subtitle_style_json` TEXT DEFAULT NULL,
    `status` ENUM('candidate', 'approved', 'rendering', 'ready', 'failed') NOT NULL DEFAULT 'candidate',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_clip_candidates_project` (`project_id`),
    KEY `idx_clip_candidates_user` (`user_id`),
    CONSTRAINT `fk_clip_candidates_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_clip_candidates_source` FOREIGN KEY (`source_video_id`) REFERENCES `source_videos` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_clip_candidates_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. Tabela: processing_jobs
-- Armazena as tarefas de processamento assíncrono enviadas ao worker Python,
-- incluindo controle de tentativas (attempts/max_attempts) e repetição (retry).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `processing_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `job_type` VARCHAR(50) NOT NULL DEFAULT 'find_highlights',
    `external_job_id` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('pending', 'queued', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `payload_json` JSON DEFAULT NULL,
    `result_summary_json` JSON DEFAULT NULL,
    `error_message` VARCHAR(500) DEFAULT NULL,
    `started_at` DATETIME DEFAULT NULL,
    `finished_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_processing_jobs_project` (`project_id`),
    KEY `idx_processing_jobs_user_status` (`user_id`, `status`),
    KEY `idx_processing_jobs_status_attempts` (`status`, `attempts`),
    CONSTRAINT `fk_processing_jobs_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_processing_jobs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. Tabela: usage_logs
-- Registra atividades da conta, erros de execução e consumo de recursos.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usage_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `project_id` BIGINT UNSIGNED DEFAULT NULL,
    `action_type` VARCHAR(60) NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `units_consumed` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_usage_logs_user_created` (`user_id`, `created_at`),
    KEY `idx_usage_logs_project` (`project_id`),
    CONSTRAINT `fk_usage_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_usage_logs_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. Tabela: monthly_budgets
-- Controle mensal compartilhado de orçamento no banco de dados (não depende
-- apenas de budget_ledger.json local), com bloqueio transacional para múltiplos workers.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `monthly_budgets` (
    `month_key` CHAR(7) NOT NULL COMMENT 'Formato YYYY-MM',
    `spent_brl` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    `reserved_brl` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    `calls_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `prompt_tokens` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `completion_tokens` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`month_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. Tabela: budget_reservations
-- Registra reservas atômicas pré-chamada e liquidação (settle/release) por chamada
-- de IA, garantindo que múltiplos workers simultâneos nunca ultrapassem o teto mensal.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `budget_reservations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reservation_token` VARCHAR(64) NOT NULL,
    `month_key` CHAR(7) NOT NULL,
    `job_id` BIGINT UNSIGNED NOT NULL,
    `project_id` BIGINT UNSIGNED NOT NULL,
    `reserved_brl` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    `settled_brl` DECIMAL(12,4) DEFAULT NULL,
    `status` ENUM('reserved', 'settled', 'released') NOT NULL DEFAULT 'reserved',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_budget_reservation_token` (`reservation_token`),
    KEY `idx_budget_res_month_status` (`month_key`, `status`),
    KEY `idx_budget_res_job_status` (`job_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
