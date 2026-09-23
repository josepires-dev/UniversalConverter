# Dependências externas

Os binários desta pasta não são versionados porque são grandes, específicos do sistema operativo e distribuídos por terceiros. O UniversalConverter foi desenhado para execução local em Apache/XAMPP no Windows.

## Estrutura esperada

```text
tools/
├── ffmpeg/ffmpeg.exe
├── imagemagick/magick.exe
├── imagemagick/identify.exe
├── pandoc/pandoc.exe
└── poppler/              # utilitários PDF, quando necessários
```

## Downloads oficiais

| Ferramenta | Utilização | Download |
|---|---|---|
| ImageMagick | Imagens, SVG e PDF através de delegates | [imagemagick.org](https://imagemagick.org/script/download.php#windows) |
| FFmpeg | Áudio e vídeo | [ffmpeg.org](https://ffmpeg.org/download.html) |
| Pandoc | Conversão de páginas Web para Markdown/TXT | [GitHub Releases](https://github.com/jgm/pandoc/releases) |
| Poppler | Utilitários PDF opcionais | [poppler releases](https://github.com/oschwartz10612/poppler-windows/releases) |

Depois de extrair cada ferramenta, coloque os executáveis diretamente na pasta correspondente. Não renomeie os ficheiros esperados.

## Verificação

Na raiz do projeto, execute:

```powershell
php cli.php doctor
```

O diagnóstico indica quais ferramentas, extensões PHP e permissões ainda precisam de configuração. Nunca descarregue binários de fontes não oficiais nem altere a política do ImageMagick para permitir delegates de rede.
