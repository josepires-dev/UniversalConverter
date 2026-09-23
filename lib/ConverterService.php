<?php
declare(strict_types=1);

final class ConversionException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

final class ConverterService
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        $this->ensureDirectory((string) $this->config['storage_root']);
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
        $format = $this->validateFormat($options['format'] ?? 'png');
        $width = $this->validateDimension($options['width'] ?? null, 'A largura é inválida.');
        $height = $this->validateDimension($options['height'] ?? null, 'A altura é inválida.');
        $quality = $this->validateQuality($options['quality'] ?? null);
        $rotation = $this->validateRotation($options['rotation'] ?? null);
        $taskDirectory = $this->createTaskDirectory();

        try {
            [$input, $sourceName] = $this->storeUpload($file, $taskDirectory);
            $outputName = $this->outputName($sourceName, $format);
            $output = $taskDirectory . DIRECTORY_SEPARATOR . $outputName;
            $command = [$this->resolveMagick(), $input, '-auto-orient'];

            if ($width !== null || $height !== null) {
                $geometry = ($width ?? '') . 'x' . ($height ?? '');
                $command[] = '-resize';
                $command[] = $geometry;
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
                $command[] = '-background';
                $command[] = 'white';
                $command[] = '-alpha';
                $command[] = 'remove';
            }
            if ($format === 'ico') {
                $command[] = '-define';
                $command[] = 'icon:auto-resize=256,128,64,48,32,16';
            }
            $command[] = $output;
            $this->execute($command, $taskDirectory);

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
            $this->removeDirectory($taskDirectory);
            if ($exception instanceof ConversionException) {
                throw $exception;
            }
            throw new ConversionException('Não foi possível concluir a conversão.', 500);
        }
    }

    public function removeTaskDirectory(string $taskDirectory): void
    {
        $this->removeDirectory($taskDirectory);
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
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open([$this->resolveMagick(), 'identify', '-list', 'format'], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
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

    /** @param array<string, mixed> $file @return array{string,string} */
    private function storeUpload(array $file, string $taskDirectory): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new ConversionException($this->uploadError($error), 400);
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
        $sourceName = $this->safeName((string) ($file['name'] ?? 'ficheiro'));
        $extension = strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new ConversionException('O ficheiro deve ter uma extensão.', 400);
        }
        $target = $taskDirectory . DIRECTORY_SEPARATOR . 'entrada.' . $extension;
        
        $moved = php_sapi_name() === 'cli' ? copy($temporaryName, $target) : move_uploaded_file($temporaryName, $target);
        if (!$moved) {
            throw new ConversionException('Não foi possível preparar o ficheiro para conversão.', 500);
        }
        return [$target, $sourceName];
    }

    /** @param list<string> $command */
    private function execute(array $command, string $workingDirectory): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $workingDirectory, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new ConversionException('Não foi possível iniciar o ImageMagick.', 500);
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
            $message = $this->lastLine($stderr . "\n" . $stdout);
            throw new ConversionException($message !== '' ? 'ImageMagick: ' . $message : 'O ImageMagick não conseguiu converter este ficheiro.', 422);
        }
    }

    private function validateFormat(mixed $value): string
    {
        $format = strtolower(trim(is_string($value) ? $value : ''));
        if ($format === 'jpeg') {
            $format = 'jpg';
        }
        if (!in_array($format, $this->config['allowed_output_formats'], true)) {
            throw new ConversionException('O formato de saída selecionado não é suportado nesta instalação.', 400);
        }
        return $format;
    }

    private function validateDimension(mixed $value, string $error): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => (int) $this->config['max_dimension']]]);
        if ($number === false) {
            throw new ConversionException($error, 400);
        }
        return (int) $number;
    }

    private function validateQuality(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($number === false) {
            throw new ConversionException('A qualidade é inválida.', 400);
        }
        return (int) $number;
    }

    private function validateRotation(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $rotation = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($rotation) || !in_array($rotation, [0, 90, 180, 270], true)) {
            throw new ConversionException('A rotação é inválida.', 400);
        }
        return $rotation;
    }

    private function createTaskDirectory(): string
    {
        $directory = (string) $this->config['storage_root'] . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16));
        $this->ensureDirectory($directory);
        return $directory;
    }

    private function outputName(string $sourceName, string $format): string
    {
        $base = pathinfo($sourceName, PATHINFO_FILENAME);
        $base = preg_replace('/[^\pL\pN._ -]+/u', '_', $base) ?? 'ficheiro';
        $base = trim($base, '. ');
        return ($base !== '' ? $base : 'ficheiro') . '_convertido.' . $format;
    }

    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '_', $name) ?? '';
        return trim($name) !== '' ? $name : 'ficheiro';
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

    private function lastLine(string $text): string
    {
        $lines = preg_split('/\R/', trim($text)) ?: [];
        return trim((string) end($lines));
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
}
