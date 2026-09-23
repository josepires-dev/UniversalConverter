# UniversalConverter

> Local PHP/Apache file converter for XAMPP, Laragon, WAMP, or any Apache + PHP 8.1+ stack.

UniversalConverter is a native PHP rewrite of the original project. It provides local conversion for images, vectors, documents, audio, and video without Flask, Python, virtual environments, or an emulator server.

> **Local-use notice:** This application is designed for use on a personal machine through Apache. Do not expose it directly to the public Internet without a dedicated security review and an appropriate authentication layer.

## Features

| Area | Supported behavior |
|---|---|
| Graphics | PNG, JPG, WEBP, PDF, GIF, SVG, ICO, and other formats exposed by ImageMagick |
| Video | MP4, WEBM, MKV, AVI, and MOV conversion with FFmpeg |
| Audio | MP3, WAV, M4A, AAC, and other FFmpeg-supported formats |
| Image controls | Resize, rotate, and quality adjustments through ImageMagick |
| Video controls | Resize, rotate, and quality adjustments through FFmpeg |
| Privacy | Uploaded files are kept in a request-specific temporary directory and removed after delivery |
| History | Conversion history is stored only in browser `localStorage` and can be cleared from the interface |
| Downloads | Converted files are returned directly by Apache as `original_converted.ext` |

## Installation

1. Extract the repository into `C:\xampp\htdocs\UniversalConverter`.
2. In the XAMPP `php.ini`, enable `file_uploads=On`, set `upload_max_filesize=100M`, and set `post_max_size=110M` or higher.
3. Make sure `proc_open` is not listed in `disable_functions`.
4. Start or restart Apache from the XAMPP control panel.
5. Open `http://localhost/UniversalConverter/` in your browser.

The same application can be used with Laragon, WAMP, or another Apache + PHP 8.1+ environment after adapting the document root and PHP configuration.

## External tools

Runtime executables are intentionally **not committed** because they are large third-party distributions. Download them from their official sources and place the required files in the corresponding directories under `tools/`:

| Directory | Tool | Official source |
|---|---|---|
| `tools/imagemagick/` | ImageMagick (`magick.exe`, `identify.exe`) | [imagemagick.org](https://imagemagick.org/script/download.php#windows) |
| `tools/ffmpeg/` | FFmpeg (`ffmpeg.exe`) | [ffmpeg.org](https://ffmpeg.org/download.html) |
| `tools/pandoc/` | Pandoc (`pandoc.exe`) | [Pandoc releases](https://github.com/jgm/pandoc/releases) |
| `tools/poppler/` | Poppler PDF utilities | Use a compatible official or distribution-provided build |

After installation, confirm that each executable is directly inside the expected directory, for example `tools/ffmpeg/ffmpeg.exe`. See [`tools/README.md`](tools/README.md) for the repository policy on third-party tools.

## Formats and limitations

The format search list is generated from the installed ImageMagick distribution through `identify -list format` and filtered to formats available in that build. Common graphics formats such as PNG, JPEG, WEBP, BMP, TIFF, GIF, and ICO are the primary path.

Video and audio inputs are routed to FFmpeg. The application targets MP4/MOV with H.264 and AAC, WEBM with VP9/Opus, MKV with H.264/AAC, and AVI with MPEG-4/MP3. PDF/EPS/PS reading and some video formats may require external delegates such as Ghostscript or FFmpeg.

Conversion of Word, Excel, and PowerPoint files to PDF in the original Python project depended on Microsoft Office automation on Windows. This XAMPP version does not include or emulate Microsoft Office; export those documents to PDF first, then convert the PDF with UniversalConverter.

## Security considerations

The application limits uploads to 100 MB, limits image dimensions to 16,000 px, protects temporary files and libraries from direct web access, blocks URL delegates in ImageMagick, and removes request-specific temporary files after processing. Do not change `tools/imagemagick/policy.xml` to enable external sources or indirect paths.

URL conversion resolves the target host and blocks RFC1918 and loopback ranges, including `127.x`, `10.x`, `172.16.x–172.31.x`, and `192.168.x`, to mitigate server-side request forgery (SSRF). See [`SECURITY.md`](SECURITY.md) before deploying or modifying the application.

## Troubleshooting

| Message or symptom | Recommended action |
|---|---|
| `Portable ImageMagick was not found` | Check that the required ImageMagick files were extracted into `tools/imagemagick/` |
| `proc_open` is disabled | Remove `proc_open` from `disable_functions` in `php.ini` and restart Apache |
| Unsupported format | Choose a format listed by the interface or install the delegate required by that format |
| `ReadVIDEOImage` error | Ensure video and audio inputs are being handled by the current FFmpeg path |
| Upload exceeds the limit | Reduce the input size or raise PHP limits while keeping the application limit intentional |

## Contributing

Please read [`CONTRIBUTING.md`](CONTRIBUTING.md) before opening a pull request. Do not commit executables, uploaded files, generated output, credentials, or personal data.

## License

The UniversalConverter source code is released under the [MIT License](LICENSE). The repository also includes third-party notices, including the license for the AnyDoc WASM component in [`licenses/`](licenses/).
