<?php

namespace App\Services\Research;

use App\Services\Monitoring\PublicEndpoint;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;

class FetchSource
{
    public function __construct(private PublicEndpoint $endpoint) {}

    public function validate(string $url): string
    {
        if (strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('Use a public HTTP or HTTPS URL without a fragment.');
        }

        return $this->endpoint->host(explode('?', $url, 2)[0]);
    }

    public function fetch(string $url, ?string $elementId = null): string
    {
        $this->validate($url);
        $options = $this->endpoint->options(explode('?', $url, 2)[0]);
        $limit = 4 * 1024 * 1024;
        $options['decode_content'] = false;
        $options['on_headers'] = function ($response) use ($limit): void {
            if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                throw new RuntimeException('The source is larger than 4 MB.');
            }
        };
        $options['progress'] = function ($total, $downloaded) use ($limit): void {
            if ($downloaded > $limit) {
                throw new RuntimeException('The source is larger than 4 MB.');
            }
        };
        $response = Http::withOptions($options)->setHandler(new CurlHandler)
            ->withHeaders(['Accept-Encoding' => 'identity'])->withUserAgent('ContentHub/1.0 source reader')
            ->connectTimeout(5)->timeout(20)->withoutRedirecting()->get($url);
        if (! $response->successful() || $response->header('Content-Encoding')) {
            throw new RuntimeException('Source unavailable. Use its final public URL; redirects and compressed responses are not followed.');
        }
        $body = $response->body();
        if (strlen($body) > $limit) {
            throw new RuntimeException('The source is larger than 4 MB.');
        }
        $type = strtolower(explode(';', $response->header('Content-Type'))[0]);
        if ($type === 'application/pdf' || str_starts_with($body, '%PDF-')) {
            $text = $this->pdf($body);
        } elseif (in_array($type, ['text/html', 'application/xhtml+xml', 'text/xml', 'application/xml', 'application/rss+xml', 'application/atom+xml', 'text/plain'], true)) {
            $text = $type === 'text/plain' ? $body : $this->html($body, $elementId);
        } else {
            throw new RuntimeException('Use an HTML page, RSS/Atom feed, plain text or text-based PDF.');
        }
        $text = self::normalize($text);
        if (mb_strlen($text) < 40) {
            throw new RuntimeException('Not enough readable text. Scanned PDFs and JavaScript-only pages need another source URL.');
        }
        if (strlen($text) > 200000) {
            throw new RuntimeException('This source is too broad. Choose a specific notice or a content element ID.');
        }

        return $text;
    }

    public static function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_convert_encoding($text, 'UTF-8', 'UTF-8')) ?? '');
    }

    private function html(string $body, ?string $elementId): string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            $doc->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($doc);
            foreach ($xpath->query('//script|//style|//nav|//footer|//header|//noscript|//form') as $node) {
                $node->parentNode?->removeChild($node);
            }
            if ($elementId) {
                $node = $doc->getElementById($elementId);
                if (! $node) {
                    throw new RuntimeException('The selected content element ID was not found.');
                }
            } else {
                $node = $xpath->query('//main|//article')->item(0) ?? $doc->documentElement;
            }
            foreach ($xpath->query('.//p|.//div|.//li|.//tr|.//h1|.//h2|.//h3|.//br|.//item|.//entry', $node) as $block) {
                $block->appendChild($doc->createTextNode(' '));
            }

            return $node->textContent;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function pdf(string $body): string
    {
        $file = tempnam(sys_get_temp_dir(), 'hub-pdf-');
        try {
            file_put_contents($file, $body);
            $process = new Process([config('research.pdftotext'), '-enc', 'UTF-8', '-nopgbrk', $file, '-']);
            $process->setTimeout(15);
            $outputBytes = 0;
            $process->run(function (string $type, string $buffer) use (&$outputBytes): void {
                $outputBytes += strlen($buffer);
                if ($outputBytes > 200000) {
                    throw new RuntimeException('The PDF contains too much text. Use a shorter notice.');
                }
            });
            if (! $process->isSuccessful() || strlen($process->getOutput()) > 200000) {
                throw new RuntimeException('PDF extraction failed or the document is too long. Use a shorter, text-based notice.');
            }

            return $process->getOutput();
        } finally {
            @unlink($file);
        }
    }
}
