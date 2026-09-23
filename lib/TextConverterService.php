<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'ConversionSupport.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'MarkdownFormatter.php';

final class TextConverterService
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
            $options['format'] ?? 'md',
            $this->config['allowed_output_formats'],
            'O formato de saída selecionado não é suportado nesta instalação.'
        );
        if ($format !== 'md') {
            throw new ConversionException('Este serviço apenas converte para md.', 400);
        }

        $taskDirectory = ConversionSupport::createTaskDirectory($this->config);
        try {
            [$input, $sourceName] = ConversionSupport::storeUpload(
                $file,
                $taskDirectory,
                (int) $this->config['max_upload_bytes'],
                ['md', 'txt', 'py'],
                'Este serviço requer um ficheiro .md, .txt ou .py de entrada.'
            );
            $outputName = ConversionSupport::outputName($sourceName, $format);
            $output = $taskDirectory . DIRECTORY_SEPARATOR . $outputName;
            $extension = strtolower((string) pathinfo($sourceName, PATHINFO_EXTENSION));
            $sourceCode = file_get_contents($input);
            if ($sourceCode === false) {
                throw new ConversionException('Não foi possível ler o ficheiro de entrada.', 500);
            }

            $sourceCode = str_replace(["\r\n", "\r"], "\n", $sourceCode);
            if ($extension === 'py') {
                $sourceCode = "```python\n" . $sourceCode . "\n```\n";
            } elseif ($extension === 'txt') {
                $sourceCode = MarkdownFormatter::enhance($sourceCode);
            }
            if (file_put_contents($output, $sourceCode) === false) {
                throw new ConversionException('Não foi possível gerar o ficheiro MD.', 500);
            }

            return ['file' => $output, 'name' => $outputName, 'mime' => 'text/markdown', 'task_dir' => $taskDirectory];
        } catch (Throwable $exception) {
            ConversionSupport::removeDirectory($taskDirectory);
            if ($exception instanceof ConversionException) {
                throw $exception;
            }
            throw new ConversionException('Não foi possível concluir a conversão para Markdown.', 500);
        }
    }

    public function removeTaskDirectory(string $taskDirectory): void
    {
        ConversionSupport::removeDirectory($taskDirectory);
    }
}
