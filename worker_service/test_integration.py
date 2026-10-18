"""
Teste de Integração Ponta a Ponta (PHP + Banco + Worker Python) (`worker_service/test_integration.py`).

Valida:
1. Rejeição estrita de URLs arbitrárias (`https://...`) no serviço Python.
2. Criação de projeto autorizado com fonte de vídeo local (`uploaded_file`) no banco de dados.
3. Simulação de falha (`fail_job`), verificação do status `failed` e repetição da tarefa (`retryFailedProjectForUser`).
4. Execução real do Worker Python autenticado via HMAC-SHA256 contra `pages/worker-api.php`.
5. Persistência dos candidatos (`clip_candidates`), transição do projeto para `completed` e registro de consumo em `usage_logs`.
"""

from __future__ import annotations

import shutil
import subprocess
import sys
import time
from pathlib import Path

from clipia_worker.config import WorkerConfig
from clipia_worker.security import UnauthorizedSourceError, validate_authorized_local_media_file
from main import ClipIAWorkerOrchestrator

ROOT_DIR = Path(__file__).resolve().parent.parent


def ensure_test_sqlite_schema(db_file: Path) -> None:
    db_file.parent.mkdir(parents=True, exist_ok=True)
    if db_file.exists():
        db_file.unlink()

    init_php = f"""
    $pdo = new PDO('sqlite:{db_file.as_posix()}');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE users (
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
        CREATE TABLE projects (
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
        CREATE TABLE source_videos (
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
        CREATE TABLE clip_candidates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            source_video_id INTEGER NOT NULL,
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
        CREATE TABLE processing_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            job_type TEXT NOT NULL DEFAULT 'find_highlights',
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
        CREATE TABLE usage_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            project_id INTEGER NULL,
            action_type TEXT NOT NULL,
            description TEXT NOT NULL,
            units_consumed INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
        CREATE TABLE monthly_budgets (
            month_key TEXT PRIMARY KEY,
            spent_brl REAL NOT NULL DEFAULT 0.0,
            reserved_brl REAL NOT NULL DEFAULT 0.0,
            calls_count INTEGER NOT NULL DEFAULT 0,
            prompt_tokens INTEGER NOT NULL DEFAULT 0,
            completion_tokens INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL
        );
        CREATE TABLE budget_reservations (
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
    echo 'SCHEMA_OK';
    """
    subprocess.run(["php", "-r", init_php], cwd=str(ROOT_DIR), check=True, capture_output=True)


def run_php_inline(code: str, env_vars: dict[str, str]) -> str:
    tmp_php = ROOT_DIR / "storage" / ".tmp_test_runner.php"
    try:
        tmp_php.write_text("<?php\n" + code, encoding="utf-8")
        proc = subprocess.run(
            ["php", str(tmp_php)],
            cwd=str(ROOT_DIR),
            env=env_vars,
            capture_output=True,
            text=True,
            encoding="utf-8",
            check=True,
        )
        return proc.stdout.strip()
    finally:
        tmp_php.unlink(missing_ok=True)


def test_security_rejects_arbitrary_urls() -> None:
    try:
        validate_authorized_local_media_file("https://example.com/arbitrary-video.mp4", authorized_confirmed=True)
        raise AssertionError("Deveria ter rejeitado URL arbitrária!")
    except UnauthorizedSourceError:
        print("[OK] 1. Bloqueio de URLs arbitrárias confirmado no serviço Python.")


