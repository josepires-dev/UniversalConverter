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

if ($argc < 3) {
    echo "UniversalConverter CLI\n";
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
