<?php
declare(strict_types=1);

/**
 * ClipIA — 7. Página de Configurações (Protegida + Persistência Real em MySQL via PDO)
 * Seções: Perfil, Preferências, Segurança e Espaço Reservado para Consumo e Limites de Uso
 */

require_once __DIR__ . '/includes/auth.php';

$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$activeTab = 'perfil';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    $section = (string)($_POST['settings_section'] ?? 'perfil');
    $activeTab = $section;

    if (!verify_csrf($csrf)) {
        set_flash('error', 'Sessão expirada', 'Token de segurança inválido. Recarregue a página e tente novamente.');
    } elseif ($section === 'perfil') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));

        if (mb_strlen($name) < 3 || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Dados inválidos', 'Informe um nome válido (entre 3 e 120 caracteres) e um e-mail válido.');
        } else {
            try {
                UserRepository::updateProfile($userId, $name, $email);
                $_SESSION['auth_user_name'] = $name;
                $_SESSION['auth_user_email'] = $email;
                set_flash('success', 'Perfil atualizado', 'Suas informações de perfil foram salvas no banco de dados.');
            } catch (DomainException $e) {
                set_flash('error', 'E-mail indisponível', $e->getMessage());
            } catch (Throwable $e) {
                set_flash('error', 'Erro ao atualizar perfil', 'Não foi possível salvar suas alterações no momento.');
            }
        }
    } elseif ($section === 'preferencias') {
        $aspectRatio = (string)($_POST['default_aspect_ratio'] ?? '9:16');
        $clipsCount = max(CLIP_MIN_COUNT, min(CLIP_MAX_COUNT, (int)($_POST['default_clips_count'] ?? 5)));
        $minDuration = max(10, min(170, (int)($_POST['default_min_duration'] ?? 30)));
        $maxDuration = max(15, min(180, (int)($_POST['default_max_duration'] ?? 60)));
        $emailNotif = isset($_POST['email_notifications']);

        if (!in_array($aspectRatio, ['9:16', '1:1', '16:9'], true) || $minDuration >= $maxDuration) {
            set_flash('error', 'Preferências inválidas', 'A duração mínima deve ser menor que a duração máxima.');
        } else {
            UserRepository::updatePreferences($userId, $aspectRatio, $clipsCount, $minDuration, $maxDuration, $emailNotif);
            set_flash('success', 'Preferências salvas', 'Seus parâmetros padrão de corte foram atualizados no banco de dados.');
        }
    } elseif ($section === 'seguranca') {
        $currentPass = (string)($_POST['current_password'] ?? '');
        $newPass = (string)($_POST['new_password'] ?? '');
        $confirmPass = (string)($_POST['confirm_password'] ?? '');

        $passStrengthErr = validate_password_strength($newPass);

        if ($currentPass === '') {
            set_flash('error', 'Senha atual obrigatória', 'Informe sua senha atual para autorizar a alteração.');
        } elseif ($passStrengthErr !== null) {
            set_flash('error', 'Nova senha inválida', $passStrengthErr);
        } elseif ($newPass !== $confirmPass) {
            set_flash('error', 'Confirmação incorreta', 'A confirmação da nova senha não coincide.');
        } else {
            try {
                UserRepository::updatePassword($userId, $currentPass, $newPass);
                set_flash('success', 'Senha atualizada com segurança', 'Sua senha foi alterada usando hash criptográfico.');
            } catch (DomainException $e) {
                set_flash('error', 'Não foi possível alterar a senha', $e->getMessage());
            } catch (Throwable $e) {
                set_flash('error', 'Erro interno', 'Não foi possível atualizar sua senha neste momento.');
            }
        }
    }
}

$userProfile = get_current_user_profile();
$stats = ProjectRepository::getStatsForUser($userId);
$budgetSnapshot = WorkerJobRepository::getMonthlyBudgetSnapshot();

$pageTitle = 'Configurações';
$layoutType = 'app';
$activeNav = 'settings';
$topbarTitle = 'Configurações da Conta';
$topbarSubtitle = 'Gerencie seu perfil, preferências de corte, segurança e limites de uso.';

require_once __DIR__ . '/includes/header.php';
?>

