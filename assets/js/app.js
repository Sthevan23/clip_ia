/**
 * ClipIA — Interações de Interface (JavaScript Puro / Vanilla JS)
 * Validação em conjunto com o Backend PHP + Pré-visualização Oficial YouTube (oEmbed / IFrame)
 */

(function () {
    'use strict';

    const ClipIA = {
        /**
         * Exibe mensagens de erro, sucesso, aviso ou carregamento na interface
         */
        showFeedback(type, title, message) {
            const container = document.getElementById('ui-feedback-container');
            if (!container) return;

            const role = (type === 'error' || type === 'warning') ? 'alert' : 'status';
            const iconSvg = type === 'loading'
                ? '<span class="spinner" aria-hidden="true"></span>'
                : this.getIconSvg(type);

            const alertEl = document.createElement('div');
            alertEl.className = `alert alert-${type}`;
            alertEl.setAttribute('role', role);
            alertEl.innerHTML = `
                <div class="alert-icon">${iconSvg}</div>
                <div class="alert-content">
                    <strong class="alert-title">${this.escapeHtml(title)}</strong>
                    <p class="alert-message">${this.escapeHtml(message)}</p>
                </div>
                <button type="button" class="alert-close" data-dismiss="alert" aria-label="Fechar mensagem">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            `;

            container.innerHTML = '';
            container.appendChild(alertEl);
            alertEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        },

        escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        },

        getIconSvg(type) {
            if (type === 'success') {
                return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>';
            }
            if (type === 'error') {
                return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>';
            }
            if (type === 'warning') {
                return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>';
            }
            return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
        }
    };

    window.ClipIA = ClipIA;

    document.addEventListener('DOMContentLoaded', () => {
        /* 1. Fechamento de Alertas */
        document.addEventListener('click', (e) => {
            const closeBtn = e.target.closest('[data-dismiss="alert"]');
            if (closeBtn) {
                const alert = closeBtn.closest('.alert');
                if (alert) alert.remove();
            }
        });

        /* 2. Menu Mobile (Landing Page) */
        const publicMenuToggle = document.getElementById('publicMenuToggle');
        const publicNav = document.getElementById('publicNav');
        if (publicMenuToggle && publicNav) {
            publicMenuToggle.addEventListener('click', () => {
                const isOpen = publicNav.classList.toggle('is-open');
                publicMenuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
        }

        /* 3. Menu Lateral Responsivo (Dashboard / Painel) */
        const sidebar = document.getElementById('appSidebar');
        const sidebarOpenBtn = document.getElementById('sidebarOpenBtn');
        const sidebarCloseBtn = document.getElementById('sidebarCloseBtn');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');

        function closeSidebar() {
            if (!sidebar) return;
            sidebar.classList.remove('is-open');
            if (sidebarBackdrop) sidebarBackdrop.classList.remove('is-open');
            if (sidebarOpenBtn) sidebarOpenBtn.setAttribute('aria-expanded', 'false');
        }

        if (sidebarOpenBtn && sidebar) {
            sidebarOpenBtn.addEventListener('click', () => {
                sidebar.classList.add('is-open');
                if (sidebarBackdrop) sidebarBackdrop.classList.add('is-open');
                sidebarOpenBtn.setAttribute('aria-expanded', 'true');
            });
        }
        if (sidebarCloseBtn) sidebarCloseBtn.addEventListener('click', closeSidebar);
        if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', closeSidebar);

        /* 4. Alternar Visibilidade de Senha */
        document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-toggle-password');
                const input = document.getElementById(targetId);
                if (!input) return;

                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                btn.setAttribute('aria-label', isPassword ? 'Ocultar senha' : 'Mostrar senha');
                btn.setAttribute('aria-pressed', isPassword ? 'true' : 'false');

                const eyeOpen = btn.querySelector('.icon-eye-open');
                const eyeOff = btn.querySelector('.icon-eye-off');
                if (eyeOpen && eyeOff) {
                    eyeOpen.style.display = isPassword ? 'none' : 'block';
                    eyeOff.style.display = isPassword ? 'block' : 'none';
                }
            });
        });

        /* 5. Indicador Opcional de Força de Senha */
        const registerPassword = document.getElementById('registerPassword');
        const strengthBars = document.querySelectorAll('#passwordStrengthBars .strength-bar');
        const strengthLabel = document.getElementById('passwordStrengthLabel');

        if (registerPassword && strengthBars.length === 4) {
            registerPassword.addEventListener('input', () => {
                const val = registerPassword.value;
                let score = 0;
                if (val.length >= 8) score++;
                if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
                if (/[0-9]/.test(val)) score++;
                if (/[^A-Za-z0-9]/.test(val) || val.length >= 12) score++;

                const colors = ['#EF4444', '#F59E0B', '#8B5CF6', '#10B981'];
                const labels = ['Muito curta', 'Fraca', 'Boa', 'Forte', 'Excelente'];

                strengthBars.forEach((bar, index) => {
                    bar.style.backgroundColor = index < score ? colors[Math.max(0, score - 1)] : 'var(--border-default)';
                });
                if (strengthLabel) {
                    strengthLabel.textContent = val.length === 0 ? 'Mín. 8 caracteres' : labels[score];
                }
            });
        }

        /* 6. Novo Projeto — Validação Server-Side da URL do YouTube + Player Incorporado + Modo de Fonte */
        const validateYoutubeBtn = document.getElementById('validateYoutubeBtn');
        const youtubeUrlInput = document.getElementById('youtubeUrl');
        const csrfTokenInput = document.getElementById('csrfTokenInput');
        const previewImg = document.getElementById('videoPreviewImg');
        const embedFrame = document.getElementById('videoEmbedFrame');
        const previewTitle = document.getElementById('videoPreviewTitle');
        const previewIdLabel = document.getElementById('videoPreviewId');
        const previewAuthorLabel = document.getElementById('videoPreviewAuthor');
        const previewStatusBadge = document.getElementById('videoPreviewStatusBadge');
        const hiddenVideoId = document.getElementById('videoExternalId');
        const hiddenVideoTitle = document.getElementById('videoDetectedTitle');

        async function validateYouTubeLinkOnServer() {
            if (!youtubeUrlInput) return false;
            const rawUrl = youtubeUrlInput.value.trim();

            if (!rawUrl) {
                ClipIA.showFeedback('error', 'URL obrigatória', 'Cole um link do YouTube antes de clicar em validar.');
                return false;
            }

            if (validateYoutubeBtn) {
                validateYoutubeBtn.disabled = true;
                validateYoutubeBtn.classList.add('is-loading');
            }

            ClipIA.showFeedback('loading', 'Validando URL no servidor...', 'Verificando domínio autorizado, identificador do vídeo e consultando metadados públicos.');

            try {
                const formData = new URLSearchParams();
                formData.set('csrf_token', csrfTokenInput ? csrfTokenInput.value : '');
                formData.set('youtube_url', rawUrl);

                const response = await fetch('pages/validate-youtube.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json'
                    },
                    body: formData.toString()
                });

                const data = await response.json();

                if (!response.ok || !data || !data.ok) {
                    const errMsg = (data && data.error) ? data.error : 'URL rejeitada pelo servidor.';
                    ClipIA.showFeedback('error', 'Link do YouTube inválido ou não permitido', errMsg);
                    if (previewStatusBadge) {
                        previewStatusBadge.className = 'badge badge-danger';
                        previewStatusBadge.textContent = 'Link Rejeitado';
                    }
                    if (embedFrame) {
                        embedFrame.src = 'about:blank';
                        embedFrame.style.display = 'none';
                    }
                    if (previewImg) {
                        previewImg.style.display = 'block';
                    }
                    return false;
                }

                // Atualiza título, miniatura e player incorporado
                if (previewTitle) previewTitle.textContent = data.title || `Vídeo (${data.video_id})`;
                if (previewIdLabel) previewIdLabel.textContent = `ID do Vídeo: ${data.video_id}`;
                if (previewAuthorLabel) {
                    previewAuthorLabel.textContent = data.author_name ? `Canal: ${data.author_name}` : 'YouTube Oficial';
                }

                if (data.embed_allowed && embedFrame) {
                    embedFrame.src = data.embed_url;
                    embedFrame.style.display = 'block';
                    if (previewImg) previewImg.style.display = 'none';
                } else {
                    if (embedFrame) {
                        embedFrame.src = 'about:blank';
                        embedFrame.style.display = 'none';
                    }
                    if (previewImg) {
                        previewImg.src = data.thumbnail_url;
                        previewImg.style.display = 'block';
                    }
                }

                if (previewStatusBadge) {
                    previewStatusBadge.className = 'badge badge-success';
                    previewStatusBadge.textContent = data.embed_allowed ? 'Player Ativo' : 'URL Validada';
                }

                if (hiddenVideoId) hiddenVideoId.value = data.video_id;
                if (hiddenVideoTitle) hiddenVideoTitle.value = data.title || '';

                const projectNameInput = document.getElementById('projectName');
                if (projectNameInput && projectNameInput.value.trim() === '' && data.title) {
                    projectNameInput.value = data.title.substring(0, 120);
                }

                ClipIA.showFeedback(
                    'success',
                    'Link do YouTube validado no servidor',
                    data.legal_notice || 'URL validada e pré-visualização carregada.'
                );
                return true;
            } catch (_) {
                ClipIA.showFeedback('error', 'Falha de comunicação', 'Não foi possível consultar o validador no servidor. Verifique sua conexão.');
                return false;
            } finally {
                if (validateYoutubeBtn) {
                    validateYoutubeBtn.disabled = false;
                    validateYoutubeBtn.classList.remove('is-loading');
                }
            }
        }

        if (validateYoutubeBtn) {
            validateYoutubeBtn.addEventListener('click', validateYouTubeLinkOnServer);
        }

        /* Controle de exibição do campo de Upload de Arquivo Autorizado */
        const sourceModeInputs = document.querySelectorAll('input[name="source_mode"]');
        const uploadContainer = document.getElementById('authorizedUploadContainer');
        const summaryInitialState = document.getElementById('summaryInitialState');

        function updateSourceModeUI() {
            const selected = document.querySelector('input[name="source_mode"]:checked');
            const isUpload = selected && selected.value === 'uploaded_file';
            if (uploadContainer) {
                uploadContainer.style.display = isUpload ? 'block' : 'none';
            }
            if (summaryInitialState) {
                summaryInitialState.textContent = isUpload ? 'Aguardando processamento' : 'Aguardando fonte';
            }
        }

        sourceModeInputs.forEach((input) => {
            input.addEventListener('change', updateSourceModeUI);
        });
        updateSourceModeUI();

        /* Sincronização do Resumo de Configuração em Novo Projeto */
        const clipsCountInput = document.getElementById('clipsCount');
        const minDurationInput = document.getElementById('minDuration');
        const maxDurationInput = document.getElementById('maxDuration');
        const aspectInputs = document.querySelectorAll('input[name="aspect_ratio"]');

        const summaryCount = document.getElementById('summaryClipsCount');
        const summaryDuration = document.getElementById('summaryDurationRange');
        const summaryFormat = document.getElementById('summaryFormat');

        function updateProjectSummary() {
            if (summaryCount && clipsCountInput) {
                summaryCount.textContent = `${clipsCountInput.value} cortes`;
            }
            if (summaryDuration && minDurationInput && maxDurationInput) {
                summaryDuration.textContent = `${minDurationInput.value}s até ${maxDurationInput.value}s`;
            }
            if (summaryFormat && aspectInputs.length) {
                const checked = document.querySelector('input[name="aspect_ratio"]:checked');
                if (checked) {
                    summaryFormat.textContent = checked.value === '9:16' ? 'Vertical 9:16 (Reels/Shorts/TikTok)' : checked.value;
                }
            }
        }

        [clipsCountInput, minDurationInput, maxDurationInput].forEach((el) => {
            if (el) el.addEventListener('input', updateProjectSummary);
        });
        aspectInputs.forEach((el) => el.addEventListener('change', updateProjectSummary));

        /* 7. Meus Projetos — Filtro de Busca, Modal com Player Incorporado e Exclusão */
        const projectSearchInput = document.getElementById('projectSearchInput');
        const projectStatusFilter = document.getElementById('projectStatusFilter');
        const projectRows = document.querySelectorAll('[data-project-row]');

        function filterProjectsTable() {
            const term = (projectSearchInput?.value || '').toLowerCase().trim();
            const status = projectStatusFilter?.value || 'all';

            projectRows.forEach((row) => {
                const title = (row.getAttribute('data-project-title') || '').toLowerCase();
                const rowStatus = row.getAttribute('data-project-status') || '';
                const matchesTerm = !term || title.includes(term);
                const matchesStatus = status === 'all' || rowStatus === status;
                row.style.display = (matchesTerm && matchesStatus) ? '' : 'none';
            });
        }

        if (projectSearchInput) projectSearchInput.addEventListener('input', filterProjectsTable);
        if (projectStatusFilter) projectStatusFilter.addEventListener('change', filterProjectsTable);

        // Modal de Detalhes do Projeto + Player Incorporado + Upload de Fonte
        const projectDetailModal = document.getElementById('projectDetailModal');
        const modalEmbedFrame = document.getElementById('modalEmbedFrame');
        const modalThumbFallback = document.getElementById('modalThumbFallback');
        const modalAttachForm = document.getElementById('modalAttachSourceForm');
        const modalAttachProjectId = document.getElementById('modalAttachProjectId');
        const modalGenerateForm = document.getElementById('modalGenerateClipsForm');
        const modalGenerateProjectId = document.getElementById('modalGenerateProjectId');
        const modalDirectMediaUrl = document.getElementById('modalDirectMediaUrl');

        document.querySelectorAll('[data-open-project]').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (!projectDetailModal) return;

                const projId = btn.getAttribute('data-open-project') || '';
                const title = btn.getAttribute('data-title') || '';
                const url = btn.getAttribute('data-url') || '';
                const videoId = btn.getAttribute('data-video-id') || '—';
                const embedUrl = btn.getAttribute('data-embed-url') || '';
                const embedAllowed = btn.getAttribute('data-embed-allowed') === '1';
                const thumbUrl = btn.getAttribute('data-thumb-url') || '';
                const format = btn.getAttribute('data-format') || '9:16';
                const clips = btn.getAttribute('data-clips') || '—';
                const statusKey = btn.getAttribute('data-status-key') || 'awaiting_source';
                const statusLabel = btn.getAttribute('data-status-label') || '';
                const uploadedFile = btn.getAttribute('data-uploaded-file') || '';

                document.getElementById('modalProjectTitle').textContent = title;
                document.getElementById('modalProjectUrl').textContent = url;
                document.getElementById('modalProjectVideoId').textContent = videoId;
                document.getElementById('modalProjectFormat').textContent = format;
                document.getElementById('modalProjectClips').textContent = clips;
                document.getElementById('modalProjectStatus').textContent = statusLabel;
                const sourceMode = btn.getAttribute('data-source-mode') || 'url_only';
                const authorizedMediaUrl = btn.getAttribute('data-authorized-media-url') || '';
                let sourceLabel = 'Pendente (referência YouTube — envie arquivo ou URL HTTPS direta)';
                if (uploadedFile !== '') {
                    sourceLabel = `Arquivo enviado (${uploadedFile})`;
                } else if (sourceMode === 'direct_media_url' && authorizedMediaUrl !== '') {
                    const shortUrl = authorizedMediaUrl.length > 52
                        ? `${authorizedMediaUrl.substring(0, 52)}…`
                        : authorizedMediaUrl;
                    sourceLabel = `URL de mídia autorizada (${shortUrl})`;
                }
                document.getElementById('modalProjectSourceFile').textContent = sourceLabel;

                if (embedAllowed && embedUrl && modalEmbedFrame) {
                    modalEmbedFrame.src = embedUrl;
                    modalEmbedFrame.style.display = 'block';
                    if (modalThumbFallback) modalThumbFallback.style.display = 'none';
                } else {
                    if (modalEmbedFrame) {
                        modalEmbedFrame.src = 'about:blank';
                        modalEmbedFrame.style.display = 'none';
                    }
                    if (modalThumbFallback) {
                        modalThumbFallback.src = thumbUrl || 'assets/img/placeholder-video.svg';
                        modalThumbFallback.style.display = 'block';
                    }
                }

                if (modalAttachForm && modalAttachProjectId) {
                    modalAttachProjectId.value = projId;
                    modalAttachForm.style.display = (statusKey === 'awaiting_source') ? 'block' : 'none';
                }

                const showGenerate = ['awaiting_source', 'awaiting_processing', 'failed'].includes(statusKey);
                if (modalGenerateForm && modalGenerateProjectId) {
                    modalGenerateProjectId.value = projId;
                    modalGenerateForm.style.display = showGenerate ? 'block' : 'none';
                }
                if (modalDirectMediaUrl) {
                    modalDirectMediaUrl.value = authorizedMediaUrl;
                }

                projectDetailModal.classList.add('is-open');
            });
        });

        // Modal de Exclusão de Projeto
        const projectDeleteModal = document.getElementById('projectDeleteModal');
        const deleteProjectIdInput = document.getElementById('deleteProjectIdInput');
        const deleteProjectTitleSpan = document.getElementById('deleteProjectTitleSpan');

        document.querySelectorAll('[data-delete-project]').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (!projectDeleteModal) return;
                const id = btn.getAttribute('data-delete-project');
                const title = btn.getAttribute('data-title') || 'este projeto';
                if (deleteProjectIdInput) deleteProjectIdInput.value = id;
                if (deleteProjectTitleSpan) deleteProjectTitleSpan.textContent = title;
                projectDeleteModal.classList.add('is-open');
            });
        });

        document.querySelectorAll('[data-close-modal]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const modal = btn.closest('.modal-backdrop');
                if (modal) {
                    modal.classList.remove('is-open');
                    if (modalEmbedFrame) modalEmbedFrame.src = 'about:blank';
                }
            });
        });

        /* 8. Configurações — Navegação por Abas */
        const tabButtons = document.querySelectorAll('[data-settings-tab]');
        const tabSections = document.querySelectorAll('[data-settings-panel]');

        function activateSettingsTab(tabId) {
            tabButtons.forEach((btn) => {
                const isMatch = btn.getAttribute('data-settings-tab') === tabId;
                btn.classList.toggle('is-active', isMatch);
                btn.setAttribute('aria-selected', isMatch ? 'true' : 'false');
            });
            tabSections.forEach((sec) => {
                const isMatch = sec.getAttribute('data-settings-panel') === tabId;
                sec.classList.toggle('is-active', isMatch);
            });
        }

        if (tabButtons.length > 0) {
            tabButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    const target = btn.getAttribute('data-settings-tab');
                    activateSettingsTab(target);
                });
            });

            const hash = window.location.hash.replace('#', '');
            if (hash && document.querySelector(`[data-settings-panel="${hash}"]`)) {
                activateSettingsTab(hash);
            }
        }

        /* 9. Cópia de Metadados (Título, Descrição Curta e Hashtags) para a Área de Transferência */
        async function copyTextWithFeedback(textToCopy, buttonEl, labelWhenCopied = 'Copiado!') {
            if (!textToCopy) return;
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(textToCopy);
                } else {
                    const tempArea = document.createElement('textarea');
                    tempArea.value = textToCopy;
                    document.body.appendChild(tempArea);
                    tempArea.select();
                    document.execCommand('copy');
                    document.body.removeChild(tempArea);
                }
                const spanEl = buttonEl.querySelector('span');
                const originalLabel = spanEl ? spanEl.textContent : '';
                if (spanEl) spanEl.textContent = labelWhenCopied;
                ClipIA.showFeedback('success', 'Copiado para a área de transferência', textToCopy.substring(0, 120) + (textToCopy.length > 120 ? '...' : ''));
                setTimeout(() => {
                    if (spanEl && originalLabel) spanEl.textContent = originalLabel;
                }, 1800);
            } catch (_) {
                ClipIA.showFeedback('error', 'Falha ao copiar', 'Selecione o texto manualmente e pressione Ctrl+C.');
            }
        }

        document.querySelectorAll('[data-copy-target]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-copy-target');
                const inputEl = targetId ? document.getElementById(targetId) : null;
                if (inputEl) {
                    copyTextWithFeedback((inputEl.value || '').trim(), btn);
                }
            });
        });

        document.querySelectorAll('[data-copy-bundle]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const candId = btn.getAttribute('data-copy-bundle');
                const titleEl = document.getElementById(`candTitle_${candId}`);
                const descEl = document.getElementById(`candDesc_${candId}`);
                const tagsEl = document.getElementById(`candTags_${candId}`);
                const bundle = [
                    titleEl ? titleEl.value.trim() : '',
                    descEl ? descEl.value.trim() : '',
                    tagsEl ? tagsEl.value.trim() : ''
                ].filter(Boolean).join('\n\n');
                copyTextWithFeedback(bundle, btn, 'Tudo copiado!');
            });
        });

        // Estúdio — polling de progresso (sem expor segredos)
        const studioRoot = document.getElementById('projectStudioRoot');
        if (studioRoot && studioRoot.getAttribute('data-poll-active') === '1') {
            const projectId = studioRoot.getAttribute('data-project-id');
            const progressFill = document.getElementById('studioProgressFill');
            const statusLabel = document.getElementById('studioStatusLabel');
            const errorBox = document.getElementById('studioErrorBox');

            const poll = async () => {
                if (!projectId) return;
                try {
                    const resp = await fetch(`pages/project-status.php?project_id=${encodeURIComponent(projectId)}`, {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    });
                    const data = await resp.json();
                    if (!data.ok || !data.snapshot) return;
                    const snap = data.snapshot;
                    if (progressFill) {
                        progressFill.style.width = `${snap.progress_percent}%`;
                    }
                    if (statusLabel) {
                        statusLabel.textContent = snap.status_label || snap.status;
                    }
                    if (errorBox && snap.status === 'failed' && snap.last_error) {
                        errorBox.style.display = '';
                        errorBox.textContent = snap.last_error;
                    }
                    if (snap.status === 'completed' || snap.status === 'failed') {
                        window.location.reload();
                    }
                } catch (_) {
                    /* silencioso — próxima tentativa */
                }
            };

            poll();
            window.setInterval(poll, 8000);
        }
    });
})();
