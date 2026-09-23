# UniversalConverter

> Ferramenta local PHP/Apache — compatível com XAMPP, Laragon, WAMP ou qualquer stack Apache+PHP 8.1+
> Este projeto é exclusivamente para uso local com XAMPP/Apache numa máquina pessoal.
> Não deve ser exposto à internet: o fetch de URLs não filtra redes internas por padrão em versões antigas, e os binários portáteis em `tools/` não são distribuídos neste repositório.
> Vê a secção [Download dos Binários](#download-dos-binários) antes de usar.

O **UniversalConverter** é uma reescrita nativa em PHP para Apache/XAMPP do projeto original. Faz conversões locais de imagens, vetores e ficheiros compatíveis com ImageMagick, sem Flask, Python, ambiente virtual ou servidor emulador.

## Instalação

1. Extrai a pasta `UniversalConverter` para `C:\xampp\htdocs\`.
2. Confirma no `php.ini` do XAMPP que `file_uploads=On`, `upload_max_filesize=100M` e `post_max_size=110M` ou valores superiores estão definidos.
3. Confirma que `proc_open` não aparece em `disable_functions`.
4. Inicie ou reinicie o Apache no painel XAMPP.
5. Abre `http://localhost/UniversalConverter/`.

## Download dos Binários

Os executáveis **não estão incluídos no repositório** (ficheiros grandes, não adequados para Git).
Descarrega e extrai manualmente para as pastas indicadas:

| Pasta em `tools/`        | Binário            | Fonte oficial                                       |
|--------------------------|--------------------|-----------------------------------------------------|
| `tools/imagemagick/`     | `magick.exe`       | https://imagemagick.org/script/download.php#windows |
| `tools/ffmpeg/`          | `ffmpeg.exe`       | https://ffmpeg.org/download.html                    |
| `tools/pandoc/`          | `pandoc.exe`       | https://github.com/jgm/pandoc/releases              |

Após descarregar, confirma que o executável está directamente dentro da pasta indicada (ex: `tools/ffmpeg/ffmpeg.exe`).

## Funcionalidades

| Função | Comportamento |
|---|---|
| Conversão gráfica | PNG, JPG, WEBP, PDF, GIF, SVG, ICO e outros formatos expostos pela aplicação |
| Conversão multimédia | MP4, WEBM, MKV, AVI e MOV; extração ou conversão de MP3, WAV, M4A e AAC |
| Ajustes de imagem | Redimensionamento, rotação e qualidade aplicados pelo ImageMagick |
| Ajustes de vídeo | Redimensionamento, rotação e qualidade aplicados pelo FFmpeg |
| Áudio | Conversão de formato; opções visuais são ocultadas porque não se aplicam |
| Privacidade | O ficheiro é guardado só numa pasta temporária própria do pedido e apagado após o envio |
| Histórico | Guardado apenas no `localStorage` do navegador e pode ser apagado na interface |
| Download | O ficheiro é entregue diretamente pelo Apache com o nome `original_convertido.ext` |

## Limites e formatos especiais

A lista da pesquisa é construída a partir do próprio ImageMagick portátil através de `identify -list format` e filtrada para formatos de saída disponíveis nesse binário. Isto evita prometer todos os formatos da documentação geral quando uma biblioteca ou delegado opcional não está incluído. Formatos gráficos comuns, como PNG, JPEG, WEBP, BMP, TIFF, GIF e ICO, são o percurso principal. Ficheiros de vídeo e áudio são encaminhados automaticamente para o FFmpeg; para vídeo, a aplicação cria MP4/MOV com H.264 e AAC, WEBM com VP9/Opus, MKV com H.264/AAC e AVI com MPEG-4/MP3.

A leitura de PDF/EPS/PS e alguns formatos de vídeo pode necessitar de delegados externos, como Ghostscript ou FFmpeg. A conversão de Word, Excel e PowerPoint para PDF no projeto Python original dependia das aplicações Microsoft Office instaladas no Windows através de automação COM; esta versão XAMPP não inclui nem simula Microsoft Office. Para esses documentos, guarde primeiro em PDF numa aplicação de escritório e depois converta o PDF no UniversalConverter.

## Segurança

A aplicação limita cada upload a 100 MB, limita dimensões a 16 000 px, impede acesso web a temporários, bibliotecas e executáveis, bloqueia URLs no ImageMagick e elimina os ficheiros temporários no fim de cada pedido. Não altere `tools/imagemagick/policy.xml` para permitir fontes externas ou caminhos indiretos.

O fetch de URLs (`UrlConverterService`) resolve o host e bloqueia endereços RFC1918 e loopback (`127.x`, `10.x`, `172.16-31.x`, `192.168.x`) para prevenir SSRF.

## Resolução de problemas

| Mensagem | Ação recomendada |
|---|---|
| `O ImageMagick portátil não foi encontrado` | Confirme que a pasta `tools/imagemagick` foi extraída integralmente |
| `proc_open` desativado | Remova `proc_open` de `disable_functions` no `php.ini` e reinicie Apache |
| Formato não suportado | Escolha um formato listado pela busca da interface ou verifique se o formato depende de um delegado externo |
| Erro `ReadVIDEOImage` | Instale esta atualização: vídeos e áudio são agora processados por FFmpeg, e não pelo ImageMagick |
| Upload excede o limite | Reduza o ficheiro ou aumente os limites no `php.ini`, mantendo pelo menos o valor da configuração da aplicação |
