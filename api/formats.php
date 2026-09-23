<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'ConverterService.php';
$config = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = new ConverterService($config);
    $formats = $service->formats();
    if (in_array('ipynb', $config['allowed_output_formats']) && !in_array('ipynb', $formats)) {
        $formats[] = 'ipynb';
    }
    if (in_array('md', $config['allowed_output_formats']) && !in_array('md', $formats)) {
        $formats[] = 'md';
    }
    if (in_array('txt', $config['allowed_output_formats'], true) && !in_array('txt', $formats, true)) {
        $formats[] = 'txt';
    }
    if (in_array('zip', $config['allowed_output_formats']) && !in_array('zip', $formats)) {
        $formats[] = 'zip';
    }
    if (in_array('ipynb', $formats) || in_array('md', $formats) || in_array('zip', $formats)) {
        sort($formats, SORT_NATURAL | SORT_FLAG_CASE);
    }
    echo json_encode($formats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível carregar os formatos disponíveis.'], JSON_UNESCAPED_UNICODE);
}
