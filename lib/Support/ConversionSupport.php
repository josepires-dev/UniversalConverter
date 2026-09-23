<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ConversionException.php';

final class ConversionSupport
{
    /** @param array<string, mixed> $config */
    public static function createTaskDirectory(array $config): string
    {
        $storageRoot = (string) ($config['storage_root'] ?? '');
        if ($storageRoot === '') {
            throw new RuntimeException('A pasta temporária não foi configurada.');
        }

        self::ensureDirectory($storageRoot);
        $directory = $storageRoot . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16));
        self::ensureDirectory($directory);
        return $directory;
    }

    public static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar a pasta temporária.');
        }
    }

    /**
     * @param array<string, mixed> $file
     * @param list<string>|null $allowedExtensions
     * @return array{string,string,string}
     */
    public static function storeUpload(
        array $file,
        string $taskDirectory,
        int $maxUploadBytes,
        ?array $allowedExtensions = null,
        ?string $extensionError = null,
        ?string $uploadErrorMessage = null,
        bool $requireNonEmptyFile = true,
        string $targetBaseName = 'entrada'
    ): array {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new ConversionException($uploadErrorMessage ?? self::uploadError($error), 400);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($requireNonEmptyFile && $size < 1) {
            throw new ConversionException('Selecione um ficheiro não vazio.', 400);
        }
        if ($size > $maxUploadBytes) {
            throw new ConversionException('O ficheiro excede o limite de 100 MB.', 413);
        }

        $temporaryName = (string) ($file['tmp_name'] ?? '');
        if ($temporaryName === '') {
            throw new ConversionException('O carregamento do ficheiro não é válido.', 400);
        }
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($temporaryName)) {
            throw new ConversionException('O ficheiro não foi submetido via upload.', 400);
        }

        $sourceName = self::safeName((string) ($file['name'] ?? 'ficheiro'));
        $extension = strtolower((string) pathinfo($sourceName, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new ConversionException('O ficheiro deve ter uma extensão.', 400);
        }
        if ($allowedExtensions !== null && !in_array($extension, $allowedExtensions, true)) {
            throw new ConversionException($extensionError ?? 'O formato de entrada não é suportado.', 400);
        }

        $target = $taskDirectory . DIRECTORY_SEPARATOR . $targetBaseName . '.' . $extension;
        $moved = PHP_SAPI === 'cli' ? copy($temporaryName, $target) : move_uploaded_file($temporaryName, $target);
        if (!$moved) {
            throw new ConversionException('Não foi possível preparar o ficheiro para conversão.', 500);
        }

        return [$target, $sourceName, $extension];
    }

    /** @param list<string> $allowedFormats */
    public static function validateFormat(mixed $value, array $allowedFormats, string $message): string
    {
        $format = strtolower(trim(is_string($value) ? $value : ''));
        if ($format === 'jpeg') {
            $format = 'jpg';
        }
        if (!in_array($format, $allowedFormats, true)) {
            throw new ConversionException($message, 400);
        }
        return $format;
    }

    public static function validateDimension(mixed $value, int $maxDimension, string $message): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maxDimension]]);
        if ($number === false) {
            throw new ConversionException($message, 400);
        }
        return (int) $number;
    }

    public static function validateQuality(mixed $value): ?int
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

    public static function validateRotation(mixed $value): int
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

    public static function outputName(string $sourceName, string $format): string
    {
        $base = pathinfo($sourceName, PATHINFO_FILENAME);
        $base = preg_replace('/[^\pL\pN._ -]+/u', '_', $base) ?? 'ficheiro';
        $base = trim($base, '. ');
        return ($base !== '' ? $base : 'ficheiro') . '_convertido.' . $format;
    }

    public static function removeDirectory(string $directory): void
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

    /**
     * @param list<string> $command
     */
    public static function runCommand(
        array $command,
        string $workingDirectory,
        int $timeoutSeconds,
        string $toolName,
        string $startError,
        string $timeoutError,
        string $failureError
    ): void {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $workingDirectory, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new ConversionException($startError, 500);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
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
                throw new ConversionException($timeoutError, 504);
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
            $message = self::lastUsefulLine($stderr . "\n" . $stdout);
            throw new ConversionException($message !== '' ? $toolName . ': ' . $message : $failureError, 422);
        }
    }

    public static function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O ficheiro excede o limite de upload permitido.',
            UPLOAD_ERR_PARTIAL => 'O carregamento do ficheiro ficou incompleto.',
            UPLOAD_ERR_NO_FILE => 'Nenhum ficheiro foi enviado.',
            default => 'Ocorreu um erro durante o carregamento do ficheiro.',
        };
    }

    private static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '_', $name) ?? '';
        return trim($name) !== '' ? $name : 'ficheiro';
    }

    private static function lastUsefulLine(string $text): string
    {
        $lines = array_reverse(preg_split('/\R/', trim($text)) ?: []);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, 'frame=')) {
                return $line;
            }
        }
        return '';
    }
}
