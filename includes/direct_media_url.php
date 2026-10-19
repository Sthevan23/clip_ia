<?php
declare(strict_types=1);

/**
 * Validação de URL HTTPS para arquivo de vídeo autorizado (não YouTube).
 * Usado quando o proprietário hospeda o MP4/WebM em CDN, Drive público, etc.
 */

final class DirectMediaUrlValidator
{
    /** @var list<string> */
    private const BLOCKED_HOST_SUFFIXES = [
        'youtube.com',
        'youtu.be',
        'youtube-nocookie.com',
        'googlevideo.com',
        'ytimg.com',
    ];

    /**
     * @return array{valid: bool, normalized_url: string|null, error: string|null}
     */
    public static function validate(string $rawUrl): array
    {
        $url = trim($rawUrl);
        if ($url === '') {
            return ['valid' => false, 'normalized_url' => null, 'error' => 'Informe a URL HTTPS do arquivo de vídeo.'];
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['valid' => false, 'normalized_url' => null, 'error' => 'URL inválida.'];
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return ['valid' => false, 'normalized_url' => null, 'error' => 'URL malformada.'];
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return ['valid' => false, 'normalized_url' => null, 'error' => 'Use apenas HTTPS para a URL do arquivo.'];
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return ['valid' => false, 'normalized_url' => null, 'error' => 'Host inválido ou endereço IP não permitido.'];
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
                return [
                    'valid' => false,
                    'normalized_url' => null,
                    'error' => 'Links do YouTube não podem ser baixados automaticamente. Envie o arquivo ou use uma URL HTTPS do seu próprio armazenamento.',
                ];
            }
        }

        $allowlist = trim((string)clipia_env('CLIPIA_DIRECT_MEDIA_URL_HOST_ALLOWLIST', ''));
        if ($allowlist !== '') {
            $allowed = array_filter(array_map('trim', explode(',', $allowlist)));
            $hostOk = false;
            foreach ($allowed as $entry) {
                $entry = strtolower($entry);
                if ($host === $entry || str_ends_with($host, '.' . $entry)) {
                    $hostOk = true;
                    break;
                }
            }
            if (!$hostOk) {
                return [
                    'valid' => false,
                    'normalized_url' => null,
                    'error' => 'Este domínio não está na lista permitida (CLIPIA_DIRECT_MEDIA_URL_HOST_ALLOWLIST).',
                ];
            }
        }

        $path = (string)($parts['path'] ?? '');
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== '' && !in_array($ext, ['mp4', 'mov', 'webm'], true)) {
            return [
                'valid' => false,
                'normalized_url' => null,
                'error' => 'A URL deve apontar para um arquivo .mp4, .mov ou .webm quando a extensão estiver presente.',
            ];
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['valid' => false, 'normalized_url' => null, 'error' => 'URL com credenciais embutidas não é permitida.'];
        }

        return ['valid' => true, 'normalized_url' => $url, 'error' => null];
    }
}
