<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("Este script apenas pode ser executado via linha de comandos (CLI).\n");
}

$config = require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'ConverterService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'MediaConverterService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'NotebookConverterService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'TextConverterService.php';

if (($argv[1] ?? '') === 'doctor') {
    $checks = [
        'PHP >= 8.1' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'proc_open disponível' => function_exists('proc_open'),
        'file_uploads ativo' => filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOL),
        'upload_max_filesize >= 100M' => (int) ini_get('upload_max_filesize') >= 100,
        'post_max_size >= 110M' => (int) ini_get('post_max_size') >= 110,
        'storage/ pode ser criado' => is_dir($config['storage_root']) || @mkdir($config['storage_root'], 0700, true),
        'extensão DOM disponível' => class_exists(DOMDocument::class),
        'ImageMagick instalado' => is_file($config['magick_binary']),
        'FFmpeg instalado' => is_file($config['ffmpeg_binary']),
        'Pandoc instalado' => is_file(__DIR__ . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'pandoc' . DIRECTORY_SEPARATOR . 'pandoc.exe'),
        'pdftotext instalado' => is_file(__DIR__ . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'poppler' . DIRECTORY_SEPARATOR . 'pdftotext.exe'),
        'extensão ZipArchive disponível' => class_exists(ZipArchive::class),
    ];
    $failed = false;
    echo "UniversalConverter environment doctor\n\n";
    foreach ($checks as $label => $ok) {
        printf("[%s] %s\n", $ok ? 'OK' : 'FAIL', $label);
        $failed = $failed || !$ok;
    }
    echo "\n" . ($failed ? "Instalação incompleta: consulte tools/README.md.\n" : "Ambiente pronto para executar o UniversalConverter.\n");
    exit($failed ? 1 : 0);
}

if ($argc < 3) {
    echo "UniversalConverter CLI\n";
    echo "Diagnóstico: php cli.php doctor\n";
    echo "Uso: php cli.php <ficheiro_entrada> <formato_saida> [largura] [altura] [qualidade]\n";
    echo "Exemplo: php cli.php meu_script.py md\n";
    echo "Exemplo: php cli.php imagem.jpg png 800 600\n";
    exit(1);
}

$inputFile = $argv[1];
$outputFormat = strtolower($argv[2]);
$width = $argv[3] ?? null;
$height = $argv[4] ?? null;
$quality = $argv[5] ?? null;

if (!is_file($inputFile) || !is_readable($inputFile)) {
    echo "Erro: O ficheiro '$inputFile' não existe ou não pode ser lido.\n";
    exit(1);
}

// Simulando o upload do ficheiro para a API do conversor
$fileUploadMock = [
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($inputFile),
    'tmp_name' => $inputFile,
    'name' => basename($inputFile)
];

$options = [
    'format' => $outputFormat,
    'width' => $width,
    'height' => $height,
    'quality' => $quality
];

$extension = strtolower(pathinfo($inputFile, PATHINFO_EXTENSION));
$mediaExtensions = array_merge($config['video_input_extensions'], $config['audio_input_extensions']);

echo "A processar '$inputFile' para '$outputFormat'...\n";

try {
    if ($extension === 'py' && $outputFormat === 'ipynb') {
        $service = new NotebookConverterService($config);
    } elseif (($extension === 'txt' && $outputFormat === 'md') || ($extension === 'py' && $outputFormat === 'md')) {
        $service = new TextConverterService($config);
    } elseif ($extension === 'pdf' && in_array($outputFormat, ['md', 'txt'], true)) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'PdfConverterService.php';
        $service = new PdfConverterService($config);
    } elseif ($extension === 'url' && in_array($outputFormat, ['md', 'txt'], true)) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'UrlConverterService.php';
        $service = new UrlConverterService($config);
    } else {
        $service = in_array($extension, $mediaExtensions, true) ? new MediaConverterService($config) : new ConverterService($config);
    }

    $result = $service->convert($fileUploadMock, $options);
    
    // Copy the converted file to the current working directory
    $finalDestination = getcwd() . DIRECTORY_SEPARATOR . $result['name'];
    if (copy($result['file'], $finalDestination)) {
        echo "✅ Sucesso! Ficheiro gerado em: " . $finalDestination . "\n";
    } else {
        echo "❌ Erro ao mover o ficheiro convertido para a pasta atual.\n";
    }

    // Clean up
    $service->removeTaskDirectory($result['task_dir']);
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}
