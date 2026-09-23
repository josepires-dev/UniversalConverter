<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'ConversionSupport.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'MarkdownFormatter.php';

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
        if (!in_array($format, ['md', 'txt'], true)) {
            throw new ConversionException('Este serviço (Poppler) apenas converte PDF para MD ou TXT.', 400);
        }

        $taskDirectory = ConversionSupport::createTaskDirectory($this->config);
        try {
            [$input, $sourceName] = ConversionSupport::storeUpload(
                $file,
                $taskDirectory,
                (int) $this->config['max_upload_bytes'],
                ['pdf'],
                'Este serviço requer um ficheiro .pdf de entrada.'
            );
            $outputName = ConversionSupport::outputName($sourceName, $format);
            $output = $taskDirectory . DIRECTORY_SEPARATOR . $outputName;
            ConversionSupport::runCommand(
                [$this->resolveBinary(), '-layout', '-enc', 'UTF-8', $input, $output],
                $taskDirectory,
                (int) $this->config['command_timeout_seconds'],
                'pdftotext',
                'Não foi possível iniciar o pdftotext.',
                'A conversão excedeu o tempo máximo permitido.',
                'O pdftotext não conseguiu extrair o texto.'
            );
            if (!is_file($output)) {
                throw new ConversionException('Não foi possível gerar o ficheiro.', 500);
            }

            if ($format === 'md') {
                $text = file_get_contents($output);
                if ($text !== false && file_put_contents($output, MarkdownFormatter::enhance($text)) === false) {
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
            throw new ConversionException('Não foi possível converter o PDF.', 500);
        }
    }

    private function resolveBinary(): string
    {
        $binary = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'poppler' . DIRECTORY_SEPARATOR . 'pdftotext.exe';
        if (!is_file($binary)) {
            throw new ConversionException('O pdftotext não foi encontrado em tools/poppler.', 500);
        }
        return $binary;
    }

    public function removeTaskDirectory(string $directory): void
    {
        ConversionSupport::removeDirectory($directory);
    }
}
