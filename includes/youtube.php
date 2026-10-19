<?php
declare(strict_types=1);

/**
 * ClipIA — Validador Estrito de URLs do YouTube e Integração Oficial oEmbed / IFrame
 *
 * Regras de Segurança e Conformidade:
 * 1. Aceita exclusivamente os domínios públicos oficiais do YouTube (`youtube.com`, `www.youtube.com`, `m.youtube.com`, `youtu.be`).
 * 2. Rejeita esquemas diferentes de http/https, credenciais embutidas na URL (`user:pass@`), portas não padrão,
 *    endereços IP (IPv4/IPv6), localhost, domínios arbitrários ou redirecionamentos abertos (`/redirect`, `google.com/url`).
 * 3. Valida rigorosamente o identificador do vídeo (`^[A-Za-z0-9_-]{11}$`).
 * 4. Na consulta oEmbed oficial, conecta-se apenas ao endpoint fixo `https://www.youtube.com/oembed`
 *    com `CURLOPT_FOLLOWLOCATION = false` (sem seguir redirecionamentos inseguros).
 * 5. Não baixa fluxos de mídia do YouTube nem contorna DRM ou restrições de acesso.
 */

class YouTubeUrlValidator
{
    private const ALLOWED_HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'youtu.be',
    ];

    private const VIDEO_ID_REGEX = '/^[A-Za-z0-9_-]{11}$/';

    /**
     * Valida uma URL do YouTube no servidor e retorna um array estruturado:
     * [
     *   'valid'          => bool,
     *   'error'          => ?string,
     *   'video_id'       => ?string,
     *   'original_url'   => string,
     *   'normalized_url' => ?string,
     *   'embed_url'      => ?string,
     *   'thumbnail_url'  => ?string,
     * ]
     */
    public static function validate(string $rawUrl): array
    {
        $trimmed = trim($rawUrl);

        $invalid = static fn(string $error): array => [
            'valid'          => false,
            'error'          => $error,
            'video_id'       => null,
            'original_url'   => $trimmed,
            'normalized_url' => null,
            'embed_url'      => null,
            'thumbnail_url'  => null,
        ];

        if ($trimmed === '') {
            return $invalid('Informe o link do vídeo do YouTube.');
        }

        if (strlen($trimmed) > 500) {
            return $invalid('A URL informada excede o limite máximo de 500 caracteres.');
        }

        // Rejeita caracteres de controle ou espaços internos
        if (preg_match('/[\s\x00-\x1F\x7F]/', $trimmed)) {
            return $invalid('A URL contém espaços ou caracteres de controle inválidos.');
        }

        $parts = parse_url($trimmed);
        if ($parts === false || !is_array($parts)) {
            return $invalid('Formato de URL inválido.');
        }

        // 1. Esquema deve ser http ou https
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $invalid('Apenas links iniciados com https:// ou http:// são permitidos.');
        }

        // 2. Proíbe credenciais na URL (ex: https://youtube.com@atacante.com)
        if (isset($parts['user']) || isset($parts['pass'])) {
            return $invalid('URLs com credenciais de autenticação embutidas não são permitidas.');
        }

        // 3. Proíbe portas customizadas (anti-SSRF)
        if (isset($parts['port']) && !in_array((int)$parts['port'], [80, 443], true)) {
            return $invalid('Portas personalizadas não são permitidas na URL.');
        }

        // 4. Validação estrita de Host / Domínio
        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        if ($host === '') {
            return $invalid('Domínio não identificado na URL.');
        }

        // Bloqueia qualquer endereço IP literal (IPv4 ou IPv6) ou localhost/interno
        if (
            filter_var($host, FILTER_VALIDATE_IP) !== false
            || str_starts_with($host, '[')
            || $host === 'localhost'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
        ) {
            return $invalid('Endereços internos, localhost ou endereços IP não são permitidos.');
        }

        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            return $invalid('Domínio não autorizado. Utilize apenas links diretos de youtube.com ou youtu.be.');
        }

        // 5. Extração do ID de acordo com o formato público suportado
        $path = (string)($parts['path'] ?? '');
        $query = (string)($parts['query'] ?? '');

        // Bloqueia endpoints de redirecionamento do próprio YouTube (ex: /redirect?q=...)
        if (preg_match('#^/(redirect|attribution_link)\b#i', $path)) {
            return $invalid('Links de redirecionamento intermediário não são aceitos. Use o link direto do vídeo.');
        }

        $candidateId = null;

        if ($host === 'youtu.be') {
            // Formato: https://youtu.be/VIDEO_ID
            $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn($s) => $s !== ''));
            if (count($segments) !== 1) {
                return $invalid('Formato curto youtu.be inválido. Use https://youtu.be/ID_DO_VIDEO.');
            }
            $candidateId = $segments[0];
        } else {
            // Domínios youtube.com / www.youtube.com / m.youtube.com
            if ($path === '/watch') {
                parse_str($query, $queryParams);
                // Bloqueia parâmetros de redirecionamento ou URLs aninhadas
                if (isset($queryParams['v']) && is_string($queryParams['v'])) {
                    $candidateId = $queryParams['v'];
                } else {
                    return $invalid('O parâmetro "v" com o identificador do vídeo não foi encontrado em youtube.com/watch.');
                }
            } elseif (preg_match('~^/(?:shorts|live|embed)/([^/?#]+)$~', $path, $matches)) {
                $candidateId = $matches[1];
            } else {
                return $invalid('Caminho da URL não suportado. Use youtube.com/watch?v=ID, youtu.be/ID, youtube.com/shorts/ID ou youtube.com/live/ID.');
            }
        }

        if ($candidateId === null || !preg_match(self::VIDEO_ID_REGEX, $candidateId)) {
            return $invalid('Identificador do vídeo inválido. O ID do YouTube deve possuir exatamente 11 caracteres alfanuméricos.');
        }

        $normalizedUrl = 'https://www.youtube.com/watch?v=' . $candidateId;
        $embedUrl      = 'https://www.youtube-nocookie.com/embed/' . $candidateId . '?rel=0';
        $thumbnailUrl  = 'https://i.ytimg.com/vi/' . $candidateId . '/hqdefault.jpg';

        return [
            'valid'          => true,
            'error'          => null,
            'video_id'       => $candidateId,
            'original_url'   => $trimmed,
            'normalized_url' => $normalizedUrl,
            'embed_url'      => $embedUrl,
            'thumbnail_url'  => $thumbnailUrl,
        ];
    }

    /**
     * Consulta metadados públicos via endpoint oficial YouTube oEmbed (compatível com as políticas do YouTube).
     * Não utiliza a URL bruta do usuário para fazer requisição: constrói uma URL fixa para `www.youtube.com/oembed`
     * usando apenas o `video_id` de 11 caracteres já validado e desativa `FOLLOWLOCATION`.
     */
    public static function fetchPublicMetadata(string $validatedVideoId): array
    {
        if (!preg_match(self::VIDEO_ID_REGEX, $validatedVideoId)) {
            throw new InvalidArgumentException('ID de vídeo inválido para consulta de metadados.');
        }

        $defaultTitle  = 'Vídeo do YouTube (' . $validatedVideoId . ')';
        $defaultThumb  = 'https://i.ytimg.com/vi/' . $validatedVideoId . '/hqdefault.jpg';
        $embedUrl      = 'https://www.youtube-nocookie.com/embed/' . $validatedVideoId . '?rel=0';
        $canonicalUrl  = 'https://www.youtube.com/watch?v=' . $validatedVideoId;
        $oembedEndpoint = 'https://www.youtube.com/oembed?url=' . rawurlencode($canonicalUrl) . '&format=json';

        $result = [
            'video_id'      => $validatedVideoId,
            'title'         => $defaultTitle,
            'author_name'   => '',
            'thumbnail_url' => $defaultThumb,
            'embed_url'     => $embedUrl,
            'embed_allowed' => true,
            'oembed_status' => 'fallback',
        ];

        // Evita chamadas externas durante testes CLI offline se desabilitado
        if (clipia_env('CLIPIA_DISABLE_EXTERNAL_HTTP', '0') === '1') {
            return $result;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($oembedEndpoint);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false, // Segurança: nunca segue redirecionamentos
                    CURLOPT_MAXREDIRS      => 0,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT        => 4,
                    CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                    CURLOPT_USERAGENT      => 'ClipIA-MetadataResolver/1.0',
                ]);
                $body = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && is_string($body) && $body !== '') {
                    $json = json_decode($body, true);
                    if (is_array($json)) {
                        $result['title']         = trim((string)($json['title'] ?? $defaultTitle)) ?: $defaultTitle;
                        $result['author_name']   = trim((string)($json['author_name'] ?? ''));
                        $result['thumbnail_url'] = trim((string)($json['thumbnail_url'] ?? $defaultThumb)) ?: $defaultThumb;
                        $result['embed_allowed'] = !empty($json['html']);
                        $result['oembed_status'] = 'ok';
                    }
                } elseif ($httpCode === 401 || $httpCode === 403) {
                    // O vídeo existe, porém o proprietário desativou incorporação / oEmbed público
                    $result['embed_allowed'] = false;
                    $result['oembed_status'] = 'restricted';
                } elseif ($httpCode === 404) {
                    $result['embed_allowed'] = false;
                    $result['oembed_status'] = 'not_found';
                }
            }
        }

        return $result;
    }
}
