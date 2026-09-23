<?php
declare(strict_types=1);

return [
    'app_name' => 'UniversalConverter',
    'storage_root' => __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp',
    'magick_binary' => __DIR__ . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'imagemagick' . DIRECTORY_SEPARATOR . 'magick.exe',
    'ffmpeg_binary' => __DIR__ . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'ffmpeg' . DIRECTORY_SEPARATOR . 'ffmpeg.exe',
    'max_upload_bytes' => 100 * 1024 * 1024,
    'max_dimension' => 16000,
    'command_timeout_seconds' => 300,
    'allowed_output_formats' => [
        'png', 'jpg', 'jpeg', 'webp', 'pdf', 'gif', 'svg', 'ico', 'bmp', 'tiff', 'tif',
        'avif', 'heic', 'jp2', 'jxl', 'pcx', 'tga', 'dds', 'psd', 'eps', 'ps', 'ppm',
        'pgm', 'pbm', 'pam', 'miff', 'xpm', 'xbm', 'dpx', 'exr', 'hdr', 'wbmp', 'cur',
        'mp4', 'webm', 'mkv', 'avi', 'mov', 'mp3', 'wav', 'm4a', 'aac', 'ipynb', 'md', 'zip'
    ],
    'quality_formats' => ['jpg', 'jpeg', 'webp', 'tiff', 'tif', 'avif', 'heic'],
    'video_input_extensions' => ['mp4', 'm4v', 'mov', 'mkv', 'avi', 'webm', 'wmv', 'flv', 'mpeg', 'mpg', '3gp'],
    'audio_input_extensions' => ['mp3', 'm4a', 'aac', 'wav', 'flac', 'ogg', 'opus', 'wma'],
    'video_output_formats' => ['mp4', 'webm', 'mkv', 'avi', 'mov', 'zip'],
    'audio_output_formats' => ['mp3', 'wav', 'm4a', 'aac'],
];
