<?php
declare(strict_types=1);

final class MarkdownFormatter
{
    public static function enhance(string $text, bool $removePromptArtifacts = true): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $rawLines = array_map('trim', explode("\n", str_replace(["\r\n", "\r"], "\n", $text)));
        while ($rawLines !== [] && $rawLines[0] === '') {
            array_shift($rawLines);
        }

        $rawLines = array_filter($rawLines, static function (string $line) use ($removePromptArtifacts): bool {
            if (preg_match('/^\*?🕐?\s*\d{1,2}\s+de\s+[a-z]+\.?\s+de\s+\d{4}/i', $line)) {
                return false;
            }
            if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?/i', $line)) {
                return false;
            }
            if (preg_match('/\[stay in caveman/i', $line)) {
                return false;
            }
            if (preg_match('/^Turno\s+\d+/i', $line)) {
                return false;
            }
            if (preg_match('/^(Usuário|Gemini|🔗 Link original|📅 Exportado em|Gerado por)/i', $line)) {
                return false;
            }
            return !$removePromptArtifacts || preg_match('/como\s+descreverias|como\s+descreveria|super\s+detalhada|forma\s+super|como\s+é\s+que\s+é/i', $line) !== 1;
        });

        $result = [];
        $inSection = false;
        foreach ($rawLines as $line) {
            if ($line === '') {
                $result[] = '';
                continue;
            }
            if (preg_match('/^[-*_]{3,}$/', $line)) {
                $result[] = '---';
                continue;
            }

            $line = str_replace(['\\', '`'], ['/', ''], $line);
            if (preg_match('/^[\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $line)) {
                $line = preg_replace('/^#+\s*/', '', $line) ?? $line;
                $result[] = '## ' . $line;
                $inSection = false;
                continue;
            }
            if (preg_match('/^(Colega \d+|Projeto \d+|Fase \d+|Etapa \d+|Modulo \d+|Relatório|Veredito|Conclusão)/i', $line) && !str_contains($line, ':')) {
                $line = preg_replace('/^#+\s*/', '', $line) ?? $line;
                $result[] = '## ' . $line;
                $inSection = false;
                continue;
            }
            if (preg_match('/^[A-Za-zÀ-ÿ0-9\s\/()_-]{2,30}:$/i', $line) || preg_match('/^Resumo/i', $line)) {
                $line = preg_replace('/^#+\s*/', '', $line) ?? $line;
                if (preg_match('/^Resumo/i', $line)) {
                    $line = '**' . str_replace('**', '', $line) . '**';
                } else {
                    $result[] = '### ' . $line;
                    $inSection = true;
                    continue;
                }
            }

            if (str_contains($line, '?') && !str_starts_with($line, '-') && !str_starts_with($line, '*') && !str_starts_with($line, '#')) {
                $questionIndex = strpos($line, '?');
                $line = '- **' . trim(substr($line, 0, $questionIndex + 1)) . '** ' . trim(substr($line, $questionIndex + 1));
            } elseif (str_contains($line, ': ') && !str_starts_with($line, '#') && !str_starts_with($line, '-') && !str_starts_with($line, '*') && !str_starts_with($line, '>')) {
                $colonIndex = strpos($line, ': ');
                $label = trim(substr($line, 0, $colonIndex));
                $value = trim(substr($line, $colonIndex + 2));
                if (strlen($label) < 40 && !str_contains($label, "\n")) {
                    $line = $inSection ? '- **' . $label . ':** ' . $value : '**' . $label . ':** ' . $value;
                }
            } elseif (preg_match('/^[•▪]\s+/', $line)) {
                $line = '- ' . preg_replace('/^[•▪]\s+/', '', $line);
            }

            $line = (string) preg_replace('/\b((?:data|notebooks|src|models|docs|lib|api|tools|raw|interim)\/[a-zA-Z0-9_.\-\/]+)\b/i', '`$1`', $line);
            $line = (string) preg_replace('/(?<!`)\b([a-zA-Z0-9_-]+\.(?:csv|py|ipynb|docx|pdf|png|jpg|json|html|js|php|exe|txt|md))\b(?!`)/i', '`$1`', $line);
            $result[] = $line;
        }

        return implode("\n", $result);
    }
}
