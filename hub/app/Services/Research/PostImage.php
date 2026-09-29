<?php

namespace App\Services\Research;

use App\Models\Post;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class PostImage
{
    public function create(Post $post): array
    {
        if (($post->visual['type'] ?? '') === 'collection') {
            $visual = app(ContentVisual::class)->validate($post->visual, $post->source_url);
            $images = [];
            foreach ($visual['cards'] as $i => $card) {
                $single = clone $post;
                $single->visual = $card['visual'];
                $single->source_url = $card['source_url'];
                $image = $this->create($single);
                $images[] = ['path' => $image['image_path'], 'hash' => $image['image_hash'], 'source_url' => $card['source_url']];
            }

            return ['image_path' => $images[0]['path'], 'image_hash' => $images[0]['hash'], 'card_images' => $images];
        }
        $binary = config('research.convert');
        if (! is_string($binary) || ! is_file($binary)) {
            throw new RuntimeException('Image rendering is unavailable. Configure HUB_IMAGE_CONVERT with the ImageMagick executable with Pango support.');
        }
        $escape = fn (string $text): string => str_replace('%', '&#37;', htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        $brand = $escape(mb_substr($post->brand->name, 0, 55));
        $title = $escape(mb_substr($post->title, 0, 200));
        $host = $escape(parse_url($post->source_url ?? '', PHP_URL_HOST) ?: '');
        $markup = '<span font_desc="Sans 48" foreground="#86efac">'.$brand.'</span>'
            ."\n\n".'<span font_desc="Sans Bold 84" foreground="#ffffff">'.$title.'</span>'
            ."\n\n".'<span font_desc="Sans 36" foreground="#cbd5e1">'.$host.'</span>';
        $file = tempnam(sys_get_temp_dir(), 'hub-card-');
        try {
            $command = [$binary, '-limit', 'memory', '64MiB', '-limit', 'map', '128MiB',
                '-background', '#102f35', '-size', '2080x', 'pango:'.$markup,
                '-resize', '2080x1480>', '-gravity', 'center', '-extent', '2400x1800', 'png:'.$file];
            if ($post->visual) {
                $visuals = new ContentVisual;
                $visual = $visuals->validate($post->visual, $post->source_url);
                $layout = $visuals->layout($visual, $post->brand->name, $post->source_url);
                // Rasterize geometry and glyphs at twice the design resolution, before final downsampling.
                $layout['height'] *= 2;
                foreach (['backgrounds' => 4, 'panels' => 4, 'bars' => 4, 'lines' => 4, 'missing_markers' => 2, 'points' => 2, 'layers' => 5] as $key => $coordinates) {
                    foreach ($layout[$key] as &$item) {
                        for ($i = 0; $i < $coordinates; $i++) {
                            $item[$i] *= 2;
                        }
                    }
                    unset($item);
                }
                $command = [$binary, '-limit', 'memory', '64MiB', '-limit', 'map', '128MiB', '-size', '2400x'.$layout['height'], 'xc:#f7fafc', '-stroke', 'none'];
                foreach ($layout['backgrounds'] as [$x1, $y1, $x2, $y2, $fill]) {
                    array_push($command, '-fill', $fill, '-draw', 'rectangle '.$x1.','.$y1.','.$x2.','.$y2);
                }
                foreach ($layout['panels'] as $panel) {
                    array_push($command, '-fill', '#ffffff', '-stroke', '#d7e2e9', '-strokewidth', '4', '-draw', 'roundrectangle '.implode(',', $panel).',24,24');
                }
                array_push($command, '-stroke', 'none');
                foreach ($layout['bars'] as $index => $bar) {
                    array_push($command, '-fill', $index % 2 ? '#0891b2' : '#14b8a6', '-draw', 'rectangle '.implode(',', $bar));
                }
                foreach ($layout['lines'] as $line) {
                    array_push($command, '-stroke', $line[4] ?? '#0891b2', '-strokewidth', '8', '-draw', 'line '.implode(',', array_slice($line, 0, 4)));
                }
                foreach ($layout['missing_markers'] as $marker) {
                    [$x, $y] = $marker;
                    array_push($command, '-stroke', $marker[2] ?? '#b45309', '-strokewidth', '6', '-draw', 'line '.($x - 10).','.($y - 10).','.($x + 10).','.($y + 10), '-draw', 'line '.($x - 10).','.($y + 10).','.($x + 10).','.($y - 10));
                }
                foreach ($layout['points'] as $point) {
                    [$x, $y] = $point;
                    array_push($command, '-stroke', '#ffffff', '-strokewidth', '4', '-fill', $point[2] ?? '#087f8c', '-draw', 'circle '.$x.','.$y.','.($x + 10).','.$y);
                }
                array_push($command, '-stroke', 'none');
                foreach ($layout['layers'] as $layer) {
                    [$x, $y, $width, $height, $font, $color, $text] = $layer;
                    if ($text === '') {
                        continue;
                    }
                    $weight = ($layer[7] ?? false) ? 'Bold ' : '';
                    // Pango gravity controls writing direction; west can reverse horizontal alignment.
                    array_push($command, '(', '-background', 'none', '-gravity', 'south', '-direction', 'left-to-right', '-define', 'pango:align=left', '-size', $width.'x', 'pango:<span font_desc="Sans '.$weight.$font.'" foreground="'.$color.'">'.$escape($text).'</span>', '-resize', $width.'x'.$height.'>', '+repage', ')', '-gravity', 'northwest', '-geometry', '+'.$x.'+'.$y, '-composite');
                }
                array_push($command, 'png:'.$file);
            }
            // Contain the entire artwork inside a padded square; never crop source content.
            array_pop($command);
            array_push($command, '-filter', 'Lanczos', '-resize', '1884x1884>', '-background', '#f7fafc', '-gravity', 'center', '-extent', '2048x2048', '-colorspace', 'sRGB', '-depth', '8', 'png:'.$file);
            $process = new Process($command);
            $process->setTimeout(40);
            $process->mustRun();
            $bytes = file_get_contents($file);
            $size = getimagesizefromstring($bytes);
            if (! $size || $size[0] !== 2048 || $size[1] !== 2048 || strlen($bytes) > 4000000) {
                throw new RuntimeException('Image rendering failed.');
            }
            $hash = hash('sha256', $bytes);
            $path = 'post-images/'.$post->id.'-'.$hash.'.png';
            if (! Storage::disk('local')->put($path, $bytes)) {
                throw new RuntimeException('Could not store the post image.');
            }

            return ['image_path' => $path, 'image_hash' => $hash, 'card_images' => null];
        } finally {
            @unlink($file);
        }
    }
}
