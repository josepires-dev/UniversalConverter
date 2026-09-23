<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'ConversionSupport.php';

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
        $width = ConversionSupport::validateDimension($options['width'] ?? null, (int) $this->config['max_dimension'], 'A largura é inválida.');
        $height = ConversionSupport::validateDimension($options['height'] ?? null, (int) $this->config['max_dimension'], 'A altura é inválida.');
        $quality = ConversionSupport::validateQuality($options['quality'] ?? null);
        $rotation = ConversionSupport::validateRotation($options['rotation'] ?? null);
        $taskDirectory = ConversionSupport::createTaskDirectory($this->config);

        try {
            [$input, $sourceName, $inputExtension] = ConversionSupport::storeUpload($file, $taskDirectory, (int) $this->config['max_upload_bytes']);
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

            $outputName = ConversionSupport::outputName($sourceName, $format);
            $output = $taskDirectory . DIRECTORY_SEPARATOR . $outputName;
            if ($format === 'zip') {
                $framesDirectory = $taskDirectory . DIRECTORY_SEPARATOR . 'frames';
                ConversionSupport::ensureDirectory($framesDirectory);
                ConversionSupport::runCommand(
                    [$this->resolveFfmpeg(), '-hide_banner', '-y', '-i', $input, '-qscale:v', '2', $framesDirectory . DIRECTORY_SEPARATOR . 'frame_%05d.jpg'],
                    $taskDirectory,
                    (int) $this->config['command_timeout_seconds'],
                    'FFmpeg',
                    'Não foi possível iniciar o FFmpeg.',
                    'A conversão excedeu o tempo máximo permitido.',
                    'O FFmpeg não conseguiu converter este ficheiro.'
                );

                $zip = new ZipArchive();
                if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new ConversionException('Não foi possível iniciar a compactação ZIP.', 500);
                }
                $files = array_diff(scandir($framesDirectory) ?: [], ['.', '..']);
                $frameCount = 0;
                foreach ($files as $name) {
                    $zip->addFile($framesDirectory . DIRECTORY_SEPARATOR . $name, $name);
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
            ConversionSupport::runCommand(
                $command,
                $taskDirectory,
                (int) $this->config['command_timeout_seconds'],
                'FFmpeg',
                'Não foi possível iniciar o FFmpeg.',
                'A conversão excedeu o tempo máximo permitido.',
                'O FFmpeg não conseguiu converter este ficheiro.'
            );
            if (!is_file($output) || filesize($output) < 1024) {
                throw new ConversionException('O FFmpeg não gerou um ficheiro multimédia válido.', 500);
            }
            return ['file' => $output, 'name' => $outputName, 'mime' => $this->mimeType($format), 'task_dir' => $taskDirectory];
        } catch (Throwable $exception) {
            ConversionSupport::removeDirectory($taskDirectory);
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
        if ($rotation === 90) {
            $filters[] = 'transpose=1';
        }
        if ($rotation === 180) {
            $filters[] = 'hflip';
            $filters[] = 'vflip';
        }
        if ($rotation === 270) {
            $filters[] = 'transpose=2';
        }
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

    private function mimeType(string $format): string
    {
        return match ($format) {
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            default => 'application/octet-stream',
        };
    }

    public function removeTaskDirectory(string $directory): void
    {
        ConversionSupport::removeDirectory($directory);
    }
}
