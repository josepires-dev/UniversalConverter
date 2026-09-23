<?php
declare(strict_types=1);

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
        if ($format !== 'md' && $format !== 'txt') {
            throw new ConversionException('A conversão de URLs apenas suporta MD ou TXT.', 400);
        }

        $taskDirectory = $this->createTaskDirectory();
        try {
            [$urlFile, $sourceName] = $this->storeUpload($file, $taskDirectory);
            $url = trim(file_get_contents($urlFile) ?: '');
            
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new ConversionException('O conteúdo submetido não é um URL válido.', 400);
            }

            // SSRF guard: block private/reserved IP ranges
            $this->blockPrivateAddress($url);

            // Fetch HTML content
            $context = stream_context_create([
                'http' => [
                    'header' => "User-Agent: UniversalConverter/1.0\r\n",
                    'timeout' => 15,
                    'follow_location' => 1,
                    'max_redirects' => 5,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);
            $html = @file_get_contents($url, false, $context);
            $statusCode = $this->responseStatusCode($http_response_header ?? []);
            if ($html === false || $statusCode < 200 || $statusCode >= 400) {
                $detail = $statusCode > 0 ? " (HTTP {$statusCode})" : '';
                throw new ConversionException('Não foi possível aceder ao website especificado' . $detail . '.', 422);
            }
            if (trim($html) === '') {
                throw new ConversionException('O website especificado não devolveu conteúdo HTML.', 422);
            }

            $html = $this->extractMainContent($html);
            $htmlFile = $taskDirectory . DIRECTORY_SEPARATOR . 'website.html';
            if (file_put_contents($htmlFile, $html) === false) {
                throw new ConversionException('Não foi possível preparar o conteúdo do website.', 500);
            }
            
            // Generate clean output filename
            $host = parse_url($url, PHP_URL_HOST) ?? 'website';
            $host = preg_replace('/[^a-zA-Z0-9.-]/', '_', strtolower($host));
            $outputName = $host . '_convertido.' . $format;
            $output = $taskDirectory . DIRECTORY_SEPARATOR . $outputName;

            // Converter HTML para MD usando Pandoc
            $binary = $this->resolveBinary();
            $command = [$binary, '-f', 'html', '-t', $format === 'md' ? 'gfm' : 'plain', $htmlFile, '-o', $output];
            
            $this->execute($command, $taskDirectory);
            
            if (!is_file($output) || filesize($output) === 0) {
                throw new ConversionException('O Pandoc não conseguiu gerar o ficheiro Markdown.', 500);
            }

            if ($format === 'md') {
                $mdText = file_get_contents($output);
                if ($mdText !== false) {
                    file_put_contents($output, $this->enhanceToRealMarkdown($mdText));
                }
            }

            return [
                'file' => $output,
                'name' => $outputName,
                'mime' => $format === 'md' ? 'text/markdown' : 'text/plain',
                'task_dir' => $taskDirectory
            ];
            
        } catch (Throwable $exception) {
            $this->removeTaskDirectory($taskDirectory);
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
        return escapeshellarg($binary);
    }

    /** @param array<string, mixed> $file @return array{string,string} */
    private function storeUpload(array $file, string $taskDirectory): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new ConversionException($this->uploadError($error), 400);
        }
        
        $size = (int) ($file['size'] ?? 0);
        if ($size > (int) $this->config['max_upload_bytes']) throw new ConversionException('O ficheiro excede o limite.', 413);
        
        $temporaryName = (string) ($file['tmp_name'] ?? '');
        if ($temporaryName === '') throw new ConversionException('O carregamento não é válido.', 400);
        if (php_sapi_name() !== 'cli' && !is_uploaded_file($temporaryName)) throw new ConversionException('O ficheiro não foi submetido via upload.', 400);
        
        $target = $taskDirectory . DIRECTORY_SEPARATOR . 'link.url';
        $moved = php_sapi_name() === 'cli' ? copy($temporaryName, $target) : move_uploaded_file($temporaryName, $target);
        if (!$moved) throw new ConversionException('Não foi possível preparar o URL.', 500);
        
        return [$target, 'website.url'];
    }

    /** @param list<string> $command */
    private function execute(array $command, string $workingDirectory): void
    {
        $cmdLine = $command[0];
        for ($i = 1; $i < count($command); $i++) {
            $cmdLine .= ' ' . escapeshellarg($command[$i]);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        
        $process = proc_open($cmdLine, $descriptors, $pipes, $workingDirectory, null, ['bypass_shell' => true]);
        
        if (!is_resource($process)) {
            throw new ConversionException('Não foi possível iniciar o Pandoc.', 500);
        }
        
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        $deadline = microtime(true) + (int) $this->config['command_timeout_seconds'];
        $reportedExitCode = null;
        
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $reportedExitCode = is_int($status['exitcode']) ? $status['exitcode'] : null;
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new ConversionException('A conversão do website excedeu o tempo máximo.', 504);
            }
            usleep(50_000);
        }
        
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        $exitCode = proc_close($process);
        if ($exitCode === -1 && $reportedExitCode !== null) {
            $exitCode = $reportedExitCode;
        }
        
        if ($exitCode !== 0) {
            throw new ConversionException('O Pandoc não conseguiu processar o HTML.', 422);
        }
    }

    private function createTaskDirectory(): string
    {
        $directory = (string) $this->config['storage_root'] . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16));
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar a pasta temporária.');
        }
        return $directory;
    }

    private function uploadError(int $error): string
    {
        return 'Erro no upload do URL.';
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

    public function removeTaskDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }

    private function enhanceToRealMarkdown(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $rawLines = array_map('trim', explode("\n", str_replace(["\r\n", "\r"], "\n", $text)));
        while (count($rawLines) > 0 && $rawLines[0] === '') {
            array_shift($rawLines);
        }

        $rawLines = array_filter($rawLines, function ($l) {
            if (preg_match('/^\*?🕐?\s*\d{1,2}\s+de\s+[a-z]+\.?\s+de\s+\d{4}/i', $l)) return false;
            if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?/i', $l)) return false;
            if (preg_match('/\[stay in caveman/i', $l)) return false;
            if (preg_match('/^Turno\s+\d+/i', $l)) return false;
            if (preg_match('/^(Usuário|Gemini|🔗 Link original|📅 Exportado em|Gerado por)/i', $l)) return false;
            return true;
        });

        $result = [];
        $inSection = false;

        foreach ($rawLines as $line) {
            $trimmed = $line;

            if ($trimmed === '') {
                $result[] = '';
                continue;
            }

            if (preg_match('/^[-*_]{3,}$/', $trimmed)) {
                $result[] = '---';
                continue;
            }

            $trimmed = str_replace('\\', '/', $trimmed);
            $trimmed = str_replace('`', '', $trimmed);

            if (preg_match('/^[\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $trimmed)) {
                $trimmed = preg_replace('/^#+\s*/', '', $trimmed) ?? $trimmed;
                $result[] = '## ' . $trimmed;
                $inSection = false;
                continue;
            }

            if (preg_match('/^(Colega \d+|Projeto \d+|Fase \d+|Etapa \d+|Modulo \d+|Relatório|Veredito|Conclusão)/i', $trimmed) && !str_contains($trimmed, ':')) {
                $trimmed = preg_replace('/^#+\s*/', '', $trimmed) ?? $trimmed;
                $result[] = '## ' . $trimmed;
                $inSection = false;
                continue;
            }

            if (preg_match('/^[A-Za-zÀ-ÿ0-9\s\/()_-]{2,30}:$/i', $trimmed) || preg_match('/^Resumo/i', $trimmed)) {
                $trimmed = preg_replace('/^#+\s*/', '', $trimmed) ?? $trimmed;
                if (preg_match('/^Resumo/i', $trimmed)) {
                    $clean = str_replace('**', '', $trimmed);
                    $trimmed = "**{$clean}**";
                } else {
                    $result[] = '### ' . $trimmed;
                    $inSection = true;
                    continue;
                }
            }

            if (str_contains($trimmed, '?') && !str_starts_with($trimmed, '-') && !str_starts_with($trimmed, '*') && !str_starts_with($trimmed, '#')) {
                $qIndex = strpos($trimmed, '?');
                $questionPart = trim(substr($trimmed, 0, $qIndex + 1));
                $answerPart = trim(substr($trimmed, $qIndex + 1));
                $trimmed = "- **{$questionPart}** {$answerPart}";
            } elseif (str_contains($trimmed, ': ') && !str_starts_with($trimmed, '#') && !str_starts_with($trimmed, '-') && !str_starts_with($trimmed, '*') && !str_starts_with($trimmed, '>')) {
                $cIndex = strpos($trimmed, ': ');
                $labelPart = trim(substr($trimmed, 0, $cIndex));
                $valuePart = trim(substr($trimmed, $cIndex + 2));
                if (strlen($labelPart) < 40 && !str_contains($labelPart, "\n")) {
                    if ($inSection) {
                        $trimmed = "- **{$labelPart}:** {$valuePart}";
                    } else {
                        $trimmed = "**{$labelPart}:** {$valuePart}";
                    }
                }
            } elseif (preg_match('/^[•▪]\s+/', $trimmed)) {
                $trimmed = '- ' . preg_replace('/^[•▪]\s+/', '', $trimmed);
            }

            $trimmed = (string) preg_replace('/\b((?:data|notebooks|src|models|docs|lib|api|tools|raw|interim)\/[a-zA-Z0-9_.\-\/]+)\b/i', '`$1`', $trimmed);
            $trimmed = (string) preg_replace('/(?<!`)\b([a-zA-Z0-9_-]+\.(?:csv|py|ipynb|docx|pdf|png|jpg|json|html|js|php|exe|txt|md))\b(?!`)/i', '`$1`', $trimmed);

            $result[] = $trimmed;
        }

        return implode("\n", $result);
    }

    /**
     * SSRF guard: resolve host and block RFC1918/loopback/reserved ranges.
     * Prevents fetching http://127.0.0.1/, http://192.168.x.x/, http://10.x.x.x/, etc.
     */
    private function blockPrivateAddress(string $url): void
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        $host = trim($host, '[]'); // strip IPv6 brackets
        if ($host === '') {
            throw new ConversionException('O URL não contém um host válido.', 400);
        }
        $ip = gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new ConversionException('Não foi possível resolver o host do URL.', 422);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new ConversionException('O URL aponta para um endereço privado ou reservado.', 400);
        }
    }
}
