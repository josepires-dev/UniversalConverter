<?php
declare(strict_types=1);

final class NotebookConverterService
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        $this->ensureDirectory((string) $this->config['storage_root']);
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed> $options
     * @return array{file:string,name:string,mime:string,task_dir:string}
     */
    public function convert(array $file, array $options): array
    {
        $format = $this->validateFormat($options['format'] ?? 'ipynb');
        if ($format !== 'ipynb') {
            throw new ConversionException('Este serviço (nbformat native) apenas converte para ipynb.', 400);
        }

        $taskDirectory = $this->createTaskDirectory();

        try {
            [$input, $sourceName] = $this->storeUpload($file, $taskDirectory);
            $outputName = $this->outputName($sourceName, $format);
            $output = $taskDirectory . DIRECTORY_SEPARATOR . $outputName;

            // Converter .py para .ipynb sem dependências (nbformat)
            $sourceCode = file_get_contents($input);
            if ($sourceCode === false) {
                throw new ConversionException('Não foi possível ler o ficheiro Python.', 500);
            }

            // Normalize newlines to \n
            $sourceCode = str_replace("\r\n", "\n", $sourceCode);
            $sourceCode = str_replace("\r", "\n", $sourceCode);

            $cells = [];
            
            // Heuristic to split cells:
            // Split by `# %%`, `# In[`, or `# ---`
            $rawBlocks = preg_split('/^(?:# %%|# In\[|# ---).*\n/m', $sourceCode);
            
            if (count($rawBlocks) <= 1) {
                // Se não houver marcadores # %%, divide pelos blocos de código (linhas em branco duplas)
                $blocks = preg_split('/\n\s*\n/', trim($sourceCode));
                foreach ($blocks as $block) {
                    $block = trim($block, "\r\n");
                    if ($block === '') continue;
                    
                    if (preg_match('/^\s*(?:"""|\'\'\')(.*?)(?:"""|\'\'\')\s*$/s', $block, $matches)) {
                        $cells[] = [
                            'cell_type' => 'markdown',
                            'metadata' => new stdClass(),
                            'source' => $this->splitLines($matches[1])
                        ];
                    } else {
                        $cells[] = [
                            'cell_type' => 'code',
                            'execution_count' => null,
                            'metadata' => new stdClass(),
                            'outputs' => [],
                            'source' => $this->splitLines($block)
                        ];
                    }
                }
            } else {
                foreach ($rawBlocks as $block) {
                    $block = trim($block, "\r\n");
                    if ($block === '') continue;
                    
                    // Check if block is just a markdown string
                    if (preg_match('/^\s*(?:"""|\'\'\')(.*?)(?:"""|\'\'\')\s*$/s', $block, $matches)) {
                        $cells[] = [
                            'cell_type' => 'markdown',
                            'metadata' => new stdClass(),
                            'source' => $this->splitLines($matches[1])
                        ];
                    } else {
                        $cells[] = [
                            'cell_type' => 'code',
                            'execution_count' => null,
                            'metadata' => new stdClass(),
                            'outputs' => [],
                            'source' => $this->splitLines($block)
                        ];
                    }
                }
            }

            if (empty($cells)) {
                $cells[] = [
                    'cell_type' => 'code',
                    'execution_count' => null,
                    'metadata' => new stdClass(),
                    'outputs' => [],
                    'source' => []
                ];
            }

            $notebook = [
                'cells' => $cells,
                'metadata' => new stdClass(),
                'nbformat' => 4,
                'nbformat_minor' => 4
            ];

            $json = json_encode($notebook, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (file_put_contents($output, $json) === false) {
                throw new ConversionException('Não foi possível gerar o ficheiro ipynb.', 500);
            }

            return [
                'file' => $output,
                'name' => $outputName,
                'mime' => 'application/x-ipynb+json',
                'task_dir' => $taskDirectory,
            ];
        } catch (Throwable $exception) {
            $this->removeDirectory($taskDirectory);
            if ($exception instanceof ConversionException) {
                throw $exception;
            }
            throw new ConversionException('Não foi possível concluir a conversão para Jupyter Notebook.', 500);
        }
    }

    public function removeTaskDirectory(string $taskDirectory): void
    {
        $this->removeDirectory($taskDirectory);
    }

    private function validateFormat(mixed $value): string
    {
        $format = strtolower(trim(is_string($value) ? $value : ''));
        if (!in_array($format, $this->config['allowed_output_formats'], true)) {
            throw new ConversionException('O formato de saída selecionado não é suportado nesta instalação.', 400);
        }
        return $format;
    }

    private function createTaskDirectory(): string
    {
        $directory = (string) $this->config['storage_root'] . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16));
        $this->ensureDirectory($directory);
        return $directory;
    }

    /** @param array<string, mixed> $file @return array{string,string} */
    private function storeUpload(array $file, string $taskDirectory): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new ConversionException('Ocorreu um erro no upload.', 400);
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1) {
            throw new ConversionException('Selecione um ficheiro não vazio.', 400);
        }
        if ($size > (int) $this->config['max_upload_bytes']) {
            throw new ConversionException('O ficheiro excede o limite de 100 MB.', 413);
        }
        $temporaryName = (string) ($file['tmp_name'] ?? '');
        if ($temporaryName === '') {
            throw new ConversionException('O carregamento do ficheiro não é válido.', 400);
        }
        if (php_sapi_name() !== 'cli' && !is_uploaded_file($temporaryName)) {
            throw new ConversionException('O ficheiro não foi submetido via upload.', 400);
        }
        $sourceName = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'ficheiro')));
        $sourceName = preg_replace('/[\x00-\x1F\x7F]+/u', '_', $sourceName) ?? '';
        $sourceName = trim($sourceName) !== '' ? $sourceName : 'ficheiro';
        
        $extension = strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));
        if ($extension !== 'py') {
            throw new ConversionException('Este serviço (nbformat native) requer um ficheiro .py de entrada.', 400);
        }
        
        $target = $taskDirectory . DIRECTORY_SEPARATOR . 'entrada.' . $extension;
        $moved = php_sapi_name() === 'cli' ? copy($temporaryName, $target) : move_uploaded_file($temporaryName, $target);
        if (!$moved) {
            throw new ConversionException('Não foi possível preparar o ficheiro para conversão.', 500);
        }
        return [$target, $sourceName];
    }

    private function outputName(string $sourceName, string $format): string
    {
        $base = pathinfo($sourceName, PATHINFO_FILENAME);
        $base = preg_replace('/[^\pL\pN._ -]+/u', '_', $base) ?? 'ficheiro';
        $base = trim($base, '. ');
        return ($base !== '' ? $base : 'ficheiro') . '_convertido.' . $format;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar a pasta temporária.');
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }

    private function splitLines(string $content): array
    {
        $lines = explode("\n", $content);
        $result = [];
        foreach ($lines as $i => $line) {
            $result[] = $line . ($i < count($lines) - 1 ? "\n" : "");
        }
        return $result;
    }
}