<div class="app-shell">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="app-main">
        <?php require_once __DIR__ . '/includes/topbar.php'; ?>

        <main id="main-content" class="app-content">
            <?php render_flash_container(); ?>

            <div class="settings-layout">
                <!-- Menu de Abas de Configurações -->
                <nav class="settings-tabs" role="tablist" aria-label="Seções de configurações">
                    <button
                        type="button"
                        class="settings-tab-btn <?= $activeTab === 'perfil' ? 'is-active' : '' ?>"
                        data-settings-tab="perfil"
                        role="tab"
                        aria-selected="<?= $activeTab === 'perfil' ? 'true' : 'false' ?>"
                    >
                        <?= clipia_icon('user', 18) ?>
                        <span>Perfil</span>
                    </button>

                    <button
                        type="button"
                        class="settings-tab-btn <?= $activeTab === 'preferencias' ? 'is-active' : '' ?>"
                        data-settings-tab="preferencias"
                        role="tab"
                        aria-selected="<?= $activeTab === 'preferencias' ? 'true' : 'false' ?>"
                    >
                        <?= clipia_icon('sliders', 18) ?>
                        <span>Preferências</span>
                    </button>

                    <button
                        type="button"
                        class="settings-tab-btn <?= $activeTab === 'seguranca' ? 'is-active' : '' ?>"
                        data-settings-tab="seguranca"
                        role="tab"
                        aria-selected="<?= $activeTab === 'seguranca' ? 'true' : 'false' ?>"
                    >
                        <?= clipia_icon('lock', 18) ?>
                        <span>Segurança</span>
                    </button>

                    <button
                        type="button"
                        class="settings-tab-btn <?= $activeTab === 'consumo' ? 'is-active' : '' ?>"
                        data-settings-tab="consumo"
                        role="tab"
                        aria-selected="<?= $activeTab === 'consumo' ? 'true' : 'false' ?>"
                    >
                        <?= clipia_icon('cpu', 18) ?>
                        <span>Consumo e Limites</span>
                    </button>
                </nav>

                <!-- Conteúdo das Abas -->
                <div>
                    <!-- 1. Aba Perfil -->
                    <section
                        class="panel-card settings-section <?= $activeTab === 'perfil' ? 'is-active' : '' ?>"
                        data-settings-panel="perfil"
                        role="tabpanel"
                        aria-labelledby="tab-perfil-title"
                    >
                        <div class="panel-header">
                            <h2 id="tab-perfil-title" class="panel-title">Dados de Perfil</h2>
                            <span class="badge badge-neutral">Conta Autenticada</span>
                        </div>
                        <div class="panel-body">
                            <form action="settings.php" method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="settings_section" value="perfil">

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="profileName" class="form-label">Nome de exibição</label>
                                        <input
                                            type="text"
                                            id="profileName"
                                            name="name"
                                            class="form-control"
                                            value="<?= e($userProfile['name']) ?>"
                                            required
                                        >
                                    </div>

                                    <div class="form-group">
                                        <label for="profileEmail" class="form-label">E-mail principal</label>
                                        <input
                                            type="email"
                                            id="profileEmail"
                                            name="email"
                                            class="form-control"
                                            value="<?= e($userProfile['email']) ?>"
                                            required
                                        >
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="profileTimezone" class="form-label">Fuso horário do painel</label>
                                    <select id="profileTimezone" class="form-control" disabled>
                                        <option selected>America/Sao_Paulo (GMT-03:00 — Horário de Brasília)</option>
                                    </select>
                                    <span class="form-hint">Padronizado automaticamente para registros de criação de projetos.</span>
                                </div>

                                <div style="display: flex; justify-content: flex-end; margin-top: 16px;">
                                    <button type="submit" class="btn btn-primary">Salvar alterações de perfil</button>
                                </div>
                            </form>
                        </div>
                    </section>

                    <!-- 2. Aba Preferências -->
                    <section
                        class="panel-card settings-section <?= $activeTab === 'preferencias' ? 'is-active' : '' ?>"
                        data-settings-panel="preferencias"
                        role="tabpanel"
                        aria-labelledby="tab-pref-title"
                    >
                        <div class="panel-header">
                            <h2 id="tab-pref-title" class="panel-title">Preferências Padrão de Projetos</h2>
                            <span class="badge badge-violet">Automação de Formulário</span>
                        </div>
                        <div class="panel-body">
                            <form action="settings.php" method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="settings_section" value="preferencias">

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="prefAspectRatio" class="form-label">Formato padrão de saída</label>
                                        <select id="prefAspectRatio" name="default_aspect_ratio" class="form-control">
                                            <option value="9:16" <?= ($userProfile['default_aspect_ratio'] ?? '9:16') === '9:16' ? 'selected' : '' ?>>Vertical 9:16 (Reels, Shorts, TikTok)</option>
                                            <option value="1:1" <?= ($userProfile['default_aspect_ratio'] ?? '') === '1:1' ? 'selected' : '' ?>>Quadrado 1:1 (Feed)</option>
                                            <option value="16:9" <?= ($userProfile['default_aspect_ratio'] ?? '') === '16:9' ? 'selected' : '' ?>>Horizontal 16:9</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label for="prefClipsCount" class="form-label">Quantidade padrão de cortes</label>
                                        <input
                                            type="number"
                                            id="prefClipsCount"
                                            name="default_clips_count"
                                            class="form-control"
                                            min="1"
                                            max="15"
                                            value="<?= (int)($userProfile['default_clips_count'] ?? 5) ?>"
                                        >
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="prefMinDuration" class="form-label">Duração mínima padrão (segundos)</label>
                                        <input
                                            type="number"
                                            id="prefMinDuration"
                                            name="default_min_duration"
                                            class="form-control"
                                            min="10"
                                            max="170"
                                            value="<?= (int)($userProfile['default_min_duration'] ?? 30) ?>"
                                        >
                                    </div>

                                    <div class="form-group">
                                        <label for="prefMaxDuration" class="form-label">Duração máxima padrão (segundos)</label>
                                        <input
                                            type="number"
                                            id="prefMaxDuration"
                                            name="default_max_duration"
                                            class="form-control"
                                            min="15"
                                            max="180"
                                            value="<?= (int)($userProfile['default_max_duration'] ?? 60) ?>"
                                        >
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="checkbox-label" for="prefEmailNotif">
                                        <input
                                            type="checkbox"
                                            id="prefEmailNotif"
                                            name="email_notifications"
                                            value="1"
                                            <?= !empty($userProfile['email_notifications']) ? 'checked' : '' ?>
                                        >
                                        <span>Receber aviso por e-mail quando o processamento externo de um projeto for concluído.</span>
                                    </label>
                                </div>

                                <div style="display: flex; justify-content: flex-end; margin-top: 16px;">
                                    <button type="submit" class="btn btn-primary">Salvar preferências</button>
                                </div>
                            </form>
                        </div>
                    </section>

                    <!-- 3. Aba Segurança -->
                    <section
                        class="panel-card settings-section <?= $activeTab === 'seguranca' ? 'is-active' : '' ?>"
                        data-settings-panel="seguranca"
                        role="tabpanel"
                        aria-labelledby="tab-sec-title"
                    >
                        <div class="panel-header">
                            <h2 id="tab-sec-title" class="panel-title">Segurança e Senha</h2>
                            <span class="badge badge-neutral">Proteção</span>
                        </div>
                        <div class="panel-body">
                            <form action="settings.php" method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="settings_section" value="seguranca">

                                <div class="form-group">
                                    <label for="secCurrentPass" class="form-label">Senha atual</label>
                                    <input
                                        type="password"
                                        id="secCurrentPass"
                                        name="current_password"
                                        class="form-control"
                                        placeholder="Digite sua senha atual"
                                        required
                                    >
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="secNewPass" class="form-label">Nova senha</label>
                                        <input
                                            type="password"
                                            id="secNewPass"
                                            name="new_password"
                                            class="form-control"
                                            placeholder="Mínimo de 8 caracteres (letras e números)"
                                            required
                                        >
                                    </div>

                                    <div class="form-group">
                                        <label for="secConfirmPass" class="form-label">Confirmar nova senha</label>
                                        <input
                                            type="password"
                                            id="secConfirmPass"
                                            name="confirm_password"
                                            class="form-control"
                                            placeholder="Repita a nova senha"
                                            required
                                        >
                                    </div>
                                </div>

                                <div style="display: flex; justify-content: flex-end; margin-top: 16px;">
                                    <button type="submit" class="btn btn-primary">Atualizar senha</button>
                                </div>
                            </form>
                        </div>
                    </section>

                    <!-- 4. Aba Espaço Reservado para Consumo e Limites de Uso -->
                    <section
                        class="panel-card settings-section <?= $activeTab === 'consumo' ? 'is-active' : '' ?>"
                        data-settings-panel="consumo"
                        role="tabpanel"
                        aria-labelledby="tab-usage-title"
                    >
                        <div class="panel-header">
                            <h2 id="tab-usage-title" class="panel-title">Consumo, Orçamento e Limites de Uso</h2>
                            <span class="badge badge-violet">Uso Exclusivo do Proprietário</span>
                        </div>
                        <div class="panel-body">
                            <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 20px;">
                                Métricas reais registradas no banco de dados. A análise local com <code>faster-whisper</code> tem custo zero e a API paga permanece desativada por padrão.
                            </p>

                            <div class="usage-meter-card">
                                <div class="usage-meter-header">
                                    <strong>Projetos Criados na Conta</strong>
                                    <span class="text-muted"><?= (int)$stats['total_projects'] ?> projeto(s)</span>
                                </div>
                                <div class="usage-bar-track">
                                    <div class="usage-bar-fill" style="width: <?= min(100, ((int)$stats['total_projects']) * 10) ?>%;"></div>
                                </div>
                                <span class="form-hint">Jobs aguardando worker Python único: <?= (int)$stats['pending_jobs'] ?></span>
                            </div>

                            <div class="usage-meter-card">
                                <div class="usage-meter-header">
                                    <strong>Cortes Verticais 9:16 Gerados</strong>
                                    <span class="text-muted"><?= (int)$stats['total_clips_generated'] ?> corte(s)</span>
                                </div>
                                <div class="usage-bar-track">
                                    <div class="usage-bar-fill" style="width: <?= min(100, ((int)$stats['total_clips_generated']) * 5) ?>%;"></div>
                                </div>
                                <span class="form-hint">Contabiliza os registros na tabela clip_candidates vinculados ao proprietário.</span>
                            </div>

                            <div class="usage-meter-card">
                                <div class="usage-meter-header">
                                    <strong>Orçamento Mensal Compartilhado (<?= e((string)$budgetSnapshot['month_key']) ?>)</strong>
                                    <span class="text-muted">R$ <?= number_format((float)$budgetSnapshot['spent_brl'], 2, ',', '.') ?> / R$ <?= number_format((float)MAX_MONTHLY_COST_BRL, 2, ',', '.') ?></span>
                                </div>
                                <div class="usage-bar-track">
                                    <div class="usage-bar-fill" style="width: <?= min(100, (int)round((((float)$budgetSnapshot['spent_brl'] + (float)$budgetSnapshot['reserved_brl']) / max(1.0, (float)MAX_MONTHLY_COST_BRL)) * 100)) ?>%;"></div>
                                </div>
                                <span class="form-hint">Reservado em trânsito: R$ <?= number_format((float)$budgetSnapshot['reserved_brl'], 2, ',', '.') ?> • Limite por tarefa: R$ <?= number_format((float)MAX_COST_PER_JOB_BRL, 2, ',', '.') ?></span>
                            </div>

                            <div class="summary-spec-list">
                                <div class="summary-spec-item">
                                    <span>Modo de operação:</span>
                                    <strong>Proprietário Único (Cadastro público desativado)</strong>
                                </div>
                                <div class="summary-spec-item">
                                    <span>Status do Banco de Dados:</span>
                                    <strong><?= Database::isConnected() ? 'Conectado via PDO (Prepared Statements ativos)' : 'Desconectado' ?></strong>
                                </div>
                                <div class="summary-spec-item">
                                    <span>Serviço Externo de IA (Python):</span>
                                    <strong class="text-accent">Worker único separado (faster-whisper + heurística local padrão)</strong>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </main>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
