# Changelog

All notable changes to UniversalConverter are documented in this file. This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

No unreleased changes yet.

## [1.1.2] - 2026-09-23

### Fixed

- PDF files now select the document conversion path in the browser and expose both MD and TXT targets.
- PDF-to-Markdown post-processing now removes common page numbers, institutional headers and footers, repeated blank blocks, and restores common course metadata tables.

### Added

- Regression coverage for PDF Markdown cleanup and page-noise removal.

## [1.1.1] - 2026-09-23

### Added

- Interface screenshot and a Demo section in the README.
- `setup.ps1` and `setup.bat` to prepare the expected Windows/XAMPP directories and run the environment doctor.
- API examples for listing formats and converting a text file.

### Changed

- Expanded the environment doctor to check PHP upload limits, DOM, and the Poppler executable.
- Documented the distinction between tested conversion paths and installation-dependent formats.

## [1.1.0] - 2026-09-23

### Changed

- Consolidated common upload, temporary-directory, command-execution, validation, and output-naming behavior in reusable support classes.
- Consolidated Markdown cleanup behavior used by text, PDF, and URL conversion.

### Added

- Regression tests for shared conversion behavior, text conversion, and Jupyter notebook conversion.
- GitHub Dependabot configuration for GitHub Actions updates.

## [1.0.0] - 2026-09-23

### Added

- Local conversion for graphics, audio, video, plain text, Python files, Jupyter notebooks, PDFs, and URL content.
- `php cli.php doctor` environment diagnostic.
- PHP syntax checks and smoke tests in GitHub Actions for PHP 8.1, 8.2, and 8.3.
- Documentation for bundled third-party tool locations and supported local setup.
- Architecture diagram and project security documentation.

### Fixed

- Validation of invalid target formats submitted to the API.
- Graceful format listing when ImageMagick is not yet installed.
- Pandoc execution with an unescaped binary path passed as an argument array.

### Security

- Per-hop SSRF validation for HTTP redirects, including private and reserved IPv4/IPv6 ranges.
- A maximum of five redirects and a 5 MiB URL-response limit.
- Security headers and request-specific temporary-file cleanup.

[Unreleased]: https://github.com/josepires-dev/UniversalConverter/compare/v1.1.2...HEAD
[1.1.2]: https://github.com/josepires-dev/UniversalConverter/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/josepires-dev/UniversalConverter/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/josepires-dev/UniversalConverter/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/josepires-dev/UniversalConverter/releases/tag/v1.0.0
