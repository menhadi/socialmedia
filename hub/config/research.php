<?php

return [
    'pdftotext' => env('HUB_PDFTOTEXT', PHP_OS_FAMILY === 'Windows' ? 'pdftotext.exe' : '/usr/bin/pdftotext'),
    'convert' => env('HUB_IMAGE_CONVERT', PHP_OS_FAMILY === 'Windows' ? null : '/usr/bin/convert'),
    'report_chrome' => env('HUB_REPORT_CHROME', PHP_OS_FAMILY === 'Windows' ? null : '/usr/bin/google-chrome'),
    'pdftoppm' => env('HUB_PDFTOPPM', PHP_OS_FAMILY === 'Windows' ? 'pdftoppm.exe' : '/usr/bin/pdftoppm'),
];
