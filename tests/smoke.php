<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . DIRECTORY_SEPARATOR . 'config.php';
require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'ConverterService.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert($config['max_upload_bytes'] === 100 * 1024 * 1024, 'O limite de upload deve ser 100 MiB.');
$assert($config['max_dimension'] === 16000, 'A dimensão máxima deve ser 16000 px.');
$assert(in_array('png', $config['allowed_output_formats'], true), 'PNG deve estar disponível.');
$assert(in_array('mp4', $config['video_output_formats'], true), 'MP4 deve estar disponível para vídeo.');
$assert(is_dir($root . DIRECTORY_SEPARATOR . 'lib'), 'A pasta lib deve existir.');
$assert(is_file($root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'README.md'), 'A documentação de tools deve existir.');

$service = new ConverterService($config);
$formats = $service->formats();
$assert(in_array('png', $formats, true), 'A lista de formatos deve incluir PNG sem ImageMagick instalado.');
$assert(in_array('mp4', $formats, true), 'A lista de formatos deve incluir MP4.');
$assert(in_array('txt', $formats, true), 'TXT deve estar disponível para conversão de PDF.');

if ($failures !== []) {
    fwrite(STDERR, "Smoke tests falharam:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Smoke tests OK\n";
