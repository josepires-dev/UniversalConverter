<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'ConversionSupport.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'MarkdownFormatter.php';

final class UrlConverterService
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed> $options
     * @return array{file:string,name:string,mime:string,task_dir:string}
     */
    public function convert(array $file, array $options): array
    {
        $format = strtolower(trim((string) ($options['format'] ?? '')));
        if (!in_array($format, ['md', 'txt'], true)) {
            throw new ConversionException('A conversão de URLs apenas suporta MD ou TXT.', 400);
        }

        $taskDirectory = ConversionSupport::createTaskDirectory($this->config);
        try {
            [$urlFile] = ConversionSupport::storeUpload(
                $file,
                $taskDirectory,
                (int) $this->config['max_upload_bytes'],
                ['url'],
                'Este serviço requer um ficheiro .url de entrada.',
                'Erro no upload do URL.',
                true,
                'link'
            );
            $url = trim(file_get_contents($urlFile) ?: '');
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new ConversionException('O conteúdo submetido não é um URL válido.', 400);
            }
            $this->blockPrivateAddress($url);
            $html = $this->fetchUrl($url);
            if (trim($html) === '') {
                throw new ConversionException('O website especificado não devolveu conteúdo HTML.', 422);
            }

            $html = $this->extractMainContent($html);
            $htmlFile = $taskDirectory . DIRECTORY_SEPARATOR . 'website.html';
            if (file_put_contents($htmlFile, $html) === false) {
                throw new ConversionException('Não foi possível preparar o conteúdo do website.', 500);
            }
            $host = parse_url($url, PHP_URL_HOST) ?? 'website';
            $host = preg_replace('/[^a-zA-Z0-9.-]/', '_', strtolower($host));
            $outputName = $host . '_convertido.' . $format;
            $output = $taskDirectory . DIRECTORY_SEPARATOR . $outputName;
            ConversionSupport::runCommand(
                [$this->resolveBinary(), '-f', 'html', '-t', $format === 'md' ? 'gfm' : 'plain', $htmlFile, '-o', $output],
                $taskDirectory,
                (int) $this->config['command_timeout_seconds'],
                'Pandoc',
                'Não foi possível iniciar o Pandoc.',
                'A conversão do website excedeu o tempo máximo.',
                'O Pandoc não conseguiu processar o HTML.'
            );
            if (!is_file($output) || filesize($output) === 0) {
                throw new ConversionException('O Pandoc não conseguiu gerar o ficheiro Markdown.', 500);
            }
            if ($format === 'md') {
                $markdown = file_get_contents($output);
                if ($markdown !== false && file_put_contents($output, MarkdownFormatter::enhance($markdown, false)) === false) {
                    throw new ConversionException('Não foi possível gerar o ficheiro Markdown.', 500);
                }
            }
            return [
                'file' => $output,
                'name' => $outputName,
                'mime' => $format === 'md' ? 'text/markdown' : 'text/plain',
                'task_dir' => $taskDirectory,
            ];
        } catch (Throwable $exception) {
            ConversionSupport::removeDirectory($taskDirectory);
            if ($exception instanceof ConversionException) {
                throw $exception;
            }
            throw new ConversionException('Não foi possível converter o Website.', 500);
        }
    }

    private function resolveBinary(): string
    {
        $binary = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'pandoc' . DIRECTORY_SEPARATOR . 'pandoc.exe';
        if (!is_file($binary)) {
            throw new ConversionException('O Pandoc não foi encontrado na pasta tools/pandoc.', 500);
        }
        return $binary;
    }

    private function extractMainContent(string $html): string
    {
        if (!class_exists(DOMDocument::class)) {
            return $html;
        }
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $html;
        }

        $xpath = new DOMXPath($document);
        $content = $this->firstMatchingNode($xpath, [
            "//*[@id='mw-content-text']",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' mw-parser-output ')]",
            "//*[@id='bodyContent']",
            '//main',
            '//article',
        ]);
        $container = $content ?? $document->getElementsByTagName('body')->item(0);
        if (!$container instanceof DOMElement) {
            return $html;
        }

        $removable = $xpath->query('.//script | .//style | .//noscript | .//template | .//nav | .//header | .//footer | .//aside | .//form', $container);
        if ($removable !== false) {
            foreach (iterator_to_array($removable) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        $hidden = $xpath->query(".//*[@id='p-lang' or @id='p-lang-btn' or contains(concat(' ', normalize-space(@class), ' '), ' noprint ') or contains(concat(' ', normalize-space(@class), ' '), ' navigation-box ') or contains(concat(' ', normalize-space(@class), ' '), ' mw-editsection ') or contains(concat(' ', normalize-space(@class), ' '), ' vector-dropdown ') or contains(concat(' ', normalize-space(@class), ' '), ' vector-menu ') or contains(concat(' ', normalize-space(@class), ' '), ' mw-portlet ') or contains(concat(' ', normalize-space(@class), ' '), ' mw-portlet-lang ') or contains(concat(' ', normalize-space(@class), ' '), ' mw-indicators ') or contains(concat(' ', normalize-space(@class), ' '), ' navbox ') or contains(concat(' ', normalize-space(@class), ' '), ' metadata ') or contains(concat(' ', normalize-space(@class), ' '), ' interlanguage-link-target ')]", $container);
        if ($hidden !== false) {
            foreach (iterator_to_array($hidden) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $cleaned = '';
        foreach (iterator_to_array($container->childNodes) as $child) {
            $cleaned .= $document->saveHTML($child);
        }
        return trim($cleaned) !== '' ? $cleaned : $html;
    }

    /** @param list<string> $queries */
    private function firstMatchingNode(DOMXPath $xpath, array $queries): ?DOMNode
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes !== false && $nodes->length > 0) {
                return $nodes->item(0);
            }
        }
        return null;
    }

    /** @param list<string> $headers */
    private function responseStatusCode(array $headers): int
    {
        foreach (array_reverse($headers) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $header, $match) === 1) {
                return (int) $match[1];
            }
        }
        return 0;
    }

    /**
     * Resolves every hop and rejects private, reserved, loopback, and link-local destinations.
     */
    private function blockPrivateAddress(string $url): void
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ConversionException('O URL deve utilizar HTTP ou HTTPS.', 400);
        }
        $host = trim((string) (parse_url($url, PHP_URL_HOST) ?? ''), '[]');
        if ($host === '') {
            throw new ConversionException('O URL não contém um host válido.', 400);
        }
        $ips = [];
        foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        if ($ips === []) {
            $resolved = gethostbyname($host);
            if ($resolved !== $host) {
                $ips[] = $resolved;
            }
        }
        if ($ips === [] || array_filter($ips, static fn (string $ip): bool => !filter_var($ip, FILTER_VALIDATE_IP))) {
            throw new ConversionException('Não foi possível resolver o host do URL.', 422);
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new ConversionException('O URL aponta para um endereço privado ou reservado.', 400);
            }
        }
    }

    private function fetchUrl(string $url): string
    {
        $current = $url;
        for ($redirect = 0; $redirect <= 5; $redirect++) {
            $this->blockPrivateAddress($current);
            $context = stream_context_create([
                'http' => [
                    'header' => "User-Agent: UniversalConverter/1.0\r\n",
                    'timeout' => 15,
                    'follow_location' => 0,
                    'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $stream = @fopen($current, 'rb', false, $context);
            $headers = $http_response_header ?? [];
            $statusCode = $this->responseStatusCode($headers);
            if ($stream === false) {
                throw new ConversionException('Não foi possível aceder ao website especificado.', 422);
            }
            if ($statusCode >= 300 && $statusCode < 400) {
                fclose($stream);
                $location = $this->headerValue($headers, 'Location');
                if ($location === null || $redirect === 5) {
                    throw new ConversionException('O website excedeu o limite de redirecionamentos.', 422);
                }
                $current = $this->resolveRedirect($current, $location);
                continue;
            }
            if ($statusCode < 200 || $statusCode >= 400) {
                fclose($stream);
                $detail = $statusCode > 0 ? " (HTTP {$statusCode})" : '';
                throw new ConversionException('Não foi possível aceder ao website especificado' . $detail . '.', 422);
            }
            $html = stream_get_contents($stream, 5 * 1024 * 1024 + 1);
            fclose($stream);
            if ($html === false || strlen($html) > 5 * 1024 * 1024) {
                throw new ConversionException('O website excede o limite de 5 MB.', 413);
            }
            return $html;
        }
        throw new ConversionException('Não foi possível seguir o URL especificado.', 422);
    }

    /** @param list<string> $headers */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), strtolower($name) . ':')) {
                return trim(substr($header, strlen($name) + 1));
            }
        }
        return null;
    }

    private function resolveRedirect(string $base, string $location): string
    {
        if (filter_var($location, FILTER_VALIDATE_URL)) {
            return $location;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || !str_starts_with($location, '/')) {
            throw new ConversionException('O redirecionamento devolvido pelo website é inválido.', 422);
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $location;
    }

    public function removeTaskDirectory(string $directory): void
    {
        ConversionSupport::removeDirectory($directory);
    }
}
