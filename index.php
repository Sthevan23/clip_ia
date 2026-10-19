<?php
declare(strict_types=1);

/**
 * ClipIA — 1. Landing Page Oficial
 */

$pageTitle = 'Encontre os Melhores Momentos e Crie Cortes Verticais com IA';
$layoutType = 'public';

require_once __DIR__ . '/includes/header.php';
?>

<main id="main-content">
    <!-- 1. Apresentação do ClipIA (Hero) -->
    <section class="hero-section" aria-labelledby="hero-heading">
        <div class="container hero-grid">
            <div class="hero-content">
                <div class="hero-eyebrow">
                    <?= clipia_icon('sparkles', 16) ?>
                    <span>Plataforma SaaS para Criadores, Podcasters e Equipes</span>
                </div>

                <h1 id="hero-heading" class="hero-title">
                    Transforme vídeos longos em <span class="text-accent">cortes verticais</span> prontos para engajar.
                </h1>

                <p class="hero-description">
                    O ClipIA estrutura a análise dos momentos mais relevantes de vídeos longos, podcasts e aulas para gerar recortes no formato 9:16 ideais para Reels, YouTube Shorts e TikTok.
                </p>

                <div class="hero-cta-group">
                    <a href="login.php" class="btn btn-primary btn-lg">
                        <span>Acessar painel administrativo</span>
                        <?= clipia_icon('arrow-right', 18) ?>
                    </a>
                </div>

                <div class="hero-meta">
                    <span class="hero-meta-item">
                        <?= clipia_icon('smartphone', 16) ?>
                        <span>Foco em formato vertical 9:16</span>
                    </span>
                    <span class="hero-meta-item">
                        <?= clipia_icon('sliders', 16) ?>
                        <span>Duração e quantidade configuráveis</span>
                    </span>
                    <span class="hero-meta-item">
                        <?= clipia_icon('shield', 16) ?>
                        <span>Fluxo transparente com fontes autorizadas</span>
                    </span>
                </div>
            </div>

            <!-- Prévia Ilustrativa da Interface (Claramente marcada como Demonstração Visual) -->
            <div class="hero-showcase-card" aria-label="Ilustração visual do fluxo de corte do ClipIA">
                <div class="showcase-top">
                    <div class="window-dots" aria-hidden="true">
                        <span></span><span></span><span></span>
                    </div>
                    <span class="badge badge-violet">Demonstração Visual (Exemplo)</span>
                </div>

                <div class="showcase-body">
                    <div class="showcase-source">
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                                <span style="font-size:0.78rem; font-weight:600;">Vídeo Original (16:9)</span>
                                <span class="badge badge-neutral">01:14:20</span>
                            </div>
                            <div class="source-video-mock">
                                <div class="crop-frame-indicator">
                                    <?= clipia_icon('smartphone', 18) ?>
                                    <span style="margin-top:4px;">9:16</span>
                                </div>
                            </div>
                        </div>

                        <div class="timeline-bars">
                            <div style="display:flex; justify-content:space-between; font-size:0.74rem; color:var(--text-secondary);">
                                <span>Momentos de retenção (Exemplo)</span>
                                <span class="text-accent">3 trechos sugeridos</span>
                            </div>
                            <div class="timeline-track">
                                <span class="timeline-highlight" style="left: 14%; width: 16%;"></span>
                                <span class="timeline-highlight" style="left: 46%; width: 20%;"></span>
                                <span class="timeline-highlight" style="left: 76%; width: 14%;"></span>
                            </div>
                            <div style="display:flex; justify-content:space-between; font-size:0.7rem; color:var(--text-muted);">
                                <span>00:00</span>
                                <span>18:40</span>
                                <span>42:15</span>
                                <span>01:14:20</span>
                            </div>
                        </div>
                    </div>

                    <div class="showcase-vertical-preview">
                        <span style="font-size:0.74rem; font-weight:700; color:var(--accent-light); margin-bottom:8px;">Saída 9:16</span>
                        <div class="phone-frame">
                            <div style="display:flex; justify-content:space-between; font-size:0.65rem; color:var(--text-muted);">
                                <span>Corte #01</span>
                                <span>48s</span>
                            </div>
                            <div style="margin:auto 0;">
                                <div class="mock-speaker-circle">
                                    <?= clipia_icon('play', 18) ?>
                                </div>
                            </div>
                            <div class="phone-caption-mock">
                                "O segredo da retenção nos primeiros 3 segundos..."
                            </div>
                        </div>
                        <span style="font-size:0.68rem; color:var(--text-muted); margin-top:8px;">Simulação ilustrativa</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 2. Explicação de Como Funciona -->
    <section id="como-funciona" class="section" aria-labelledby="how-heading">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">Fluxo de Trabalho</span>
                <h2 id="how-heading" class="section-title">Como o ClipIA funciona</h2>
                <p class="section-subtitle">
                    Um processo estruturado em quatro etapas para configurar seus parâmetros de corte e organizar seus projetos em um único painel.
                </p>
            </div>

            <div class="grid-4">
                <article class="card card-interactive">
                    <span class="step-number">01</span>
                    <h3 class="card-title">Identifique o Vídeo</h3>
                    <p class="card-text">
                        Crie um projeto e cole o link de referência do YouTube para validar o identificador, título e miniatura do conteúdo autorizado.
                    </p>
                </article>

                <article class="card card-interactive">
                    <span class="step-number">02</span>
                    <h3 class="card-title">Defina os Parâmetros</h3>
                    <p class="card-text">
                        Escolha a quantidade desejada de cortes, o intervalo de duração mínima e máxima (em segundos) e o enquadramento vertical 9:16.
                    </p>
                </article>

                <article class="card card-interactive">
                    <span class="step-number">03</span>
                    <h3 class="card-title">Processamento Externo</h3>
                    <p class="card-text">
                        A aplicação web envia as especificações para o serviço dedicado de processamento em Python (integração das próximas etapas).
                    </p>
                </article>

                <article class="card card-interactive">
                    <span class="step-number">04</span>
                    <h3 class="card-title">Revise e Publique</h3>
                    <p class="card-text">
                        Gerencie os cortes gerados no painel, acompanhe o status de cada projeto e exporte seus vídeos curtos para as redes sociais.
                    </p>
                </article>
            </div>
        </div>
    </section>

    <!-- 3. Demonstração Visual de Cortes (Identificados como Exemplos) -->
    <section id="demonstracao" class="section section-surface" aria-labelledby="demo-heading">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">Demonstração Visual</span>
                <h2 id="demo-heading" class="section-title">Exemplos de estruturação de cortes</h2>
                <p class="section-subtitle">
                    Veja abaixo simulações ilustrativas de como os cortes são organizados com minutagem, duração e formato vertical 9:16.
                </p>
            </div>

            <div class="demo-clips-grid">
                <!-- Exemplo 1 -->
                <article class="demo-clip-card">
                    <div class="demo-clip-visual">
                        <div class="vertical-mock-screen">
                            <div class="mock-top-badges">
                                <span class="badge badge-violet">9:16</span>
                                <span>00:42s</span>
                            </div>
                            <div class="mock-speaker-circle">
                                <?= clipia_icon('video', 22) ?>
                            </div>
                            <div>
                                <div class="mock-waveform" aria-hidden="true">
                                    <span style="height:8px"></span><span style="height:18px"></span><span style="height:12px"></span><span style="height:22px"></span><span style="height:14px"></span><span style="height:20px"></span><span style="height:10px"></span><span style="height:16px"></span>
                                </div>
                                <div class="phone-caption-mock" style="margin-top:8px; text-align:center;">
                                     Gancho de abertura + argumento central
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="demo-clip-info">
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <span class="badge badge-neutral">Exemplo Demonstrativo #1</span>
                                <span style="font-size:0.78rem; color:var(--accent-light); font-weight:600;">Podcast / Entrevista</span>
                            </div>
                            <h3 class="card-title">Corte de Pergunta e Resposta Direta</h3>
                            <p class="card-text">
                                Ideal para destacar insights rápidos de convidados em episódios de podcast com duração entre 30s e 60s.
                            </p>
                        </div>
                        <div class="demo-clip-meta">
                            <span>Trecho: 12:14 — 12:56</span>
                            <span>Formato 9:16</span>
                        </div>
                    </div>
                </article>

                <!-- Exemplo 2 -->
                <article class="demo-clip-card">
                    <div class="demo-clip-visual">
                        <div class="vertical-mock-screen">
                            <div class="mock-top-badges">
                                <span class="badge badge-violet">9:16</span>
                                <span>00:55s</span>
                            </div>
                            <div class="mock-speaker-circle">
                                <?= clipia_icon('zap', 22) ?>
                            </div>
                            <div>
                                <div class="mock-waveform" aria-hidden="true">
                                    <span style="height:14px"></span><span style="height:22px"></span><span style="height:16px"></span><span style="height:10px"></span><span style="height:24px"></span><span style="height:18px"></span><span style="height:12px"></span><span style="height:20px"></span>
                                </div>
                                <div class="phone-caption-mock" style="margin-top:8px; text-align:center;">
                                    Passo a passo prático resumido
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="demo-clip-info">
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <span class="badge badge-neutral">Exemplo Demonstrativo #2</span>
                                <span style="font-size:0.78rem; color:var(--accent-light); font-weight:600;">Aula / Masterclass</span>
                            </div>
                            <h3 class="card-title">Síntese de Conceito Educacional</h3>
                            <p class="card-text">
                                Transforma explicações técnicas longas em pílulas de conhecimento objetivas para YouTube Shorts e Reels.
                            </p>
                        </div>
                        <div class="demo-clip-meta">
                            <span>Trecho: 28:05 — 29:00</span>
                            <span>Formato 9:16</span>
                        </div>
                    </div>
                </article>

                <!-- Exemplo 3 -->
                <article class="demo-clip-card">
                    <div class="demo-clip-visual">
                        <div class="vertical-mock-screen">
                            <div class="mock-top-badges">
                                <span class="badge badge-violet">9:16</span>
                                <span>00:36s</span>
                            </div>
                            <div class="mock-speaker-circle">
                                <?= clipia_icon('sparkles', 22) ?>
                            </div>
                            <div>
                                <div class="mock-waveform" aria-hidden="true">
                                    <span style="height:10px"></span><span style="height:15px"></span><span style="height:22px"></span><span style="height:18px"></span><span style="height:12px"></span><span style="height:20px"></span><span style="height:14px"></span><span style="height:9px"></span>
                                </div>
                                <div class="phone-caption-mock" style="margin-top:8px; text-align:center;">
                                    Destaque de história ou estudo de caso
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="demo-clip-info">
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <span class="badge badge-neutral">Exemplo Demonstrativo #3</span>
                                <span style="font-size:0.78rem; color:var(--accent-light); font-weight:600;">Live / Painel</span>
                            </div>
                            <h3 class="card-title">Momento de Alta Energia ou Conclusão</h3>
                            <p class="card-text">
                                Captura sínteses finais e chamadas para ação que funcionam como convite para assistir ao vídeo completo.
                            </p>
                        </div>
                        <div class="demo-clip-meta">
                            <span>Trecho: 47:10 — 47:46</span>
                            <span>Formato 9:16</span>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <!-- 4. Benefícios da Plataforma -->
    <section id="beneficios" class="section" aria-labelledby="benefits-heading">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">Por que usar o ClipIA</span>
                <h2 id="benefits-heading" class="section-title">Benefícios para sua operação de conteúdo</h2>
                <p class="section-subtitle">
                    Projetado para reduzir o trabalho repetitivo de triagem manual de horas de gravação.
                </p>
            </div>

            <div class="grid-3">
                <article class="card">
                    <div class="feature-icon"><?= clipia_icon('clock', 20) ?></div>
                    <h3 class="card-title">Economia de Horas de Triagem</h3>
                    <p class="card-text">
                        Em vez de assistir novamente a gravações de duas horas procurando minutagens, centralize os parâmetros de corte em segundos.
                    </p>
                </article>

                <article class="card">
                    <div class="feature-icon"><?= clipia_icon('smartphone', 20) ?></div>
                    <h3 class="card-title">Padronização Vertical 9:16</h3>
                    <p class="card-text">
                        Mantenha todos os projetos alinhados ao formato vertical exigido pelo Instagram Reels, TikTok e YouTube Shorts.
                    </p>
                </article>

                <article class="card">
                    <div class="feature-icon"><?= clipia_icon('layers', 20) ?></div>
                    <h3 class="card-title">Organização por Projetos</h3>
                    <p class="card-text">
                        Acompanhe cada episódio ou vídeo em uma linha do tempo clara, com status de fila, data de criação e quantidade de cortes.
                    </p>
                </article>
            </div>
        </div>
    </section>

    <!-- 5. Seção de Recursos -->
    <section id="recursos" class="section section-surface" aria-labelledby="features-heading">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">Especificações da Plataforma</span>
                <h2 id="features-heading" class="section-title">Recursos incluídos e arquitetura preparada</h2>
                <p class="section-subtitle">
                    Interface rápida, responsiva e construída sob medida para evoluir com segurança.
                </p>
            </div>

            <div class="grid-3">
                <article class="card">
                    <div class="feature-icon"><?= clipia_icon('link', 20) ?></div>
                    <h3 class="card-title">Validador de Referência YouTube</h3>
                    <p class="card-text">
                        Identificação instantânea de URL com prévia reservada para título e miniatura antes de iniciar a configuração do projeto.
                    </p>
                </article>

                <article class="card">
                    <div class="feature-icon"><?= clipia_icon('sliders', 20) ?></div>
                    <h3 class="card-title">Controle Granular de Duração</h3>
                    <p class="card-text">
                        Configure exatamente quantos cortes deseja gerar por vídeo e estabeleça limites mínimos e máximos em segundos.
                    </p>
                </article>

                <article class="card">
                    <div class="feature-icon"><?= clipia_icon('cpu', 20) ?></div>
                    <h3 class="card-title">Arquitetura Desacoplada</h3>
                    <p class="card-text">
                        Painel web em PHP + MySQL leve e rápido, preparado para comunicar-se com workers externos em Python sem sobrecarregar a hospedagem.
                    </p>
                </article>

                <article class="card">
                    <div class="feature-icon"><?= clipia_icon('shield', 20) ?></div>
                    <h3 class="card-title">Conformidade e Fontes Autorizadas</h3>
                    <p class="card-text">
                        Fluxo transparente que orienta o usuário sobre direitos autorais e exigência de fontes de vídeo devidamente autorizadas.
                    </p>
                </article>

                <article class="card">
                    <div class="feature-icon"><?= clipia_icon('dashboard', 20) ?></div>
                    <h3 class="card-title">Dashboard de Produtividade</h3>
                    <p class="card-text">
                        Visão consolidada do total de projetos, vídeos processados, cortes gerados e histórico de atividades recentes.
                    </p>
                </article>

                <article class="card">
                    <div class="feature-icon"><?= clipia_icon('settings', 20) ?></div>
                    <h3 class="card-title">Preferências & Limites de Uso</h3>
                    <p class="card-text">
                        Configure padrões de formato, segurança da conta e acompanhe o espaço reservado para métricas de consumo do plano.
                    </p>
                </article>
            </div>
        </div>
    </section>

    <!-- 6. Chamada para Ação Final (Acesso Administrativo) -->
    <section class="section" aria-labelledby="cta-heading">
        <div class="container">
            <div class="cta-banner">
                <span class="badge badge-violet" style="margin-bottom:14px;">Uso Exclusivo do Proprietário</span>
                <h2 id="cta-heading" class="section-title">Pronto para organizar seus projetos de cortes?</h2>
                <p class="section-subtitle" style="max-width:560px; margin:0 auto 28px;">
                    Acesse o painel administrativo para gerenciar seus vídeos autorizados e configurar seus projetos de cortes.
                </p>
                <div style="display:flex; justify-content:center; gap:14px; flex-wrap:wrap;">
                    <a href="login.php" class="btn btn-primary btn-lg">Entrar no painel administrativo</a>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
