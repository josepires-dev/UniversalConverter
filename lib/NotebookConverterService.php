<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'ConversionSupport.php';

final class NotebookConverterService
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        ConversionSupport::ensureDirectory((string) $this->config['storage_root']);
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed> $options
     * @return array{file:string,name:string,mime:string,task_dir:string}
     */
    public function convert(array $file, array $options): array
    {
        $format = ConversionSupport::validateFormat(
            $options['format'] ?? 'ipynb',
            $this->config['allowed_output_formats'],
            'O formato de saída selecionado não é suportado nesta instalação.'
        );
        if ($format !== 'ipynb') {
            throw new ConversionException('Este serviço (nbformat native) apenas converte para ipynb.', 400);
        }

        $taskDirectory = ConversionSupport::createTaskDirectory($this->config);
        try {
            [$input, $sourceName] = ConversionSupport::storeUpload(
                $file,
                $taskDirectory,
                (int) $this->config['max_upload_bytes'],
                ['py'],
                'Este serviço (nbformat native) requer um ficheiro .py de entrada.'
            );
            $outputName = ConversionSupport::outputName($sourceName, $format);
            $output = $taskDirectory . DIRECTORY_SEPARATOR . $outputName;
            $sourceCode = file_get_contents($input);
            if ($sourceCode === false) {
                throw new ConversionException('Não foi possível ler o ficheiro Python.', 500);
            }
            $sourceCode = str_replace(["\r\n", "\r"], "\n", $sourceCode);

            $cells = [];
            $blocks = preg_split('/^(?:# %%|# In\[|# ---).*\n/m', $sourceCode) ?: [];
            if (count($blocks) <= 1) {
                $blocks = preg_split('/\n\s*\n/', trim($sourceCode)) ?: [];
            }
            foreach ($blocks as $block) {
                $block = trim($block, "\r\n");
                if ($block === '') {
                    continue;
                }
                if (preg_match('/^\s*(?:"""|\'\'\')(.*?)(?:"""|\'\'\')\s*$/s', $block, $matches)) {
                    $cells[] = [
                        'cell_type' => 'markdown',
                        'metadata' => new stdClass(),
                        'source' => $this->splitLines($matches[1]),
                    ];
                    continue;
                }
                $cells[] = [
                    'cell_type' => 'code',
                    'execution_count' => null,
                    'metadata' => new stdClass(),
                    'outputs' => [],
                    'source' => $this->splitLines($block),
                ];
            }
            if ($cells === []) {
                $cells[] = [
                    'cell_type' => 'code',
                    'execution_count' => null,
                    'metadata' => new stdClass(),
                    'outputs' => [],
                    'source' => [],
                ];
            }

            $notebook = ['cells' => $cells, 'metadata' => new stdClass(), 'nbformat' => 4, 'nbformat_minor' => 4];
            $json = json_encode($notebook, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false || file_put_contents($output, $json) === false) {
                throw new ConversionException('Não foi possível gerar o ficheiro ipynb.', 500);
            }
            return ['file' => $output, 'name' => $outputName, 'mime' => 'application/x-ipynb+json', 'task_dir' => $taskDirectory];
        } catch (Throwable $exception) {
            ConversionSupport::removeDirectory($taskDirectory);
            if ($exception instanceof ConversionException) {
                throw $exception;
            }
            throw new ConversionException('Não foi possível concluir a conversão para Jupyter Notebook.', 500);
        }
    }

    public function removeTaskDirectory(string $taskDirectory): void
    {
        ConversionSupport::removeDirectory($taskDirectory);
    }

    /** @return list<string> */
    private function splitLines(string $content): array
    {
        $lines = explode("\n", $content);
        $result = [];
        foreach ($lines as $index => $line) {
            $result[] = $line . ($index < count($lines) - 1 ? "\n" : '');
        }
        return $result;
    }
}
