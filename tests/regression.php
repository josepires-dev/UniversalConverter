<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . DIRECTORY_SEPARATOR . 'config.php';
require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'ConverterService.php';
require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'TextConverterService.php';
require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'NotebookConverterService.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(
    ConversionSupport::validateFormat('JPEG', $config['allowed_output_formats'], 'invalid') === 'jpg',
    'JPEG deve ser normalizado para JPG.'
);
try {
    ConversionSupport::validateDimension(0, (int) $config['max_dimension'], 'invalid');
    $failures[] = 'Dimensão zero deve ser rejeitada.';
} catch (ConversionException $exception) {
    $assert($exception->httpStatus === 400, 'Dimensão inválida deve devolver HTTP 400.');
}
$assert(
    ConversionSupport::outputName('../relatório: final.png', 'webp') === 'relatório_ final_convertido.webp',
    'O nome de saída deve ser seguro e previsível.'
);

$directory = ConversionSupport::createTaskDirectory($config);
$assert(is_dir($directory), 'A pasta temporária deve ser criada.');
ConversionSupport::removeDirectory($directory);
$assert(!is_dir($directory), 'A pasta temporária deve ser removida.');

try {
    ConversionSupport::runCommand(
        [PHP_BINARY, '-r', 'exit(0);'],
        sys_get_temp_dir(),
        3,
        'PHP',
        'start error',
        'timeout error',
        'failure error'
    );
} catch (Throwable $exception) {
    $failures[] = 'Um processo bem-sucedido não deve falhar: ' . $exception->getMessage();
}
try {
    ConversionSupport::runCommand(
        [PHP_BINARY, '-r', 'fwrite(STDERR, "failed"); exit(2);'],
        sys_get_temp_dir(),
        3,
        'PHP',
        'start error',
        'timeout error',
        'failure error'
    );
    $failures[] = 'Um processo com erro deve lançar ConversionException.';
} catch (ConversionException $exception) {
    $assert($exception->httpStatus === 422, 'Falhas de processos devem devolver HTTP 422.');
    $assert(str_contains($exception->getMessage(), 'failed'), 'A última mensagem do processo deve ser preservada.');
}

$input = tempnam(sys_get_temp_dir(), 'universalconverter-test-');
if ($input === false) {
    throw new RuntimeException('Não foi possível criar o ficheiro de teste.');
}
file_put_contents($input, "Resumo:\nFerramenta de conversão.\n");
$textService = new TextConverterService($config);
$textResult = $textService->convert([
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($input),
    'tmp_name' => $input,
    'name' => 'resumo.txt',
], ['format' => 'md']);
$text = file_get_contents($textResult['file']);
$assert($text !== false && str_contains($text, '**Resumo:**'), 'A conversão de TXT para Markdown deve preservar a formatação.');
$textService->removeTaskDirectory($textResult['task_dir']);

file_put_contents($input, "# %%\nprint('ok')\n\n# %%\n\"\"\"Título\"\"\"\n");
$notebookService = new NotebookConverterService($config);
$notebookResult = $notebookService->convert([
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($input),
    'tmp_name' => $input,
    'name' => 'exemplo.py',
], ['format' => 'ipynb']);
$notebook = json_decode((string) file_get_contents($notebookResult['file']), true);
$assert(is_array($notebook) && ($notebook['nbformat'] ?? null) === 4, 'A conversão para notebook deve gerar nbformat 4.');
$assert(is_array($notebook) && count($notebook['cells'] ?? []) === 2, 'Os marcadores de células devem ser preservados.');
$notebookService->removeTaskDirectory($notebookResult['task_dir']);
unlink($input);

if ($failures !== []) {
    fwrite(STDERR, "Testes de regressão falharam:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Testes de regressão OK\n";
