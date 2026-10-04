<?php

return [
    'ffmpeg' => env('HUB_FFMPEG', PHP_OS_FAMILY === 'Windows' ? null : '/usr/bin/ffmpeg'),
    'chart_font' => env('HUB_CHART_FONT', PHP_OS_FAMILY === 'Windows' ? 'C:/Windows/Fonts/arial.ttf' : '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'),
    'pdftotext' => env('HUB_PDFTOTEXT', PHP_OS_FAMILY === 'Windows' ? 'pdftotext.exe' : '/usr/bin/pdftotext'),
    'convert' => env('HUB_IMAGE_CONVERT', PHP_OS_FAMILY === 'Windows' ? null : '/usr/bin/convert'),
    'report_chrome' => env('HUB_REPORT_CHROME', PHP_OS_FAMILY === 'Windows' ? null : '/usr/bin/google-chrome'),
    'pdftoppm' => env('HUB_PDFTOPPM', PHP_OS_FAMILY === 'Windows' ? 'pdftoppm.exe' : '/usr/bin/pdftoppm'),
];
