<?php
declare(strict_types=1);

final class PdfConverterService
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
            throw new ConversionException('Este serviço (Poppler) apenas converte PDF para MD ou TXT.', 400);
        }

        $taskDirectory = $this->createTaskDirectory();
        try {
            [$input, $sourceName] = $this->storeUpload($file, $taskDirectory);
            
            $outputName = $this->outputName($sourceName, $format);
            $output = $taskDirectory . DIRECTORY_SEPARATOR . $outputName;

            // Converter PDF para texto com pdftotext
            $binary = $this->resolveBinary();
            
            // pdftotext [options] [PDF-file [text-file|-]]
            $command = [$binary, '-layout', '-enc', 'UTF-8', $input, $output];
            
            $this->execute($command, $taskDirectory);
            
            if (!is_file($output) || filesize($output) === 0) {
                // If it's 0 bytes, it might just be an empty PDF. Let's provide a fallback warning or allow it.
                if (!is_file($output)) {
                    throw new ConversionException('Não foi possível gerar o ficheiro.', 500);
                }
            }
            
            // Post-process to make it look like Markdown
            $text = file_get_contents($output);
            if ($text !== false && $format === 'md') {
                $text = str_replace("\f", "", $text);
                $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
                $text = $this->enhanceToRealMarkdown($text);
                file_put_contents($output, $text);
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
            throw new ConversionException('Não foi possível converter o PDF.', 500);
        }
    }

    private function resolveBinary(): string
    {
        $binary = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'poppler' . DIRECTORY_SEPARATOR . 'pdftotext.exe';
        if (!is_file($binary)) {
            throw new ConversionException('O pdftotext não foi encontrado em tools/poppler.', 500);
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
        if ($size < 1) throw new ConversionException('Selecione um ficheiro não vazio.', 400);
        if ($size > (int) $this->config['max_upload_bytes']) throw new ConversionException('O ficheiro excede o limite de 100 MB.', 413);
        
        $temporaryName = (string) ($file['tmp_name'] ?? '');
        if ($temporaryName === '') throw new ConversionException('O carregamento do ficheiro não é válido.', 400);
        if (php_sapi_name() !== 'cli' && !is_uploaded_file($temporaryName)) throw new ConversionException('O ficheiro não foi submetido via upload.', 400);
        
        $sourceName = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'ficheiro')));
        $sourceName = preg_replace('/[\x00-\x1F\x7F]+/u', '_', $sourceName) ?? '';
        $sourceName = trim($sourceName) !== '' ? $sourceName : 'ficheiro';
        
        $extension = strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));
        if ($extension !== 'pdf') {
            throw new ConversionException('Este serviço requer um ficheiro .pdf de entrada.', 400);
        }
        
        $target = $taskDirectory . DIRECTORY_SEPARATOR . 'entrada.' . $extension;
        $moved = php_sapi_name() === 'cli' ? copy($temporaryName, $target) : move_uploaded_file($temporaryName, $target);
        if (!$moved) throw new ConversionException('Não foi possível preparar o ficheiro para conversão.', 500);
        
        return [$target, $sourceName];
    }

    /** @param list<string> $command */
    private function execute(array $command, string $workingDirectory): void
    {
        // Join command array securely. Note: resolveBinary already escapes the exe path.
        // We need to escape other arguments.
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
            throw new ConversionException('Não foi possível iniciar o pdftotext.', 500);
        }
        
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + (int) $this->config['command_timeout_seconds'];
        $reportedExitCode = null;
        
        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            
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
                throw new ConversionException('A conversão excedeu o tempo máximo permitido.', 504);
            }
            
            usleep(50_000);
        }
        
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        $exitCode = proc_close($process);
        if ($exitCode === -1 && $reportedExitCode !== null) {
            $exitCode = $reportedExitCode;
        }
        
        if ($exitCode !== 0) {
            throw new ConversionException('O pdftotext não conseguiu extrair o texto.', 422);
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

    private function outputName(string $sourceName, string $format): string
    {
        $base = pathinfo($sourceName, PATHINFO_FILENAME);
        $base = preg_replace('/[^\pL\pN._ -]+/u', '_', $base) ?? 'ficheiro';
        $base = trim($base, '. ');
        return ($base !== '' ? $base : 'ficheiro') . '_convertido.' . $format;
    }

    private function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O ficheiro excede o limite de upload permitido.',
            UPLOAD_ERR_PARTIAL => 'O carregamento do ficheiro ficou incompleto.',
            UPLOAD_ERR_NO_FILE => 'Nenhum ficheiro foi enviado.',
            default => 'Ocorreu um erro durante o carregamento do ficheiro.',
        };
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
            if (preg_match('/como\s+descreverias|como\s+descreveria|super\s+detalhada|forma\s+super|como\s+é\s+que\s+é/i', $l)) return false;
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
}
