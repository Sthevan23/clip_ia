<?php
declare(strict_types=1);

/**
 * ClipIA - Componente de Alertas, Mensagens de Erro, Sucesso e Carregamento
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/icons.php';

/**
 * Renderiza um alerta individual estático ou dinâmico
 */
function render_alert(string $type, string $title, string $message, bool $dismissible = true): string
{
    $iconName = match ($type) {
        'success' => 'check-circle',
        'error'   => 'x-circle',
        'warning' => 'alert-triangle',
        'loading' => 'clock',
        default   => 'info',
    };

    $role = ($type === 'error' || $type === 'warning') ? 'alert' : 'status';
    $iconHtml = ($type === 'loading')
        ? '<span class="spinner" aria-hidden="true"></span>'
        : clipia_icon($iconName, 20);

    $closeBtn = $dismissible
        ? '<button type="button" class="alert-close" data-dismiss="alert" aria-label="Fechar mensagem">' . clipia_icon('close', 16) . '</button>'
        : '';

    return sprintf(
        '<div class="alert alert-%s" role="%s">
            <div class="alert-icon">%s</div>
            <div class="alert-content">
                <strong class="alert-title">%s</strong>
                <p class="alert-message">%s</p>
            </div>
            %s
        </div>',
        e($type),
        $role,
        $iconHtml,
        e($title),
        e($message),
        $closeBtn
    );
}

/**
 * Renderiza todas as mensagens flash pendentes na sessão + container JS para feedback assíncrono
 */
function render_flash_container(): void
{
    $flashes = get_flashes();
    echo '<div id="ui-feedback-container" class="feedback-stack" aria-live="polite">';
    foreach ($flashes as $flash) {
        echo render_alert(
            $flash['type'] ?? 'info',
            $flash['title'] ?? 'Aviso',
            $flash['message'] ?? ''
        );
    }
    echo '</div>';
}
