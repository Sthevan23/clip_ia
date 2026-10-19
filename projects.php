<?php
declare(strict_types=1);

/**
 * ClipIA — 6. Página Meus Projetos
 * - Estados de projeto: Aguardando fonte, Aguardando processamento, Processando, Concluído e Falhou
 * - Player incorporado oficial para pré-visualização quando o vídeo permitir
 * - Opção de envio de arquivo de vídeo autorizado (.mp4, .mov, .webm) para projetos "Aguardando fonte"
 * - Exibição estruturada dos candidatos de cortes analisados (início, fim, título, categoria, resumo, justificativa, transcrição e pontuação de interesse)
 * - Repetição de tarefas que falharem (`retry_failed_job`)
 * - Isolamento estrito por usuário autenticado
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/video_upload.php';
require_once __DIR__ . '/includes/subtitles_metadata.php';
require_once __DIR__ . '/includes/audit_demo_importer.php';

$currentUser = require_auth();
$userId = (int)$currentUser['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    $action = (string)($_POST['action'] ?? '');
    $returnViewCandidates = isset($_POST['return_view_candidates']) ? (int)$_POST['return_view_candidates'] : 0;

    if (!verify_csrf($csrf)) {
        set_flash('error', 'Sessão expirada', 'Token de segurança inválido. Recarregue a página e tente novamente.');
    } elseif ($action === 'update_candidate_metadata') {
        $candidateId = (int)($_POST['candidate_id'] ?? 0);
        $title       = trim((string)($_POST['candidate_title'] ?? ''));
        $shortDesc   = trim((string)($_POST['candidate_short_description'] ?? ''));
        $hashtags    = trim((string)($_POST['candidate_hashtags'] ?? ''));

        try {
            if ($candidateId <= 0 || !ProjectRepository::updateCandidateMetadataForUser($candidateId, $userId, $title, $shortDesc, $hashtags)) {
                set_flash('error', 'Falha ao salvar metadados', 'Não foi possível localizar o corte informado na sua conta.');
            } else {
                set_flash('success', 'Metadados salvos!', 'O título, a descrição curta e as hashtags do corte foram atualizados.');
            }
        } catch (DomainException $e) {
            set_flash('error', 'Dados inválidos', $e->getMessage());
        }
    } elseif ($action === 'regenerate_candidate_metadata') {
        $candidateId = (int)($_POST['candidate_id'] ?? 0);
        $cand = ProjectRepository::findCandidateByIdForUser($candidateId, $userId);
        if ($cand === null) {
            set_flash('error', 'Corte não encontrado', 'Não foi possível localizar o corte solicitado.');
        } else {
            $localMeta = SubtitleMetadataHelper::generateMetadataLocally(
                (string)($cand['transcript_excerpt'] ?? ''),
                (string)($cand['category'] ?? 'explicacao'),
                ''
            );
            ProjectRepository::updateCandidateMetadataForUser(
                $candidateId,
                $userId,
                (string)$localMeta['title'],
                (string)$localMeta['short_description'],
                implode(' ', $localMeta['hashtags'])
            );
            set_flash('success', 'Metadados regerados localmente!', 'Novo título, descrição curta e hashtags gerados gratuitamente com base na transcrição.');
        }
    } elseif ($action === 'rerender_candidate_subtitles') {
        $candidateId = (int)($_POST['candidate_id'] ?? 0);
        $cand = ProjectRepository::findCandidateByIdForUser($candidateId, $userId);
        if ($cand === null) {
            set_flash('error', 'Corte não encontrado', 'Não foi possível localizar o corte solicitado.');
        } else {
            $projectRow = ProjectRepository::findByIdForUser((int)$cand['project_id'], $userId);
            if ($projectRow === null) {
                set_flash('error', 'Projeto não encontrado', 'Não foi possível localizar o projeto deste corte.');
            } else {
                try {
                    $newStyle = SubtitleMetadataHelper::normalizeSubtitleStyle([
                        'burn_subtitles'     => ($_POST['subtitle_output_mode'] ?? 'burn_and_srt') === 'burn_and_srt',
                        'export_srt'         => true,
                        'font_family'        => $_POST['subtitle_font_family'] ?? 'Arial',
                        'font_size'          => $_POST['subtitle_font_size'] ?? 'large',
                        'primary_color'      => $_POST['subtitle_primary_color'] ?? '#FFFFFF',
                        'highlight_color'    => $_POST['subtitle_highlight_color'] ?? '#FACC15',
                        'outline_color'      => $_POST['subtitle_outline_color'] ?? '#000000',
                        'position'           => $_POST['subtitle_position'] ?? 'bottom',
                        'style_preset'       => $_POST['subtitle_style_preset'] ?? 'clean_outline',
                        'highlight_keywords' => isset($_POST['subtitle_highlight_keywords']),
                    ]);
                    $renderRes = SubtitleMetadataHelper::rerenderCandidateWithStyle($cand, $projectRow, $newStyle);
                    ProjectRepository::updateCandidateSubtitlesForUser($candidateId, $userId, $renderRes);
                    $modeLabel = $renderRes['subtitles_burned']
                        ? 'com legenda incorporada no vídeo MP4 + arquivo SRT'
                        : 'sem legenda queimada no vídeo MP4 + arquivo SRT separado';
                    set_flash('success', 'Legenda e vídeo atualizados!', 'O corte foi reprocessado com FFmpeg (' . $modeLabel . ').');
                } catch (Throwable $e) {
                    set_flash('error', 'Não foi possível re-renderizar o vídeo', $e->getMessage());
                }
            }
        }
    } elseif ($action === 'delete_project') {
        $projectId = (int)($_POST['project_id'] ?? 0);

        if ($projectId <= 0 || !ProjectRepository::deleteForUser($projectId, $userId)) {
            set_flash('error', 'Acesso negado ou projeto não encontrado', 'Não foi possível excluir o projeto solicitado.');
        } else {
            set_flash('success', 'Projeto excluído', 'O projeto foi removido com sucesso da sua conta.');
        }
    } elseif ($action === 'retry_failed_job') {
        $projectId = (int)($_POST['project_id'] ?? 0);

        if ($projectId <= 0 || !ProjectRepository::retryFailedProjectForUser($projectId, $userId)) {
            set_flash('error', 'Não foi possível repetir a tarefa', 'Verifique se o projeto pertence à sua conta e se o status atual é "Falhou".');
        } else {
            set_flash(
                'success',
                'Tarefa reenfileirada para análise!',
                'O projeto voltou para a fila de processamento e será analisado na próxima execução do serviço Python.'
            );
        }
    } elseif ($action === 'generate_clips') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $directMediaUrl = trim((string)($_POST['direct_media_url'] ?? ''));

        if ($projectId <= 0) {
            set_flash('error', 'Projeto inválido', 'Selecione um projeto válido.');
        } else {
            $result = ProjectRepository::queueGenerateClipsForUser($projectId, $userId, $directMediaUrl);
            if ($result['ok']) {
                set_flash('success', 'Geração enfileirada', $result['message']);
                header('Location: project-studio.php?id=' . $projectId);
                exit;
            }
            set_flash('error', 'Não foi possível gerar clipes', $result['message']);
        }
    } elseif ($action === 'upload_authorized_source') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $project = ProjectRepository::findByIdForUser($projectId, $userId);

        if ($project === null) {
            set_flash('error', 'Projeto não encontrado', 'Você não tem permissão para modificar este projeto.');
        } else {
            try {
                $uploadMeta = VideoUploadManager::validateAndStore($_FILES['authorized_video_file'] ?? [], $userId);
                if (ProjectRepository::attachAuthorizedFileForUser($projectId, $userId, $uploadMeta)) {
                    set_flash(
                        'success',
                        'Fonte de vídeo autorizada vinculada!',
                        'O arquivo "' . $uploadMeta['original_name'] . '" foi salvo. O status do projeto agora é "Aguardando processamento".'
                    );
                    header('Location: project-studio.php?id=' . $projectId);
                    exit;
                } else {
                    set_flash('error', 'Falha ao vincular arquivo', 'Não foi possível atualizar o estado do projeto.');
                }
            } catch (DomainException $e) {
                set_flash('error', 'Arquivo inválido', $e->getMessage());
            } catch (Throwable $e) {
                error_log('[ClipIA Attach Video Error] ' . $e->getMessage());
                set_flash('error', 'Erro no envio', 'Não foi possível armazenar o arquivo de vídeo enviado.');
            }
        }
    } elseif ($action === 'import_audit_demo') {
        try {
            if (!AuditDemoImporter::defaultJsonExists()) {
                set_flash(
                    'error',
                    'Demonstração indisponível',
                    'O arquivo storage/audit_real_free_run.json ainda não existe. Execute o teste real gratuito do worker antes de importar.'
                );
            } else {
                $importResult = AuditDemoImporter::importForUser($userId);
                $verb = !empty($importResult['created']) ? 'criado' : 'atualizado';
                set_flash(
                    'success',
                    'Demonstração importada!',
                    sprintf(
                        'Projeto de demonstração %s com %d corte(s) real(is) (Whisper local + FFmpeg, sem API paga).',
                        $verb,
                        count($importResult['candidate_ids'])
                    )
                );
                header('Location: projects.php?view_candidates=' . (int)$importResult['project_id']);
                exit;
            }
        } catch (DomainException $e) {
            set_flash('error', 'Importação recusada', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[ClipIA Audit Demo Import] ' . $e->getMessage());
            set_flash('error', 'Falha na importação', 'Não foi possível associar o resultado do teste real ao painel.');
        }
    }

    if ($returnViewCandidates > 0) {
        header('Location: projects.php?view_candidates=' . $returnViewCandidates);
    } else {
        header('Location: projects.php');
    }
    exit;
}

$projects = ProjectRepository::listByUser($userId, 100);
$auditDemoJsonReady = AuditDemoImporter::defaultJsonExists();
$auditDemoProjectId = AuditDemoImporter::findDemoProjectIdForUser($userId);

// Se o usuário solicitou inspecionar os candidatos de um projeto específico (?view_candidates=ID)
$selectedProjectForCandidates = null;
$selectedCandidates = [];
if (isset($_GET['view_candidates'])) {
    $requestedProjectId = (int)$_GET['view_candidates'];
    if ($requestedProjectId > 0) {
        $selectedProjectForCandidates = ProjectRepository::findByIdForUser($requestedProjectId, $userId);
        if ($selectedProjectForCandidates !== null) {
            $selectedCandidates = ProjectRepository::listCandidatesByProjectForUser($requestedProjectId, $userId);
        }
    }
}

$categoryLabels = [
    'historia_completa' => 'História Completa',
    'dica_pratica'      => 'Dica Prática',
    'curiosidade'       => 'Curiosidade',
    'humor'             => 'Humor',
    'opiniao'           => 'Opinião Relevante',
    'explicacao'        => 'Explicação Didática',
];

function format_seconds_mmss(float $sec): string
{
    $total = max(0, (int)round($sec));
    $m = intdiv($total, 60);
    $s = $total % 60;
    return sprintf('%02d:%02d', $m, $s);
}

$pageTitle = 'Meus Projetos';
$layoutType = 'app';
$activeNav = 'projects';
$topbarTitle = 'Meus Projetos';
$topbarSubtitle = 'Acompanhe os estados dos projetos, inspecione candidatos de cortes e gerencie fontes autorizadas.';

require_once __DIR__ . '/includes/header.php';
?>

<div class="app-shell">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="app-main">
        <?php require_once __DIR__ . '/includes/topbar.php'; ?>

        <main id="main-content" class="app-content">
            <?php render_flash_container(); ?>

            <?php if ($auditDemoJsonReady): ?>
                <section class="panel-card" style="margin-bottom: 20px; border-color: var(--accent-soft-border);" aria-labelledby="audit-demo-import-heading">
                    <div class="panel-header">
                        <div>
                            <h2 id="audit-demo-import-heading" class="panel-title" style="font-size: 1rem;">
                                Resultado do teste real gratuito (worker)
                            </h2>
                            <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 4px;">
                                Importe o JSON <code>storage/audit_real_free_run.json</code> e os arquivos MP4/SRT já gerados em <code>storage/clips/</code>, sem reprocessar vídeo e sem duplicar candidatos ao repetir a importação.
                            </p>
                        </div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                            <?php if ($auditDemoProjectId !== null): ?>
                                <a href="projects.php?view_candidates=<?= (int)$auditDemoProjectId ?>" class="btn btn-xs btn-secondary">
                                    Abrir demonstração importada
                                </a>
                            <?php endif; ?>
                            <form method="post" action="projects.php" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="import_audit_demo">
                                <button type="submit" class="btn btn-xs btn-primary">
                                    <?= $auditDemoProjectId === null ? 'Importar demonstração' : 'Atualizar demonstração' ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($selectedProjectForCandidates !== null): ?>
                <!-- Painel de Candidatos de Cortes Selecionados pelo Serviço Python -->
                <section class="panel-card" style="margin-bottom: 24px; border-color: var(--accent-soft-border);" aria-labelledby="candidates-heading">
                    <div class="panel-header">
                        <div>
                            <h2 id="candidates-heading" class="panel-title">
                                Candidatos de Cortes — <?= e($selectedProjectForCandidates['title']) ?>
                            </h2>
                            <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 4px;">
                                Trechos selecionados com timestamps pelo serviço de análise em Python. A pontuação reflete uma estimativa técnica de relevância contextual, sem promessa de viralização.
                            </p>
                        </div>
                        <a href="projects.php" class="btn btn-xs btn-secondary">Fechar visualização</a>
                    </div>

                    <div class="panel-body">
                        <?php if (empty($selectedCandidates)): ?>
                            <p style="color: var(--text-secondary); font-size: 0.9rem;">
                                Nenhum candidato de corte foi registrado para este projeto ainda. Execute o serviço Python (<code>worker_service/main.py</code>) para processar os projetos com fonte autorizada.
                            </p>
                        <?php else: ?>
                            <div style="display: grid; gap: 16px;">
                                <?php foreach ($selectedCandidates as $idx => $cand): ?>
                                    <?php
                                    $catKey = (string)($cand['category'] ?? 'explicacao');
                                    $catLabel = $categoryLabels[$catKey] ?? 'Explicação Didática';
                                    $startFmt = format_seconds_mmss((float)$cand['start_time_seconds']);
                                    $endFmt   = format_seconds_mmss((float)$cand['end_time_seconds']);
                                    $durSec   = round((float)$cand['duration_seconds'], 1);
                                    $score    = (int)$cand['relevance_score'];
                                    $candId   = (int)$cand['id'];

                                    $localFallbackMeta = SubtitleMetadataHelper::generateMetadataLocally(
                                        (string)($cand['transcript_excerpt'] ?? ''),
                                        $catKey,
                                        (string)($cand['title'] ?? '')
                                    );
                                    $shortDescVal = trim((string)($cand['short_description'] ?? ''));
                                    if ($shortDescVal === '') {
                                        $shortDescVal = (string)$localFallbackMeta['short_description'];
                                    }
                                    $hashtagsVal = SubtitleMetadataHelper::formatHashtagsString(
                                        !empty($cand['hashtags']) ? (string)$cand['hashtags'] : $localFallbackMeta['hashtags']
                                    );

                                    $rawCandStyle = !empty($cand['subtitle_style_json'])
                                        ? json_decode((string)$cand['subtitle_style_json'], true)
                                        : ($selectedProjectForCandidates['subtitle_style'] ?? []);
                                    $candSubStyle = SubtitleMetadataHelper::normalizeSubtitleStyle(is_array($rawCandStyle) ? $rawCandStyle : []);
                                    $subtitlesBurned = isset($cand['subtitles_burned'])
                                        ? ((int)$cand['subtitles_burned'] === 1)
                                        : (bool)$candSubStyle['burn_subtitles'];
                                    ?>
                                    <article style="padding: 18px; border-radius: var(--radius-md); background-color: var(--bg-elevated); border: 1px solid var(--border-subtle);">
                                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 10px;">
                                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                <span class="badge badge-violet">#<?= $idx + 1 ?> • <?= e($catLabel) ?></span>
                                                <span class="badge badge-neutral">
                                                    <?= e($startFmt) ?> → <?= e($endFmt) ?> (<?= e((string)$durSec) ?>s)
                                                </span>
                                                <span class="badge <?= $subtitlesBurned ? 'badge-success' : 'badge-warning' ?>">
                                                    <?= $subtitlesBurned ? 'Legenda incorporada no MP4' : 'MP4 sem legenda • SRT separado' ?>
                                                </span>
                                            </div>
                                            <span class="badge badge-success" title="Estimativa técnica de coesão narrativa e clareza do trecho">
                                                Interesse estimado: <?= $score ?>/100
                                            </span>
                                        </div>

                                        <h3 style="font-size: 1.02rem; font-weight: 600; color: var(--text-primary); margin-bottom: 6px;">
                                            <?= e((string)$cand['title']) ?>
                                        </h3>

                                        <p style="font-size: 0.88rem; color: var(--text-secondary); margin-bottom: 8px;">
                                            <strong>Resumo:</strong> <?= e((string)$cand['summary']) ?>
                                        </p>

                                        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 10px;">
                                            <strong>Justificativa da seleção:</strong> <?= e((string)$cand['rationale']) ?>
                                        </p>

                                        <div style="padding: 12px; border-radius: var(--radius-sm); background-color: var(--bg-surface); border-left: 3px solid var(--accent-primary); font-size: 0.86rem; color: var(--text-secondary);">
                                            <strong style="display: block; color: var(--text-primary); margin-bottom: 4px;">Transcrição sincronizada do trecho:</strong>
                                            “<?= e((string)$cand['transcript_excerpt']) ?>”
                                        </div>

                                        <!-- Painel de Edição e Cópia de Metadados (Título, Descrição Curta e Hashtags) -->
                                        <div style="margin-top: 14px; padding: 14px; border-radius: var(--radius-sm); background-color: var(--bg-surface); border: 1px solid var(--border-subtle);">
                                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 10px;">
                                                <strong style="font-size: 0.88rem; color: var(--text-primary);">
                                                    Metadados para Publicação (Gerados Localmente — Sem Custo)
                                                </strong>
                                                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                                    <button
                                                        type="button"
                                                        class="btn btn-xs btn-secondary"
                                                        data-copy-target="candTitle_<?= $candId ?>"
                                                    >
                                                        <?= clipia_icon('copy', 13) ?>
                                                        <span>Copiar título</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-xs btn-secondary"
                                                        data-copy-target="candDesc_<?= $candId ?>"
                                                    >
                                                        <?= clipia_icon('copy', 13) ?>
                                                        <span>Copiar descrição</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-xs btn-secondary"
                                                        data-copy-target="candTags_<?= $candId ?>"
                                                    >
                                                        <?= clipia_icon('copy', 13) ?>
                                                        <span>Copiar hashtags</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-xs btn-secondary"
                                                        data-copy-bundle="<?= $candId ?>"
                                                    >
                                                        <?= clipia_icon('copy', 13) ?>
                                                        <span>Copiar tudo</span>
                                                    </button>
                                                </div>
                                            </div>

                                            <form action="projects.php" method="POST" style="display: grid; gap: 10px;">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="update_candidate_metadata">
                                                <input type="hidden" name="candidate_id" value="<?= $candId ?>">
                                                <input type="hidden" name="return_view_candidates" value="<?= (int)$selectedProjectForCandidates['id'] ?>">

                                                <div>
                                                    <label for="candTitle_<?= $candId ?>" class="form-label" style="font-size: 0.78rem; margin-bottom: 3px;">Título do corte</label>
                                                    <input
                                                        type="text"
                                                        id="candTitle_<?= $candId ?>"
                                                        name="candidate_title"
                                                        class="form-control"
                                                        value="<?= e((string)$cand['title']) ?>"
                                                        maxlength="180"
                                                        required
                                                    >
                                                </div>

                                                <div>
                                                    <label for="candDesc_<?= $candId ?>" class="form-label" style="font-size: 0.78rem; margin-bottom: 3px;">Descrição curta</label>
                                                    <textarea
                                                        id="candDesc_<?= $candId ?>"
                                                        name="candidate_short_description"
                                                        class="form-control"
                                                        rows="2"
                                                        maxlength="500"
                                                    ><?= e($shortDescVal) ?></textarea>
                                                </div>

                                                <div>
                                                    <label for="candTags_<?= $candId ?>" class="form-label" style="font-size: 0.78rem; margin-bottom: 3px;">Hashtags relevantes</label>
                                                    <input
                                                        type="text"
                                                        id="candTags_<?= $candId ?>"
                                                        name="candidate_hashtags"
                                                        class="form-control"
                                                        value="<?= e($hashtagsVal) ?>"
                                                        maxlength="500"
                                                    >
                                                </div>

                                                <div style="display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap;">
                                                    <button type="submit" class="btn btn-xs btn-primary">
                                                        <span>Salvar metadados</span>
                                                    </button>
                                                </div>
                                            </form>

                                            <form action="projects.php" method="POST" style="margin-top: 6px; display: flex; justify-content: flex-start;">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="regenerate_candidate_metadata">
                                                <input type="hidden" name="candidate_id" value="<?= $candId ?>">
                                                <input type="hidden" name="return_view_candidates" value="<?= (int)$selectedProjectForCandidates['id'] ?>">
                                                <button type="submit" class="btn btn-xs btn-ghost" title="Regerar título, descrição e hashtags com heurísticas locais gratuitas">
                                                    <?= clipia_icon('refresh', 13) ?>
                                                    <span>Regerar título e hashtags localmente</span>
                                                </button>
                                            </form>
                                        </div>

                                        <!-- Exportação de Legenda .SRT, Player de Vídeo MP4 e Personalização de Estilo FFmpeg -->
                                        <div style="margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border-subtle); display: flex; flex-direction: column; gap: 10px;">
                                            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                    <?php if (!empty($cand['storage_url'])): ?>
                                                        <span class="badge badge-success">
                                                            Clipe MP4 pronto (<?= e((string)($cand['aspect_ratio'] ?? '9:16')) ?>)
                                                        </span>
                                                    <?php endif; ?>
                                                    <span class="badge badge-neutral">
                                                        Fonte: <?= e((string)$candSubStyle['font_family']) ?> • <?= e(strtoupper((string)$candSubStyle['font_size'])) ?> • Posição: <?= e((string)$candSubStyle['position']) ?>
                                                    </span>
                                                </div>

                                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                    <a
                                                        href="pages/stream-clip.php?id=<?= $candId ?>&amp;format=srt"
                                                        class="btn btn-xs btn-secondary"
                                                    >
                                                        <?= clipia_icon('subtitles', 14) ?>
                                                        <span>Exportar legenda (.srt)</span>
                                                    </a>

                                                    <?php if (!empty($cand['storage_url'])): ?>
                                                        <a
                                                            href="pages/stream-clip.php?id=<?= $candId ?>&amp;download=1"
                                                            class="btn btn-xs btn-primary"
                                                        >
                                                            <?= clipia_icon('download', 14) ?>
                                                            <span>Baixar clipe (.mp4)</span>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <?php if (!empty($cand['storage_url'])): ?>
                                                <video
                                                    controls
                                                    preload="metadata"
                                                    style="width: 100%; max-height: 360px; border-radius: var(--radius-sm); background-color: #000;"
                                                    src="pages/stream-clip.php?id=<?= $candId ?>&amp;v=<?= urlencode((string)($candSubStyle['font_size'] . '_' . ($subtitlesBurned ? 'burn' : 'clean') . '_' . $candSubStyle['primary_color'])) ?>"
                                                ></video>
                                            <?php endif; ?>

                                            <!-- Personalização de Legendas e Modo Burn-in vs SRT Separado por Corte -->
                                            <details style="margin-top: 4px; padding: 12px; border-radius: var(--radius-sm); background-color: var(--bg-surface); border: 1px solid var(--border-subtle);">
                                                <summary style="cursor: pointer; font-size: 0.85rem; font-weight: 600; color: var(--text-primary);">
                                                    Personalizar fonte, cores, posição e modo da legenda neste corte (FFmpeg)
                                                </summary>

                                                <form action="projects.php" method="POST" style="margin-top: 12px; display: grid; gap: 10px;">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="rerender_candidate_subtitles">
                                                    <input type="hidden" name="candidate_id" value="<?= $candId ?>">
                                                    <input type="hidden" name="return_view_candidates" value="<?= (int)$selectedProjectForCandidates['id'] ?>">

                                                    <div class="form-row">
                                                        <div class="form-group" style="margin-bottom: 0;">
                                                            <label class="form-label" style="font-size: 0.78rem;">Modo de saída do vídeo</label>
                                                            <select name="subtitle_output_mode" class="form-control">
                                                                <option value="burn_and_srt" <?= $subtitlesBurned ? 'selected' : '' ?>>
                                                                    Incorporar legenda no vídeo MP4 (FFmpeg) + exportar .SRT
                                                                </option>
                                                                <option value="srt_only" <?= !$subtitlesBurned ? 'selected' : '' ?>>
                                                                    Gerar vídeo MP4 limpo (sem legenda queimada) + exportar .SRT separado
                                                                </option>
                                                            </select>
                                                        </div>

                                                        <div class="form-group" style="margin-bottom: 0;">
                                                            <label class="form-label" style="font-size: 0.78rem;">Estilo de contorno e sombra</label>
                                                            <select name="subtitle_style_preset" class="form-control">
                                                                <?php foreach (SubtitleMetadataHelper::ALLOWED_PRESETS as $prKey => $prLabel): ?>
                                                                    <option value="<?= e($prKey) ?>" <?= ($candSubStyle['style_preset'] === $prKey) ? 'selected' : '' ?>>
                                                                        <?= e($prLabel) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <div class="form-row" style="grid-template-columns: repeat(3, 1fr);">
                                                        <div class="form-group" style="margin-bottom: 0;">
                                                            <label class="form-label" style="font-size: 0.78rem;">Fonte</label>
                                                            <select name="subtitle_font_family" class="form-control">
                                                                <?php foreach (SubtitleMetadataHelper::ALLOWED_FONTS as $fKey => $fLabel): ?>
                                                                    <option value="<?= e($fKey) ?>" <?= ($candSubStyle['font_family'] === $fKey) ? 'selected' : '' ?>>
                                                                        <?= e($fLabel) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="form-group" style="margin-bottom: 0;">
                                                            <label class="form-label" style="font-size: 0.78rem;">Tamanho</label>
                                                            <select name="subtitle_font_size" class="form-control">
                                                                <?php foreach (SubtitleMetadataHelper::ALLOWED_SIZES as $sKey => $sLabel): ?>
                                                                    <option value="<?= e($sKey) ?>" <?= ($candSubStyle['font_size'] === $sKey) ? 'selected' : '' ?>>
                                                                        <?= e($sLabel) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="form-group" style="margin-bottom: 0;">
                                                            <label class="form-label" style="font-size: 0.78rem;">Posição vertical</label>
                                                            <select name="subtitle_position" class="form-control">
                                                                <?php foreach (SubtitleMetadataHelper::ALLOWED_POSITIONS as $pKey => $pLabel): ?>
                                                                    <option value="<?= e($pKey) ?>" <?= ($candSubStyle['position'] === $pKey) ? 'selected' : '' ?>>
                                                                        <?= e($pLabel) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <div class="form-row" style="grid-template-columns: repeat(3, 1fr);">
                                                        <div class="form-group" style="margin-bottom: 0;">
                                                            <label class="form-label" style="font-size: 0.78rem;">Cor principal</label>
                                                            <input type="color" name="subtitle_primary_color" class="form-control" style="height: 36px; padding: 3px;" value="<?= e($candSubStyle['primary_color']) ?>">
                                                        </div>
                                                        <div class="form-group" style="margin-bottom: 0;">
                                                            <label class="form-label" style="font-size: 0.78rem;">Cor de destaque</label>
                                                            <input type="color" name="subtitle_highlight_color" class="form-control" style="height: 36px; padding: 3px;" value="<?= e($candSubStyle['highlight_color']) ?>">
                                                        </div>
                                                        <div class="form-group" style="margin-bottom: 0;">
                                                            <label class="form-label" style="font-size: 0.78rem;">Cor do contorno</label>
                                                            <input type="color" name="subtitle_outline_color" class="form-control" style="height: 36px; padding: 3px;" value="<?= e($candSubStyle['outline_color']) ?>">
                                                        </div>
                                                    </div>

                                                    <label class="checkbox-label" style="font-size: 0.82rem;">
                                                        <input type="checkbox" name="subtitle_highlight_keywords" value="1" <?= !empty($candSubStyle['highlight_keywords']) ? 'checked' : '' ?>>
                                                        <span>Destacar palavras importantes com contraste de cor (sem animações distrativas)</span>
                                                    </label>

                                                    <div style="display: flex; justify-content: flex-end;">
                                                        <button type="submit" class="btn btn-xs btn-primary">
                                                            <?= clipia_icon('subtitles', 14) ?>
                                                            <span>Aplicar estilo e re-renderizar vídeo (FFmpeg)</span>
                                                        </button>
                                                    </div>
                                                </form>
                                            </details>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Barra de ferramentas: Busca, Filtro de Status (5 estados oficiais) e Ação Principal -->
            <div class="projects-toolbar">
                <div class="search-box">
                    <?= clipia_icon('search', 18) ?>
                    <input
                        type="search"
                        id="projectSearchInput"
                        class="form-control"
                        placeholder="Buscar projeto pelo nome..."
                        aria-label="Buscar projeto pelo nome"
                    >
                </div>

                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <select id="projectStatusFilter" class="form-control" style="width: auto; min-width: 220px;" aria-label="Filtrar por status">
                        <option value="all">Todos os status</option>
                        <option value="awaiting_source">Aguardando fonte</option>
                        <option value="awaiting_processing">Aguardando processamento</option>
                        <option value="processing">Processando</option>
                        <option value="completed">Concluído</option>
                        <option value="failed">Falhou</option>
                    </select>

                    <a href="create-clips.php" class="btn btn-primary">
                        <?= clipia_icon('plus-circle', 18) ?>
                        <span>Novo projeto</span>
                    </a>
                </div>
            </div>

            <!-- Lista de Projetos -->
            <section class="panel-card" aria-labelledby="projects-list-heading">
                <div class="panel-header">
                    <h2 id="projects-list-heading" class="panel-title">
                        Lista de Projetos (<?= count($projects) ?>)
                    </h2>
                    <span class="badge badge-neutral">Isolamento por Conta Ativo</span>
                </div>

                <div class="panel-body" style="padding: 0;">
                    <?php if (empty($projects)): ?>
                        <div style="padding: 24px;">
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <?= clipia_icon('folder', 24) ?>
                                </div>
                                <h3 class="empty-state-title">Sua lista de projetos está vazia</h3>
                                <p class="empty-state-text">
                                    Crie seu primeiro projeto informando o link de referência do YouTube e os parâmetros de duração dos cortes verticais 9:16.
                                </p>
                                <div class="empty-state-actions">
                                    <a href="create-clips.php" class="btn btn-primary btn-sm">
                                        <?= clipia_icon('plus-circle', 16) ?>
                                        <span>Criar clipes</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Projeto / Referência YouTube</th>
                                        <th>Status</th>
                                        <th>Data de Criação</th>
                                        <th>Configuração de Cortes</th>
                                        <th>Candidatos</th>
                                        <th style="text-align: right;">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($projects as $proj): ?>
                                        <?php
                                        $statusKey = (string)($proj['status'] ?? 'awaiting_source');
                                        $badgeClass = (string)($proj['status_badge_class'] ?? 'badge-warning');
                                        $clipsDisplay = sprintf(
                                            '%d cortes (%ds–%ds)',
                                            (int)($proj['target_clips'] ?? 5),
                                            (int)($proj['min_duration'] ?? 30),
                                            (int)($proj['max_duration'] ?? 60)
                                        );
                                        $hasUploadedFile = ($proj['source_mode'] ?? '') === 'uploaded_file' && $proj['uploaded_file_name'] !== '';
                                        $generatedCount = (int)($proj['GeneratedClips'] ?? 0);
                                        ?>
                                        <tr
                                            data-project-row
                                            data-project-title="<?= e($proj['title']) ?>"
                                            data-project-status="<?= e($statusKey) ?>"
                                        >
                                            <td>
                                                <div class="project-cell-title">
                                                    <span><?= e($proj['title']) ?></span>
                                                </div>
                                                <span class="project-cell-url">
                                                    ID YouTube: <code><?= e($proj['video_external_id']) ?></code>
                                                    <?php if ($hasUploadedFile): ?>
                                                        • Arquivo autorizado: <?= e($proj['uploaded_file_name']) ?>
                                                    <?php endif; ?>
                                                    <?php if ($statusKey === 'failed' && !empty($proj['last_error_message'])): ?>
                                                        <br><span style="color: var(--danger); font-size: 0.78rem;">Erro: <?= e($proj['last_error_message']) ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?= e($badgeClass) ?>">
                                                    <?= e($proj['status_label']) ?>
                                                </span>
                                            </td>
                                            <td><?= e($proj['created_at']) ?></td>
                                            <td><?= e($clipsDisplay) ?></td>
                                            <td>
                                                <?php if ($generatedCount > 0): ?>
                                                    <a href="projects.php?view_candidates=<?= (int)$proj['id'] ?>" class="badge badge-violet" style="text-decoration: none;">
                                                        <?= $generatedCount ?> trechos
                                                    </a>
                                                <?php else: ?>
                                                    <span class="badge badge-neutral">0 trechos</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="table-actions" style="justify-content: flex-end;">
                                                    <?php if ($generatedCount > 0): ?>
                                                        <a
                                                            href="projects.php?view_candidates=<?= (int)$proj['id'] ?>"
                                                            class="btn btn-xs btn-primary"
                                                        >
                                                            <?= clipia_icon('sparkles', 14) ?>
                                                            <span>Ver cortes</span>
                                                        </a>
                                                    <?php endif; ?>

                                                    <?php if ($statusKey === 'failed'): ?>
                                                        <form action="projects.php" method="POST" style="display: inline;">
                                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="retry_failed_job">
                                                            <input type="hidden" name="project_id" value="<?= (int)$proj['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-secondary" title="Repetir tarefa que falhou">
                                                                <span>Repetir</span>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>

                                                    <a
                                                        href="project-studio.php?id=<?= (int)$proj['id'] ?>"
                                                        class="btn btn-xs btn-primary"
                                                        title="Acompanhar progresso e clipes"
                                                    >
                                                        <?= clipia_icon('sparkles', 14) ?>
                                                        <span>Estúdio</span>
                                                    </a>

                                                    <button
                                                        type="button"
                                                        class="btn btn-xs btn-secondary"
                                                        data-open-project="<?= (int)$proj['id'] ?>"
                                                        data-title="<?= e($proj['title']) ?>"
                                                        data-url="<?= e($proj['source_url']) ?>"
                                                        data-video-id="<?= e($proj['video_external_id']) ?>"
                                                        data-embed-url="<?= e($proj['embed_url']) ?>"
                                                        data-embed-allowed="<?= $proj['embed_allowed'] ? '1' : '0' ?>"
                                                        data-thumb-url="<?= e($proj['thumbnail_url']) ?>"
                                                        data-format="<?= e($proj['aspect_ratio'] ?? '9:16') ?>"
                                                        data-clips="<?= e($clipsDisplay) ?>"
                                                        data-status-key="<?= e($statusKey) ?>"
                                                        data-status-label="<?= e($proj['status_label']) ?>"
                                                        data-uploaded-file="<?= e($proj['uploaded_file_name']) ?>"
                                                        data-source-mode="<?= e($proj['source_mode'] ?? 'url_only') ?>"
                                                        data-authorized-media-url="<?= e($proj['authorized_media_url'] ?? '') ?>"
                                                    >
                                                        <?= clipia_icon('eye', 14) ?>
                                                        <span>Abrir</span>
                                                    </button>

                                                    <button
                                                        type="button"
                                                        class="btn btn-xs btn-danger"
                                                        data-delete-project="<?= (int)$proj['id'] ?>"
                                                        data-title="<?= e($proj['title']) ?>"
                                                    >
                                                        <?= clipia_icon('trash', 14) ?>
                                                        <span>Excluir</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>

<!-- Modal de Abertura, Player Incorporado e Upload de Fonte Autorizada -->
<div class="modal-backdrop" id="projectDetailModal" role="dialog" aria-modal="true" aria-labelledby="modalProjectTitle">
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title" id="modalProjectTitle">Detalhes do Projeto</h3>
            <button type="button" class="alert-close" data-close-modal aria-label="Fechar janela">
                <?= clipia_icon('close', 18) ?>
            </button>
        </div>

        <!-- Player Incorporado Oficial do YouTube (quando permitido) -->
        <div class="video-preview-box" style="margin-bottom: 16px;">
            <div class="video-thumbnail-wrapper" id="modalVideoStage">
                <iframe
                    id="modalEmbedFrame"
                    src="about:blank"
                    title="Player incorporado do YouTube"
                    style="width: 100%; height: 100%; border: 0; display: none;"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                    referrerpolicy="strict-origin-when-cross-origin"
                    allowfullscreen
                ></iframe>
                <img
                    id="modalThumbFallback"
                    src="<?= e(ASSETS_URL) ?>/img/placeholder-video.svg"
                    alt="Miniatura do vídeo"
                    style="display: none;"
                >
            </div>
        </div>

        <div class="summary-spec-list" style="border-top: none; padding-top: 0;">
            <div class="summary-spec-item">
                <span>URL original salva:</span>
                <strong id="modalProjectUrl" style="max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">—</strong>
            </div>
            <div class="summary-spec-item">
                <span>ID do vídeo (YouTube):</span>
                <strong id="modalProjectVideoId">—</strong>
            </div>
            <div class="summary-spec-item">
                <span>Status atual:</span>
                <strong id="modalProjectStatus" class="text-accent">—</strong>
            </div>
            <div class="summary-spec-item">
                <span>Formato configurado:</span>
                <strong id="modalProjectFormat">9:16</strong>
            </div>
            <div class="summary-spec-item">
                <span>Parâmetros de cortes:</span>
                <strong id="modalProjectClips">—</strong>
            </div>
            <div class="summary-spec-item">
                <span>Fonte de vídeo autorizada:</span>
                <strong id="modalProjectSourceFile">—</strong>
            </div>
        </div>

        <form
            action="projects.php"
            method="POST"
            id="modalGenerateClipsForm"
            style="margin-top: 16px; padding: 14px; border-radius: var(--radius-md); background-color: var(--bg-elevated); border: 1px solid var(--accent-soft-border); display: none;"
        >
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="generate_clips">
            <input type="hidden" name="project_id" id="modalGenerateProjectId" value="">

            <label for="modalDirectMediaUrl" class="form-label">URL HTTPS do arquivo de vídeo (opcional)</label>
            <div class="url-input-row">
                <input
                    type="url"
                    id="modalDirectMediaUrl"
                    name="direct_media_url"
                    class="form-control"
                    placeholder="https://seu-dominio.com/videos/episodio.mp4"
                    autocomplete="off"
                >
                <button type="submit" class="btn btn-primary btn-sm">Gerar clipes</button>
            </div>
            <span class="form-hint" id="modalGenerateClipsHint">
                Links do YouTube não são baixados automaticamente. Informe uma URL direta (.mp4, .mov ou .webm) que você tem direito de usar, ou envie o arquivo abaixo.
            </span>
        </form>

        <!-- Formulário para anexar arquivo autorizado quando o projeto estiver em 'awaiting_source' -->
        <form
            action="projects.php"
            method="POST"
            enctype="multipart/form-data"
            id="modalAttachSourceForm"
            style="margin-top: 16px; padding: 14px; border-radius: var(--radius-md); background-color: var(--bg-elevated); border: 1px solid var(--accent-soft-border); display: none;"
        >
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="upload_authorized_source">
            <input type="hidden" name="project_id" id="modalAttachProjectId" value="">

            <label for="modalAuthorizedVideoFile" class="form-label">
                Enviar arquivo de vídeo autorizado (.mp4, .mov, .webm) para liberar processamento
            </label>
            <div class="url-input-row">
                <input
                    type="file"
                    id="modalAuthorizedVideoFile"
                    name="authorized_video_file"
                    class="form-control"
                    accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm"
                    required
                >
                <button type="submit" class="btn btn-primary btn-sm">Enviar arquivo</button>
            </div>
            <span class="form-hint">Alternativa ao link HTTPS acima: envie o MP4/MOV/WebM autorizado para avançar o projeto.</span>
        </form>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-close-modal>Fechar</button>
        </div>
    </div>
</div>

<!-- Modal de Confirmação de Exclusão -->
<div class="modal-backdrop" id="projectDeleteModal" role="dialog" aria-modal="true" aria-labelledby="deleteModalHeading">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title" id="deleteModalHeading">Confirmar exclusão</h3>
            <button type="button" class="alert-close" data-close-modal aria-label="Fechar janela">
                <?= clipia_icon('close', 18) ?>
            </button>
        </div>
        <p style="font-size: 0.92rem; color: var(--text-secondary);">
            Tem certeza de que deseja remover o projeto <strong id="deleteProjectTitleSpan" style="color: var(--text-primary);">selecionado</strong> do banco de dados? Esta ação não poderá ser desfeita.
        </p>

        <form action="projects.php" method="POST" class="modal-footer">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_project">
            <input type="hidden" name="project_id" id="deleteProjectIdInput" value="">
            <button type="button" class="btn btn-ghost" data-close-modal>Cancelar</button>
            <button type="submit" class="btn btn-danger">Confirmar exclusão</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
