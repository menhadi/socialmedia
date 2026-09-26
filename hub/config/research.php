<?php

return [
    'pdftotext' => env('HUB_PDFTOTEXT', PHP_OS_FAMILY === 'Windows' ? 'pdftotext.exe' : '/usr/bin/pdftotext'),
    'convert' => env('HUB_IMAGE_CONVERT', PHP_OS_FAMILY === 'Windows' ? null : '/usr/bin/convert'),
];
