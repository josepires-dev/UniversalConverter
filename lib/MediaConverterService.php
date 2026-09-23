<?php
declare(strict_types=1);

final class MediaConverterService
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
        if ($format === 'jpeg') {
            $format = 'jpg';
        }
        $width = $this->validateDimension($options['width'] ?? null, 'A largura é inválida.');
        $height = $this->validateDimension($options['height'] ?? null, 'A altura é inválida.');
        $quality = $this->validateQuality($options['quality'] ?? null);
        $rotation = $this->validateRotation($options['rotation'] ?? null);
        $taskDirectory = $this->createTaskDirectory();
        try {
            [$input, $sourceName, $inputExtension] = $this->storeUpload($file, $taskDirectory);
            $isVideo = in_array($inputExtension, $this->config['video_input_extensions'], true);
            $isAudio = in_array($inputExtension, $this->config['audio_input_extensions'], true);
            if (!$isVideo && !$isAudio) {
                throw new ConversionException('Este ficheiro não é um formato de vídeo ou áudio suportado.', 400);
            }
            $videoFormats = $this->config['video_output_formats'];
            $audioFormats = $this->config['audio_output_formats'];
            if (!in_array($format, $videoFormats, true) && !in_array($format, $audioFormats, true)) {
                throw new ConversionException('Para vídeos e áudio, escolha MP4, WEBM, MKV, AVI, MOV, MP3, WAV, M4A ou AAC.', 400);
            }
            if ($isAudio && in_array($format, $videoFormats, true)) {
                throw new ConversionException('Não é possível criar um vídeo a partir de um ficheiro que contém apenas áudio.', 400);
            }
            $outputName = $this->outputName($sourceName, $format);
            $output = $taskDirectory . DIRECTORY_SEPARATOR . $outputName;

            if ($format === 'zip') {
                $framesDir = $taskDirectory . DIRECTORY_SEPARATOR . 'frames';
                if (!mkdir($framesDir, 0777, true) && !is_dir($framesDir)) {
                    throw new ConversionException('Não foi possível criar a pasta temporária para os frames.', 500);
                }
                
                // Extrai todos os frames em jpg de alta qualidade
                $command = [
                    $this->resolveFfmpeg(), '-hide_banner', '-y', '-i', $input,
                    '-qscale:v', '2',
                    $framesDir . DIRECTORY_SEPARATOR . 'frame_%05d.jpg'
                ];
                $this->execute($command, $taskDirectory);
                
                $zip = new ZipArchive();
                if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new ConversionException('Não foi possível iniciar a compactação ZIP.', 500);
                }
                
                $files = array_diff(scandir($framesDir) ?: [], ['.', '..']);
                $frameCount = 0;
                foreach ($files as $f) {
                    $zip->addFile($framesDir . DIRECTORY_SEPARATOR . $f, $f);
                    $frameCount++;
                }
                $zip->close();
                
                if ($frameCount === 0) {
                    throw new ConversionException('O vídeo não continha frames válidos para extrair.', 422);
                }
                
                return ['file' => $output, 'name' => $outputName, 'mime' => 'application/zip', 'task_dir' => $taskDirectory];
            }

            $command = [$this->resolveFfmpeg(), '-hide_banner', '-y', '-i', $input];

            if (in_array($format, $audioFormats, true)) {
                $command = array_merge($command, ['-vn'], $this->audioArguments($format));
            } else {
                $filters = $this->videoFilters($width, $height, $rotation);
                if ($filters !== []) {
                    $command = array_merge($command, ['-vf', implode(',', $filters)]);
                }
                $command = array_merge($command, $this->videoArguments($format, $quality));
            }
            $command[] = $output;
            $this->execute($command, $taskDirectory);
            if (!is_file($output) || filesize($output) < 1024) {
                throw new ConversionException('O FFmpeg não gerou um ficheiro multimédia válido.', 500);
            }
            return ['file' => $output, 'name' => $outputName, 'mime' => $this->mimeType($format), 'task_dir' => $taskDirectory];
        } catch (Throwable $exception) {
            $this->removeTaskDirectory($taskDirectory);
            if ($exception instanceof ConversionException) {
                throw $exception;
            }
            throw new ConversionException('Não foi possível converter o ficheiro multimédia.', 500);
        }
    }

    /** @return list<string> */
    private function videoArguments(string $format, ?int $quality): array
    {
        $quality ??= 90;
        $x264Crf = (string) max(18, min(40, (int) round(40 - ($quality * 0.22))));
        $vp9Crf = (string) max(18, min(52, (int) round(52 - ($quality * 0.34))));
        $aviQuality = (string) max(2, min(31, (int) round(31 - ($quality * 0.28))));
        return match ($format) {
            'mp4', 'mov' => ['-map', '0:v:0?', '-map', '0:a:0?', '-c:v', 'libx264', '-preset', 'medium', '-crf', $x264Crf, '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart'],
            'webm' => ['-map', '0:v:0?', '-map', '0:a:0?', '-c:v', 'libvpx-vp9', '-crf', $vp9Crf, '-b:v', '0', '-c:a', 'libopus', '-b:a', '128k'],
            'mkv' => ['-map', '0:v:0?', '-map', '0:a:0?', '-c:v', 'libx264', '-crf', $x264Crf, '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '192k'],
            'avi' => ['-map', '0:v:0?', '-map', '0:a:0?', '-c:v', 'mpeg4', '-q:v', $aviQuality, '-c:a', 'libmp3lame', '-b:a', '192k'],
            default => throw new ConversionException('O formato de vídeo selecionado não é suportado.', 400),
        };
    }

    /** @return list<string> */
    private function videoFilters(?int $width, ?int $height, int $rotation): array
    {
        $filters = [];
        if ($width !== null || $height !== null) {
            $filters[] = match (true) {
                $width !== null && $height !== null => 'scale=' . $width . ':' . $height,
                $width !== null => 'scale=' . $width . ':-2',
                default => 'scale=-2:' . $height,
            };
        }
        if ($rotation === 90) $filters[] = 'transpose=1';
        if ($rotation === 180) { $filters[] = 'hflip'; $filters[] = 'vflip'; }
        if ($rotation === 270) $filters[] = 'transpose=2';
        return $filters;
    }

    /** @return list<string> */
    private function audioArguments(string $format): array
    {
        return match ($format) {
            'mp3' => ['-c:a', 'libmp3lame', '-b:a', '192k'],
            'wav' => ['-c:a', 'pcm_s16le'],
            'm4a' => ['-c:a', 'aac', '-b:a', '192k'],
            'aac' => ['-c:a', 'aac', '-b:a', '192k'],
            default => throw new ConversionException('O formato de áudio selecionado não é suportado.', 400),
        };
    }

    private function resolveFfmpeg(): string
    {
        $binary = (string) $this->config['ffmpeg_binary'];
        if (!is_file($binary)) {
            throw new ConversionException('O FFmpeg portátil não foi encontrado em tools/ffmpeg.', 500);
        }
        return $binary;
    }

    /** @param array<string, mixed> $file @return array{string,string,string} */
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
        $sourceName = $this->safeName((string) ($file['name'] ?? 'ficheiro'));
        $extension = strtolower((string) pathinfo($sourceName, PATHINFO_EXTENSION));
        if ($extension === '') throw new ConversionException('O ficheiro deve ter uma extensão.', 400);
        $target = $taskDirectory . DIRECTORY_SEPARATOR . 'entrada.' . $extension;
        $moved = php_sapi_name() === 'cli' ? copy($temporaryName, $target) : move_uploaded_file($temporaryName, $target);
        if (!$moved) throw new ConversionException('Não foi possível preparar o ficheiro para conversão.', 500);
        return [$target, $sourceName, $extension];
    }

    /** @param list<string> $command */
    private function execute(array $command, string $workingDirectory): void
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $workingDirectory, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new ConversionException('Não foi possível iniciar o FFmpeg.', 500);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $stdout = ''; $stderr = ''; $deadline = microtime(true) + (int) $this->config['command_timeout_seconds']; $reportedExitCode = null;
        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: ''; $stderr .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($process);
            if (!$status['running']) { $reportedExitCode = is_int($status['exitcode']) ? $status['exitcode'] : null; break; }
            if (microtime(true) > $deadline) { proc_terminate($process); fclose($pipes[1]); fclose($pipes[2]); proc_close($process); throw new ConversionException('A conversão excedeu o tempo máximo permitido.', 504); }
            usleep(50_000);
        }
        $stdout .= stream_get_contents($pipes[1]) ?: ''; $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]); fclose($pipes[2]);
        $exitCode = proc_close($process); if ($exitCode === -1 && $reportedExitCode !== null) $exitCode = $reportedExitCode;
        if ($exitCode !== 0) {
            $message = $this->lastUsefulLine($stderr . "\n" . $stdout);
            throw new ConversionException($message !== '' ? 'FFmpeg: ' . $message : 'O FFmpeg não conseguiu converter este ficheiro.', 422);
        }
    }

    private function validateDimension(mixed $value, string $error): ?int
    {
        if ($value === null || $value === '') return null;
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => (int) $this->config['max_dimension']]]);
        if ($number === false) throw new ConversionException($error, 400);
        return (int) $number;
    }
    private function validateQuality(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($number === false) throw new ConversionException('A qualidade é inválida.', 400);
        return (int) $number;
    }
    private function validateRotation(mixed $value): int
    {
        if ($value === null || $value === '') return 0;
        $rotation = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($rotation) || !in_array($rotation, [0, 90, 180, 270], true)) throw new ConversionException('A rotação é inválida.', 400);
        return $rotation;
    }

    private function createTaskDirectory(): string
    {
        $directory = (string) $this->config['storage_root'] . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16));
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Não foi possível criar a pasta temporária.');
        return $directory;
    }
    private function outputName(string $sourceName, string $format): string
    {
        $base = pathinfo($sourceName, PATHINFO_FILENAME); $base = preg_replace('/[^\pL\pN._ -]+/u', '_', $base) ?? 'ficheiro'; $base = trim($base, '. ');
        return ($base !== '' ? $base : 'ficheiro') . '_convertido.' . $format;
    }
    private function safeName(string $name): string { $name = basename(str_replace('\\', '/', $name)); $name = preg_replace('/[\x00-\x1F\x7F]+/u', '_', $name) ?? ''; return trim($name) !== '' ? $name : 'ficheiro'; }
    private function uploadError(int $error): string { return match ($error) { UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O ficheiro excede o limite de upload permitido.', UPLOAD_ERR_PARTIAL => 'O carregamento do ficheiro ficou incompleto.', UPLOAD_ERR_NO_FILE => 'Nenhum ficheiro foi enviado.', default => 'Ocorreu um erro durante o carregamento do ficheiro.', }; }
    private function mimeType(string $format): string { return match ($format) { 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo', 'mov' => 'video/quicktime', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', default => 'application/octet-stream', }; }
    private function lastUsefulLine(string $text): string { $lines = array_reverse(preg_split('/\R/', trim($text)) ?: []); foreach ($lines as $line) { $line = trim($line); if ($line !== '' && !str_starts_with($line, 'frame=')) return $line; } return ''; }
    public function removeTaskDirectory(string $directory): void { if (!is_dir($directory)) return; $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($iterator as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); } rmdir($directory); }
}
