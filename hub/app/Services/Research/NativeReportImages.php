<?php

namespace App\Services\Research;

use App\Models\Post;
use App\Services\Ai\CardPlanner;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class NativeReportImages
{
    private function instagramPage(string $bytes): string
    {
        $page = imagecreatefromstring($bytes);
        if (! $page) {
            throw new RuntimeException('The original report page is not a valid image.');
        }
        $side = min(1440, max(imagesx($page), imagesy($page)));
        $scale = min($side / imagesx($page), $side / imagesy($page));
        $width = (int) round(imagesx($page) * $scale);
        $height = (int) round(imagesy($page) * $scale);
        $canvas = imagecreatetruecolor($side, $side);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $page, (int) (($side - $width) / 2), (int) (($side - $height) / 2), 0, 0, $width, $height, imagesx($page), imagesy($page));
        ob_start();
        imagepng($canvas);
        $result = ob_get_clean();
        imagedestroy($page);
        imagedestroy($canvas);

        return $result;
    }

    public function document(string $html, array $styles = []): string
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        $xpath = new DOMXPath($document);
        if (! $xpath->query('//main//svg|//main//table')->length) {
            throw new RuntimeException('The original report contains no recorded graph or table.');
        }
        foreach (iterator_to_array($xpath->query('//script|//link|//iframe|//object|//embed|//img|//image|//form|//base|//foreignObject|//foreignobject|//video|//audio|//source|//meta[translate(@http-equiv,"REFSH","refsh")="refresh"]|//div[contains(@class,"report-actions")]')) as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
        foreach ($xpath->query('//*') as $node) {
            foreach (iterator_to_array($node->attributes) as $attribute) {
                if (str_starts_with(strtolower($attribute->name), 'on') || in_array(strtolower($attribute->name), ['src', 'srcset', 'href', 'xlink:href', 'action'], true)) {
                    $node->removeAttributeNode($attribute);
                }
            }
        }
        $head = $xpath->query('//head')->item(0);
        if (! $head) {
            $head = $document->createElement('head');
            $document->documentElement->insertBefore($head, $document->documentElement->firstChild);
        }
        $policy = $document->createElement('meta');
        $policy->setAttribute('http-equiv', 'Content-Security-Policy');
        $policy->setAttribute('content', "default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; font-src 'none'; base-uri 'none'; form-action 'none'");
        $head->insertBefore($policy, $head->firstChild);
        foreach ($styles as $css) {
            $style = $document->createElement('style');
            $style->appendChild($document->createTextNode($css));
            $head->appendChild($style);
        }

        return $document->saveHTML();
    }

    public function create(Post $post): array
    {
        $chrome = config('research.report_chrome');
        $rasterizer = config('research.pdftoppm');
        if (! is_string($chrome) || ! is_file($chrome) || ! is_string($rasterizer) || ! is_file($rasterizer)) {
            throw new RuntimeException('Original-report images require the configured Chrome and PDF rasterizer.');
        }
        $visual = app(ContentVisual::class)->validate($post->visual, $post->source_url);
        $html = app(FetchSource::class)->publicHtml($visual['report_url']);
        $document = new DOMDocument;
        @$document->loadHTML($html, LIBXML_NONET);
        $styles = [];
        $origin = 'https://'.parse_url($visual['report_url'], PHP_URL_HOST);
        foreach ((new DOMXPath($document))->query('//link[@rel="stylesheet"]') as $link) {
            $url = $link->getAttribute('href');
            if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
                $url = $origin.$url;
            }
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== parse_url($origin, PHP_URL_HOST)) {
                continue;
            }
            if (count($styles) >= 3) {
                throw new RuntimeException('The printable report needs more than three stylesheets. Choose its dedicated report page.');
            }
            $styles[] = app(FetchSource::class)->publicCss($url);
        }
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hub-report-'.Str::uuid();
        File::makeDirectory($directory, 0700);
        try {
            File::put($directory.'/report.html', $this->document($html, $styles));
            $url = 'file:///'.ltrim(str_replace('\\', '/', $directory.'/report.html'), '/');
            (new Process([$chrome, '--headless', '--disable-gpu', '--disable-background-networking', '--disable-dev-shm-usage',
                '--user-data-dir='.$directory.'/profile', '--host-resolver-rules=MAP * ~NOTFOUND', '--no-pdf-header-footer',
                '--print-to-pdf='.$directory.'/report.pdf', $url]))->setTimeout(45)->mustRun();
            (new Process([$rasterizer, '-png', '-scale-to', '1600', '-f', '1', '-l', (string) CardPlanner::limit($post->channel),
                $directory.'/report.pdf', $directory.'/page']))->setTimeout(45)->mustRun();
            $files = glob($directory.'/page-*.png');
            natsort($files);
            $cards = [];
            foreach ($files as $file) {
                $bytes = File::get($file);
                if ($post->channel === 'instagram') {
                    $bytes = $this->instagramPage($bytes);
                }
                if (strlen($bytes) > 4 * 1024 * 1024 || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
                    throw new RuntimeException('An original report page exceeds the publishing image limit.');
                }
                $hash = hash('sha256', $bytes);
                $path = 'post-images/'.$post->brand_id.'/'.$post->id.'/'.$hash.'.png';
                if (! Storage::disk('local')->put($path, $bytes)) {
                    throw new RuntimeException('The original report page could not be stored.');
                }
                $cards[] = ['path' => $path, 'hash' => $hash, 'source_url' => $visual['report_url']];
            }
            if (! $cards) {
                throw new RuntimeException('No original report pages could be rendered.');
            }

            return ['image_path' => $cards[0]['path'], 'image_hash' => $cards[0]['hash'], 'card_images' => $cards];
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