def main() -> int:
    import os

    test_security_rejects_arbitrary_urls()

    db_file = ROOT_DIR / "storage" / "test_worker_integration.sqlite"
    ensure_test_sqlite_schema(db_file)

    shared_secret = "clipia-dev-hmac-secret-key-change-in-production-2026"
    env_vars = os.environ.copy()
    env_vars.update(
        {
            "DB_DRIVER": "sqlite",
            "DB_SQLITE_PATH": str(db_file),
            "WORKER_SHARED_SECRET": shared_secret,
            "WORKER_REQUIRE_HTTPS": "false",
            "CLIPIA_BACKEND_API_URL": "http://127.0.0.1:8099/pages/worker-api.php",
            "CLIPIA_WORKER_SHARED_SECRET": shared_secret,
        }
    )

    # Prepara o arquivo autorizado dentro de storage/uploads e sua transcrição complementar com timestamps
    uploads_dir = ROOT_DIR / "storage" / "uploads"
    uploads_dir.mkdir(parents=True, exist_ok=True)

    sample_mp4 = ROOT_DIR / "storage" / "samples" / "authorized_demo_podcast.mp4"
    sample_transcript = ROOT_DIR / "storage" / "samples" / "authorized_demo_podcast.mp4.transcript.json"

    dest_mp4 = uploads_dir / "authorized_demo_podcast.mp4"
    dest_transcript = uploads_dir / "authorized_demo_podcast.mp4.transcript.json"
    shutil.copyfile(sample_mp4, dest_mp4)
    shutil.copyfile(sample_transcript, dest_transcript)

    # Cria usuário + projeto autorizado no banco via repositórios PHP
    setup_php = """
    require_once 'includes/repositories.php';
    $user = UserRepository::findByEmail('worker_test@clipia.local');
    if (!$user) {
        $uid = UserRepository::create('Tester Worker', 'worker_test@clipia.local', 'SenhaForte123!');
    } else {
        $uid = (int)$user['id'];
    }
    $pid = ProjectRepository::createForUser($uid, [
        'title' => 'Podcast Autorizado - Teste Analise Python',
        'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'external_video_id' => 'dQw4w9WgXcQ',
        'video_title' => 'Podcast Autorizado Local',
        'author_name' => 'Canal Oficial ClipIA',
        'source_mode' => 'uploaded_file',
        'uploaded_file_path' => 'storage/uploads/authorized_demo_podcast.mp4',
        'uploaded_file_name' => 'authorized_demo_podcast.mp4',
        'uploaded_file_mime' => 'video/mp4',
        'uploaded_file_size' => filesize('storage/uploads/authorized_demo_podcast.mp4'),
        'target_clips_count' => 4,
        'min_duration_seconds' => 35,
        'max_duration_seconds' => 55,
        'aspect_ratio' => '9:16',
    ]);
    echo $uid . ':' . $pid;
    """
    uid_str, pid_str = run_php_inline(setup_php, env_vars).split(":")
    user_id = int(uid_str)
    project_id = int(pid_str)
    print(f"[OK] 2. Projeto autorizado criado no banco (user_id={user_id}, project_id={project_id}).")

    # Inicia servidor PHP embutido em background para testar a comunicação HTTP autenticada HMAC-SHA256
    php_server = subprocess.Popen(
        ["php", "-S", "127.0.0.1:8099"],
        cwd=str(ROOT_DIR),
        env=env_vars,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )

    try:
        time.sleep(1.2)
        os.environ.update(env_vars)
        config = WorkerConfig.from_env()
        orchestrator = ClipIAWorkerOrchestrator(config)

        # Testa registro de falha e repetição de tarefa (retry)
        fail_and_retry_php = f"""
        require_once 'includes/repositories.php';
        $job = WorkerJobRepository::claimNextAuthorizedJob({project_id});
        WorkerJobRepository::failJob((int)$job['job_id'], {project_id}, 'Falha transitória simulada para teste de retry.');
        $afterFail = ProjectRepository::findByIdForUser({project_id}, {user_id});
        $retried = ProjectRepository::retryFailedProjectForUser({project_id}, {user_id});
        $afterRetry = ProjectRepository::findByIdForUser({project_id}, {user_id});
        echo $afterFail['status'] . ':' . ($retried ? '1' : '0') . ':' . $afterRetry['status'];
        """
        fail_status, retried_flag, retry_status = run_php_inline(fail_and_retry_php, env_vars).split(":")
        assert fail_status == "failed", f"Esperado failed, obteve {fail_status}"
        assert retried_flag == "1", "Deveria permitir repetir a tarefa falha"
        assert retry_status == "awaiting_processing", f"Esperado awaiting_processing, obteve {retry_status}"
        print("[OK] 3. Registro de falha e repetição de tarefa (retry) validados com sucesso.")

        # Executa o Worker Python real autenticando via HMAC-SHA256 em http://127.0.0.1:8099/pages/worker-api.php
        processed = orchestrator.process_next_backend_job(specific_project_id=project_id)
        assert processed is True, "O Worker Python deveria ter processado a tarefa com sucesso."
        print("[OK] 4. Worker Python processou a tarefa via API protegida HMAC-SHA256.")

        # Verifica no banco se o projeto está 'completed' e com os 4 candidatos persistidos + clipes MP4 e legendas SRT prontos
        verify_php = f"""
        require_once 'includes/repositories.php';
        $proj = ProjectRepository::findByIdForUser({project_id}, {user_id});
        $cands = ProjectRepository::listCandidatesByProjectForUser({project_id}, {user_id});
        $firstStorageUrl = $cands[0]['storage_url'] ?? '';
        $firstSrtUrl = $cands[0]['srt_storage_url'] ?? '';
        $firstStatus = $cands[0]['status'] ?? '';
        $resolvedClip = VideoUploadManager::resolveSafeMediaPath((string)$firstStorageUrl);
        $resolvedSrt = VideoUploadManager::resolveSafeMediaPath((string)$firstSrtUrl);
        $clipExists = ($resolvedClip !== null && is_file($resolvedClip) && filesize($resolvedClip) > 1024) ? '1' : '0';
        $srtExists = ($resolvedSrt !== null && is_file($resolvedSrt) && filesize($resolvedSrt) > 20) ? '1' : '0';
        $metaOk = (!empty($cands[0]['short_description']) && str_contains((string)$cands[0]['hashtags'], '#') && (int)$cands[0]['subtitles_burned'] === 1) ? '1' : '0';
        echo $proj['status'] . ':' . count($cands) . ':' . $cands[0]['category'] . ':' . $firstStatus . ':' . $clipExists . ':' . $srtExists . ':' . $metaOk;
        """
        final_status, cands_count, first_cat, first_cand_status, clip_exists_flag, srt_exists_flag, meta_ok_flag = run_php_inline(verify_php, env_vars).split(":")
        assert final_status == "completed", f"Esperado status completed, obteve {final_status}"
        assert int(cands_count) == 4, f"Esperado 4 candidatos salvos, obteve {cands_count}"
        assert first_cand_status == "ready", f"Esperado candidato com status 'ready', obteve '{first_cand_status}'"
        assert clip_exists_flag == "1", "O arquivo MP4 cortado pelo FFmpeg deveria existir em storage/clips."
        assert srt_exists_flag == "1", "O arquivo SRT sincronizado deveria existir em storage/clips."
        assert meta_ok_flag == "1", "Metadados gratuitos (short_description, hashtags e subtitles_burned) deveriam estar salvos."
        print(
            f"[OK] 5. Banco, clipes MP4 com legenda incorporada, SRT e metadados validados: "
            f"status='{final_status}', candidatos={cands_count}, mp4={clip_exists_flag}, srt={srt_exists_flag}, meta={meta_ok_flag}."
        )

        # 5B. Testa edição de metadados (título, descrição curta e hashtags) e re-renderização de legenda (modo SRT separado) no PHP
        edit_and_rerender_php = f"""
        require_once 'includes/repositories.php';
        $cands = ProjectRepository::listCandidatesByProjectForUser({project_id}, {user_id});
        $cid = (int)$cands[0]['id'];
        $updOk = ProjectRepository::updateCandidateMetadataForUser(
            $cid,
            {user_id},
            'Título Editado no Painel ClipIA',
            'Descrição curta editada para Reels e Shorts.',
            '#clipia #cortesverticais #podcasts'
        );
        $candAfterEdit = ProjectRepository::findCandidateByIdForUser($cid, {user_id});
        $projRow = ProjectRepository::findByIdForUser({project_id}, {user_id});
        $rerenderRes = SubtitleMetadataHelper::rerenderCandidateWithStyle($candAfterEdit, $projRow, [
            'burn_subtitles' => false,
            'export_srt' => true,
            'font_family' => 'Verdana',
            'font_size' => 'xlarge',
            'primary_color' => '#FFFFFF',
            'highlight_color' => '#38BDF8',
            'outline_color' => '#000000',
            'position' => 'center',
            'style_preset' => 'boxed_contrast',
            'highlight_keywords' => true,
        ]);
        ProjectRepository::updateCandidateSubtitlesForUser($cid, {user_id}, $rerenderRes);
        $candAfterRerender = ProjectRepository::findCandidateByIdForUser($cid, {user_id});
        echo ($updOk && $candAfterEdit['title'] === 'Título Editado no Painel ClipIA' ? '1' : '0')
           . ':' . ((int)$candAfterRerender['subtitles_burned'] === 0 && !empty($candAfterRerender['srt_storage_url']) ? '1' : '0');
        """
        edit_meta_ok, rerender_clean_ok = run_php_inline(edit_and_rerender_php, env_vars).split(":")
        assert edit_meta_ok == "1" and rerender_clean_ok == "1"
        print("[OK] 5B. Edição de título/descrição/hashtags e re-renderização FFmpeg (MP4 limpo + SRT separado) validadas.")

        # Verifica no PHP a rejeição de sobreposição temporal e de extrapolação da duração do vídeo
        php_validation_guards = """
        require_once 'includes/worker_client.php';
        $overlapRejected = false;
        try {
            ExternalWorkerClient::validateCandidatesPayload([
                [
                    'start_time_seconds' => 0, 'end_time_seconds' => 40,
                    'title' => 'Corte 1', 'category' => 'historia_completa',
                    'summary' => 'Resumo válido 1', 'rationale' => 'Justificativa 1',
                    'transcript_excerpt' => 'Texto único um.', 'relevance_score' => 85
                ],
                [
                    'start_time_seconds' => 30, 'end_time_seconds' => 70,
                    'title' => 'Corte 2 sobreposto', 'category' => 'dica_pratica',
                    'summary' => 'Resumo válido 2', 'rationale' => 'Justificativa 2',
                    'transcript_excerpt' => 'Texto único dois.', 'relevance_score' => 80
                ],
            ], 35, 55, 4, 200.0);
        } catch (DomainException $e) {
            $overlapRejected = true;
        }
        $exceedsVideoRejected = false;
        try {
            ExternalWorkerClient::validateCandidatesPayload([
                [
                    'start_time_seconds' => 80, 'end_time_seconds' => 125,
                    'title' => 'Corte além do vídeo', 'category' => 'explicacao',
                    'summary' => 'Resumo válido', 'rationale' => 'Justificativa',
                    'transcript_excerpt' => 'Texto válido.', 'relevance_score' => 80
                ],
            ], 35, 55, 4, 100.0);
        } catch (DomainException $e) {
            $exceedsVideoRejected = true;
        }
        echo ($overlapRejected ? '1' : '0') . ':' . ($exceedsVideoRejected ? '1' : '0');
        """
        ov_flag, ex_flag = run_php_inline(php_validation_guards, env_vars).split(":")
        assert ov_flag == "1" and ex_flag == "1", "PHP deveria rejeitar sobreposição e extrapolação da duração do vídeo."
        print("[OK] 6. Validador PHP confirmou rejeição de sobreposição temporal e extrapolação da duração do vídeo.")

        # 6B. Teste de Desativação do Cadastro Público e Recuperação de Tarefas Interrompidas + Lock de Worker Único
        single_owner_and_recovery_php = f"""
        require_once 'includes/repositories.php';
        $pubRegDisabled = (defined('PUBLIC_REGISTRATION_ENABLED') && PUBLIC_REGISTRATION_ENABLED === false) ? '1' : '0';

        // Cria projeto e simula tarefa interrompida no meio da execução (started_at antigo em status 'running')
        $pidStale = ProjectRepository::createForUser({user_id}, [
            'title' => 'Projeto Tarefa Interrompida',
            'source_url' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE',
            'external_video_id' => 'M7lc1UVf-VE',
            'source_mode' => 'uploaded_file',
            'uploaded_file_path' => 'storage/uploads/authorized_demo_podcast.mp4',
            'uploaded_file_name' => 'authorized_demo_podcast.mp4',
            'uploaded_file_mime' => 'video/mp4',
            'uploaded_file_size' => 1024,
            'target_clips_count' => 3,
            'min_duration_seconds' => 30,
            'max_duration_seconds' => 55,
            'aspect_ratio' => '9:16',
        ]);
        $claimedStale = WorkerJobRepository::claimNextAuthorizedJob($pidStale);
        $pdo = Database::getConnection();
        $pastTime = date('Y-m-d H:i:s', time() - 3600);
        $pdo->prepare("UPDATE processing_jobs SET started_at = :past WHERE id = :id")
            ->execute([':past' => $pastTime, ':id' => $claimedStale['job_id']]);

        // Executa recuperação de tarefas interrompidas (timeout 600s) e verifica se voltou para awaiting_processing/queued
        $recoveredCount = WorkerJobRepository::recoverStaleRunningJobs(600);
        $projAfterRecovery = ProjectRepository::findByIdForUser($pidStale, {user_id});
        echo $pubRegDisabled . ':' . ($recoveredCount >= 1 ? '1' : '0') . ':' . $projAfterRecovery['status'];
        """
        pub_dis, rec_ok, rec_status = run_php_inline(single_owner_and_recovery_php, env_vars).split(":")
        assert pub_dis == "1" and rec_ok == "1" and rec_status == "awaiting_processing"

        from main import acquire_single_worker_instance_lock
        test_lock_file = ROOT_DIR / "storage" / "test_single_worker.lock"
        with acquire_single_worker_instance_lock(test_lock_file):
            duplicate_blocked = False
            try:
                with acquire_single_worker_instance_lock(test_lock_file):
                    pass
            except RuntimeError:
                duplicate_blocked = True
        assert duplicate_blocked is True
        print("[OK] 6B. Cadastro público desativado, lock de worker único e recuperação de tarefas interrompidas validados.")

        # 7. Teste de Concorrência entre dois Workers: apenas 1 worker deve conseguir reservar a mesma tarefa (exclusão mútua)
        concurrent_claim_php = f"""
        require_once 'includes/repositories.php';
        $pid2 = ProjectRepository::createForUser({user_id}, [
            'title' => 'Projeto Concorrencia Workers',
            'source_url' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE',
            'external_video_id' => 'M7lc1UVf-VE',
            'source_mode' => 'uploaded_file',
            'uploaded_file_path' => 'storage/uploads/authorized_demo_podcast.mp4',
            'uploaded_file_name' => 'authorized_demo_podcast.mp4',
            'uploaded_file_mime' => 'video/mp4',
            'uploaded_file_size' => 1024,
            'target_clips_count' => 3,
            'min_duration_seconds' => 30,
            'max_duration_seconds' => 55,
            'aspect_ratio' => '9:16',
        ]);
        $claimWorkerA = WorkerJobRepository::claimNextAuthorizedJob($pid2);
        $claimWorkerB = WorkerJobRepository::claimNextAuthorizedJob($pid2);
        echo ($claimWorkerA !== null ? '1' : '0') . ':' . ($claimWorkerB === null ? '1' : '0') . ':' . $pid2;
        """
        w_a, w_b_blocked, pid2_str = run_php_inline(concurrent_claim_php, env_vars).split(":")
        assert w_a == "1" and w_b_blocked == "1", "Dois workers nunca podem reservar a mesma tarefa simultaneamente!"
        print("[OK] 7. Exclusão mútua de tarefas confirmada: Worker A reservou e Worker B recebeu fila vazia.")

        # 8. Teste de Reserva Atômica e Liquidação de Orçamento Compartilhado no Banco de Dados (concorrência de orçamento)
        db_budget_concurrency_php = f"""
        require_once 'includes/repositories.php';
        // Teto mensal simulado de R$ 1.00: Worker 1 reserva R$ 0.60, Worker 2 tenta reservar R$ 0.60 e é bloqueado antes da chamada
        $r1 = WorkerJobRepository::reserveBudgetForCall(901, {pid2_str}, 0.60, 1.00, 2.50);
        $r2 = WorkerJobRepository::reserveBudgetForCall(902, {pid2_str}, 0.60, 1.00, 2.50);
        // Worker 1 liquida por R$ 0.50; agora sobra R$ 0.50 no mês; Worker 2 reserva R$ 0.45 com sucesso
        $s1 = WorkerJobRepository::settleBudgetReservation($r1['reservation_token'], 0.50, 500, 200, false);
        $r3 = WorkerJobRepository::reserveBudgetForCall(902, {pid2_str}, 0.45, 1.00, 2.50);
        echo ($r1['allowed'] ? '1' : '0') . ':'
           . (!$r2['allowed'] ? '1' : '0') . ':'
           . ($r3['allowed'] ? '1' : '0') . ':'
           . number_format((float)$s1['monthly_spent_brl'], 2, '.', '');
        """
        r1_ok, r2_blocked, r3_ok, spent_str = run_php_inline(db_budget_concurrency_php, env_vars).split(":")
        assert r1_ok == "1" and r2_blocked == "1" and r3_ok == "1" and spent_str == "0.50"
        print("[OK] 8. Reserva atômica e liquidação de orçamento compartilhado no banco de dados validadas contra concorrência.")

        # 9. Teste de Download Remoto da Fonte Autorizada (quando o worker roda em outra máquina sem acesso ao disco local do PHP)
        from clipia_worker.api_client import BackendApiClient
        api_client = BackendApiClient(config)
        downloaded_temp = api_client.download_authorized_source_to_temp(
            job_id=1,
            project_id=project_id,
            original_name="authorized_demo_podcast.mp4",
        )
        assert downloaded_temp.is_file() and downloaded_temp.stat().st_size > 0
        downloaded_temp.unlink(missing_ok=True)
        print("[OK] 9. Download remoto autenticado da fonte autorizada (storage/uploads) validado com sucesso.")

        # 10. Teste de Limites de Espaço (Cota) e Expiração de Mídia
        storage_quota_and_expiry_php = f"""
        require_once 'includes/video_upload.php';
        $quotaBlocked = false;
        try {{
            // Simula arquivo que excede a cota do usuário
            VideoUploadManager::enforceStorageQuotas({user_id}, 9999999999, VideoUploadManager::getUploadDirectory());
        }} catch (DomainException $e) {{
            $quotaBlocked = true;
        }}
        // Cria arquivo órfão antigo com mtime de 100 horas atrás e testa expurgo
        $oldFile = VideoUploadManager::getUploadDirectory() . '/u999_expired_test.mp4';
        file_put_contents($oldFile, 'expired');
        touch($oldFile, time() - (100 * 3600));
        $purged = VideoUploadManager::purgeExpiredMediaFiles(72);
        echo ($quotaBlocked ? '1' : '0') . ':' . ($purged >= 1 && !file_exists($oldFile) ? '1' : '0');
        """
        q_flag, p_flag = run_php_inline(storage_quota_and_expiry_php, env_vars).split(":")
        assert q_flag == "1" and p_flag == "1"
        print("[OK] 10. Limites de espaço (cota por usuário/servidor) e expiração automática de mídia validados.")

        # 11. Teste de HTTPS + Credenciais fora da pasta pública + Ocultação de erros em produção
        prod_hardening_php = """
        putenv('APP_ENV=production');
        putenv('WORKER_REQUIRE_HTTPS=1');
        require_once 'includes/worker_client.php';
        $httpRejected = ExternalWorkerClient::verifyIncomingRequest([
            'HTTPS' => 'off',
            'SERVER_PORT' => '80',
        ], '{}', 'segredo-forte-de-producao-com-32-bytes');
        $httpsForwardedAccepted = ExternalWorkerClient::verifyIncomingRequest([
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_CLIPIA_TIMESTAMP' => (string)time(),
            'HTTP_X_CLIPIA_SIGNATURE' => ExternalWorkerClient::computeSignature('segredo-forte-de-producao-com-32-bytes', time(), '{}'),
        ], '{}', 'segredo-forte-de-producao-com-32-bytes');
        $displayErrors = ini_get('display_errors');
        echo (!$httpRejected['ok'] && $httpRejected['code'] === 403 ? '1' : '0') . ':'
           . ($httpsForwardedAccepted['ok'] ? '1' : '0') . ':'
           . ($displayErrors === '0' || $displayErrors === '' ? '1' : '0');
        """
        http_rej, https_acc, no_err_disp = run_php_inline(prod_hardening_php, env_vars).split(":")
        assert http_rej == "1" and https_acc == "1" and no_err_disp == "1"
        print("[OK] 11. Hardening de produção validado (HTTPS obrigatório, X-Forwarded-Proto e display_errors=0).")

        # 12. Persistência após reiniciar o Worker + Upload tardio (attachAuthorizedFileForUser) + Exclusão limpa de clipes
        restarted_orchestrator = ClipIAWorkerOrchestrator(WorkerConfig.from_env())
        # Como a tarefa do projeto 1 já foi concluída, o novo worker reiniciado não deve reprocessá-la
        reprocessed = restarted_orchestrator.process_next_backend_job(specific_project_id=project_id)
        assert reprocessed is False, "Worker reiniciado não deve reprocessar tarefa já concluída."

        persistence_and_attach_php = f"""
        require_once 'includes/repositories.php';
        // Confirma que os dados do projeto 1 permanecem intactos após reinício do worker
        $projAfterRestart = ProjectRepository::findByIdForUser({project_id}, {user_id});
        $candsAfterRestart = ProjectRepository::listCandidatesByProjectForUser({project_id}, {user_id});
        $candById = ProjectRepository::findCandidateByIdForUser((int)$candsAfterRestart[0]['id'], {user_id});

        // Cria projeto inicialmente sem arquivo (url_only -> awaiting_source) e anexa arquivo depois
        $pidLate = ProjectRepository::createForUser({user_id}, [
            'title' => 'Projeto Upload Tardio',
            'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'external_video_id' => 'dQw4w9WgXcQ',
            'source_mode' => 'url_only',
            'target_clips_count' => 3,
            'min_duration_seconds' => 30,
            'max_duration_seconds' => 50,
            'aspect_ratio' => '9:16',
        ]);
        $attached = ProjectRepository::attachAuthorizedFileForUser($pidLate, {user_id}, [
            'relative_path' => 'storage/uploads/authorized_demo_podcast.mp4',
            'original_name' => 'authorized_demo_podcast.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => filesize('storage/uploads/authorized_demo_podcast.mp4'),
        ]);
        $pdo = Database::getConnection();
        $stmtJob = $pdo->prepare('SELECT status, payload_json FROM processing_jobs WHERE project_id = :pid');
        $stmtJob->execute([':pid' => $pidLate]);
        $jobRow = $stmtJob->fetch();
        $payloadArr = json_decode((string)$jobRow['payload_json'], true);
        $payloadUpdated = (($payloadArr['source']['source_mode'] ?? '') === 'uploaded_file'
            && ($payloadArr['source']['uploaded_file_path'] ?? '') === 'storage/uploads/authorized_demo_podcast.mp4') ? '1' : '0';

        // Exclui o projeto 1 e confirma que os arquivos de clipe MP4 foram removidos de storage/clips
        $firstClipRel = (string)$candsAfterRestart[0]['storage_url'];
        $deleted = ProjectRepository::deleteForUser({project_id}, {user_id});
        $clipDeleted = (VideoUploadManager::resolveSafeMediaPath($firstClipRel) === null) ? '1' : '0';

        echo ($projAfterRestart['status'] === 'completed' && count($candsAfterRestart) === 4 && $candById !== null ? '1' : '0')
           . ':' . ($attached && $jobRow['status'] === 'queued' && $payloadUpdated === '1' ? '1' : '0')
           . ':' . ($deleted && $clipDeleted === '1' ? '1' : '0');
        """
        persisted_ok, attach_ok, delete_clips_ok = run_php_inline(persistence_and_attach_php, env_vars).split(":")
        assert persisted_ok == "1" and attach_ok == "1" and delete_clips_ok == "1"
        print("[OK] 12. Persistência pós-reinício do worker, payload_json no upload tardio e limpeza de clipes validados.")

        audit_json = ROOT_DIR / "storage" / "audit_real_free_run.json"
        audit_clip = ROOT_DIR / "storage" / "clips" / "clip_local_run_01_0s_24s.mp4"
        if audit_json.is_file() and audit_clip.is_file():
            audit_import_php = f"""
        require_once 'includes/audit_demo_importer.php';
        $uid = {user_id};
        $r1 = AuditDemoImporter::importForUser($uid);
        $r2 = AuditDemoImporter::importForUser($uid);
        $cands = ProjectRepository::listCandidatesByProjectForUser((int)$r2['project_id'], $uid);
        $mp4Ok = VideoUploadManager::resolveSafeMediaPath((string)$cands[0]['storage_url']) !== null ? '1' : '0';
        $srtOk = VideoUploadManager::resolveSafeMediaPath((string)$cands[0]['srt_storage_url']) !== null ? '1' : '0';
        $idempotent = ($r1['project_id'] === $r2['project_id'] && count($cands) === 1) ? '1' : '0';
        echo $idempotent . ':' . $mp4Ok . ':' . $srtOk;
        """
            idem_ok, mp4_ok, srt_ok = run_php_inline(audit_import_php, env_vars).split(":")
            assert idem_ok == "1" and mp4_ok == "1" and srt_ok == "1"
            print("[OK] 13. Importação idempotente do audit_real_free_run.json para o painel (MP4 + SRT validados).")
        else:
            print("[SKIP] 13. audit_real_free_run.json ou clipe real ausente — pulando teste de importação da demonstração.")

    finally:
        php_server.terminate()
        php_server.wait(timeout=5)
        db_file.unlink(missing_ok=True)
        dest_mp4.unlink(missing_ok=True)
        dest_transcript.unlink(missing_ok=True)

    # Executa também toda a suíte de auditoria unitária (test_audit_suite.py) e a suíte OpenAI/Budget (test_openai_provider.py)
    import unittest
    from test_audit_suite import ClipIAAuditTestSuite
    from test_openai_provider import OpenAIProviderAndBudgetTestSuite

    suite = unittest.TestSuite()
    suite.addTests(unittest.defaultTestLoader.loadTestsFromTestCase(ClipIAAuditTestSuite))
    suite.addTests(unittest.defaultTestLoader.loadTestsFromTestCase(OpenAIProviderAndBudgetTestSuite))
    runner = unittest.TextTestRunner(verbosity=2)
    result = runner.run(suite)
    if not result.wasSuccessful():
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
