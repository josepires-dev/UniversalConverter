<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'ConverterService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'MediaConverterService.php';
$config = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');

/** @param array<string, mixed> $body */
function sendJson(array $body, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    sendJson(['error' => 'Método não permitido.'], 405);
}

try {
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        sendJson(['error' => 'Envie um ficheiro para converter.'], 400);
    }
    $name = strtolower((string) ($_FILES['file']['name'] ?? ''));
    $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    $mediaExtensions = array_merge($config['video_input_extensions'], $config['audio_input_extensions']);
    $targetFormat = strtolower($_POST['format'] ?? '');
    
    if ($extension === 'py' && $targetFormat === 'ipynb') {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'NotebookConverterService.php';
        $service = new NotebookConverterService($config);
    } elseif (in_array($extension, ['md', 'txt', 'py'], true) && $targetFormat === 'md') {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'TextConverterService.php';
        $service = new TextConverterService($config);
    } elseif ($extension === 'pdf' && in_array($targetFormat, ['md', 'txt'], true)) {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'PdfConverterService.php';
        $service = new PdfConverterService($config);
    } elseif ($extension === 'url' && in_array($targetFormat, ['md', 'txt'], true)) {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'UrlConverterService.php';
        $service = new UrlConverterService($config);
    } else {
        $service = in_array($extension, $mediaExtensions, true) ? new MediaConverterService($config) : new ConverterService($config);
    }
    $result = $service->convert($_FILES['file'], $_POST);
} catch (ConversionException $exception) {
    sendJson(['error' => $exception->getMessage()], $exception->httpStatus);
} catch (Throwable) {
    sendJson(['error' => 'Ocorreu um erro interno durante a conversão.'], 500);
}

$file = $result['file'];
$taskDirectory = $result['task_dir'];
if (!is_file($file) || !is_readable($file)) {
    $service->removeTaskDirectory($taskDirectory);
    sendJson(['error' => 'O ficheiro convertido já não está disponível.'], 500);
}

clearstatcache(true, $file);
$fileSize = filesize($file);
if ($fileSize === false || $fileSize < 1) {
    $service->removeTaskDirectory($taskDirectory);
    sendJson(['error' => 'O ficheiro convertido está vazio ou já não está disponível.'], 500);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
ignore_user_abort(true);
http_response_code(200);
header('Content-Type: ' . $result['mime']);
header('Content-Length: ' . (string) $fileSize);
header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode($result['name']));

try {
    $handle = fopen($file, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Não foi possível abrir o ficheiro convertido.');
    }
    while (!feof($handle)) {
        $chunk = fread($handle, 1024 * 1024);
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        flush();
    }
    fclose($handle);
} finally {
    $service->removeTaskDirectory($taskDirectory);
}
