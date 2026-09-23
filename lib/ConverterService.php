<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'ConversionSupport.php';

final class ConverterService
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        ConversionSupport::ensureDirectory((string) $this->config['storage_root']);
    }

    /** @return list<string> */
    public function formats(): array
    {
        $configured = array_map(static fn ($format): string => strtolower((string) $format), $this->config['allowed_output_formats']);
        $media = array_merge($this->config['video_output_formats'] ?? [], $this->config['audio_output_formats'] ?? []);
        $graphicFormats = array_values(array_diff($configured, $media));
        $writable = $this->writableMagickFormats();
        if ($writable !== []) {
            $aliases = ['jpg' => 'JPEG', 'jpeg' => 'JPEG', 'tif' => 'TIFF', 'tiff' => 'TIFF', 'ps' => 'PS', 'eps' => 'EPS'];
            $graphicFormats = array_values(array_filter($graphicFormats, static function (string $format) use ($writable, $aliases): bool {
                return isset($writable[$aliases[$format] ?? strtoupper($format)]);
            }));
        }
        $formats = array_merge($graphicFormats, $media);
        sort($formats, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values(array_unique($formats));
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed> $options
     * @return array{file:string,name:string,mime:string,task_dir:string}
     */
    public function convert(array $file, array $options): array
    {
        $format = ConversionSupport::validateFormat(
            $options['format'] ?? 'png',
            $this->config['allowed_output_formats'],
            'O formato de saída selecionado não é suportado nesta instalação.'
        );
        $width = ConversionSupport::validateDimension($options['width'] ?? null, (int) $this->config['max_dimension'], 'A largura é inválida.');
        $height = ConversionSupport::validateDimension($options['height'] ?? null, (int) $this->config['max_dimension'], 'A altura é inválida.');
        $quality = ConversionSupport::validateQuality($options['quality'] ?? null);
        $rotation = ConversionSupport::validateRotation($options['rotation'] ?? null);
        $taskDirectory = ConversionSupport::createTaskDirectory($this->config);

        try {
            [$input, $sourceName] = ConversionSupport::storeUpload($file, $taskDirectory, (int) $this->config['max_upload_bytes']);
            $outputName = ConversionSupport::outputName($sourceName, $format);
            $output = $taskDirectory . DIRECTORY_SEPARATOR . $outputName;
            $command = [$this->resolveMagick(), $input, '-auto-orient'];

            if ($width !== null || $height !== null) {
                $command[] = '-resize';
                $command[] = ($width ?? '') . 'x' . ($height ?? '');
            }
            if ($rotation !== 0) {
                $command[] = '-rotate';
                $command[] = (string) $rotation;
            }
            if ($quality !== null && in_array($format, $this->config['quality_formats'], true)) {
                $command[] = '-quality';
                $command[] = (string) $quality;
            }
            if ($format === 'jpg' || $format === 'jpeg') {
                $command = array_merge($command, ['-background', 'white', '-alpha', 'remove']);
            }
            if ($format === 'ico') {
                $command = array_merge($command, ['-define', 'icon:auto-resize=256,128,64,48,32,16']);
            }
            $command[] = $output;
            ConversionSupport::runCommand(
                $command,
                $taskDirectory,
                (int) $this->config['command_timeout_seconds'],
                'ImageMagick',
                'Não foi possível iniciar o ImageMagick.',
                'A conversão excedeu o tempo máximo permitido.',
                'O ImageMagick não conseguiu converter este ficheiro.'
            );

            if (!is_file($output) || filesize($output) < 1) {
                throw new ConversionException('O ImageMagick não gerou um ficheiro convertido válido.', 500);
            }
            return [
                'file' => $output,
                'name' => $outputName,
                'mime' => $this->mimeType($format),
                'task_dir' => $taskDirectory,
            ];
        } catch (Throwable $exception) {
            ConversionSupport::removeDirectory($taskDirectory);
            if ($exception instanceof ConversionException) {
                throw $exception;
            }
            throw new ConversionException('Não foi possível concluir a conversão.', 500);
        }
    }

    public function removeTaskDirectory(string $taskDirectory): void
    {
        ConversionSupport::removeDirectory($taskDirectory);
    }

    private function resolveMagick(): string
    {
        $binary = (string) $this->config['magick_binary'];
        if (!is_file($binary)) {
            throw new ConversionException('O ImageMagick portátil não foi encontrado em tools/imagemagick.', 500);
        }
        return $binary;
    }

    /** @return array<string, true> */
    private function writableMagickFormats(): array
    {
        $binary = (string) $this->config['magick_binary'];
        if (!is_file($binary)) {
            return [];
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open([$binary, 'identify', '-list', 'format'], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return [];
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || $output === '') {
            return [];
        }
        $writable = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z0-9]+)\*?\s+\S+\s+([r-][w-][+-]?)/', $line, $match) === 1 && str_contains(strtolower($match[2]), 'w')) {
                $writable[strtoupper($match[1])] = true;
            }
        }
        return $writable;
    }

    private function mimeType(string $format): string
    {
        return match ($format) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'pdf' => 'application/pdf',
            'tiff', 'tif' => 'image/tiff',
            'avif' => 'image/avif',
            'heic' => 'image/heic',
            default => 'application/octet-stream',
        };
    }
}
