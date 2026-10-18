<?php
declare(strict_types=1);

/**
 * ClipIA - Rodapé Reutilizável e Scripts Globais
 */

$layoutType = $layoutType ?? 'public';
?>
    <?php if ($layoutType === 'public'): ?>
    <footer class="site-footer" role="contentinfo">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-brand-col">
                    <a href="index.php" class="brand">
                        <span class="brand-mark" aria-hidden="true">
                            <?= clipia_icon('scissors', 18) ?>
                        </span>
                        <span class="brand-text">Clip<span class="brand-accent">IA</span></span>
                    </a>
                    <p class="footer-desc">
                        Plataforma SaaS para identificação inteligente de momentos-chave em vídeos longos e preparação de cortes verticais 9:16 para redes sociais.
                    </p>
                    <p class="footer-arch-note">
                        Arquitetura leve em PHP + MySQL compatível com Hostinger Business, preparada para integração com motor externo em Python.
                    </p>
                </div>

                <div class="footer-col">
                    <h2 class="footer-heading">Plataforma</h2>
                    <ul class="footer-links">
                        <li><a href="index.php#como-funciona">Como funciona</a></li>
                        <li><a href="index.php#demonstracao">Demonstração visual</a></li>
                        <li><a href="index.php#recursos">Recursos</a></li>
                        <li><a href="dashboard.php">Acessar Dashboard</a></li>
                        <li><a href="new-project.php">Novo Projeto</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h2 class="footer-heading">Administração</h2>
                    <ul class="footer-links">
                        <li><a href="login.php">Acesso administrativo</a></li>
                        <li><a href="projects.php">Meus projetos</a></li>
                        <li><a href="settings.php">Configurações</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h2 class="footer-heading">Institucional & Legal</h2>
                    <ul class="footer-links">
                        <li><a href="pages/terms.php">Termos de Uso</a></li>
                        <li><a href="pages/privacy.php">Política de Privacidade</a></li>
                        <li><a href="pages/terms.php#direitos-autorais">Diretrizes de Direitos Autorais</a></li>
                        <li><a href="pages/forgot-password.php">Recuperação de Acesso</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Todos os direitos reservados. Etapa 1 — Interface SaaS.</p>
                <div class="footer-bottom-links">
                    <a href="pages/terms.php">Termos</a>
                    <span aria-hidden="true">•</span>
                    <a href="pages/privacy.php">Privacidade</a>
                    <span aria-hidden="true">•</span>
                    <span>PHP <?= e(PHP_VERSION) ?></span>
                </div>
            </div>
        </div>
    </footer>
    <?php endif; ?>

    <script src="<?= e(ASSETS_URL) ?>/js/app.js?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>
