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
        $markup = '<span font_desc="Sans 24" foreground="#86efac">'.$brand.'</span>'
            ."\n\n".'<span font_desc="Sans Bold 42" foreground="#ffffff">'.$title.'</span>'
            ."\n\n".'<span font_desc="Sans 18" foreground="#cbd5e1">'.$host.'</span>';
        $file = tempnam(sys_get_temp_dir(), 'hub-card-');
        try {
            $command = [$binary, '-limit', 'memory', '64MiB', '-limit', 'map', '128MiB',
                '-background', '#102f35', '-size', '1040x', 'pango:'.$markup,
                '-resize', '1040x740>', '-gravity', 'center', '-extent', '1200x900', 'png:'.$file];
            if ($post->visual) {
                $visuals = new ContentVisual;
                $visual = $visuals->validate($post->visual, $post->source_url);
                $layout = $visuals->layout($visual, $post->brand->name, $post->source_url);
                $command = [$binary, '-limit', 'memory', '64MiB', '-limit', 'map', '128MiB', '-size', '1200x'.$layout['height'], 'xc:#f7fafc', '-stroke', 'none'];
                foreach ($layout['backgrounds'] as [$x1, $y1, $x2, $y2, $fill]) {
                    array_push($command, '-fill', $fill, '-draw', 'rectangle '.$x1.','.$y1.','.$x2.','.$y2);
                }
                foreach ($layout['panels'] as $panel) {
                    array_push($command, '-fill', '#ffffff', '-stroke', '#d7e2e9', '-strokewidth', '2', '-draw', 'roundrectangle '.implode(',', $panel).',12,12');
                }
                array_push($command, '-stroke', 'none');
                foreach ($layout['bars'] as $index => $bar) {
                    array_push($command, '-fill', $index % 2 ? '#0891b2' : '#14b8a6', '-draw', 'rectangle '.implode(',', $bar));
                }
                foreach ($layout['lines'] as $line) {
                    array_push($command, '-stroke', $line[4] ?? '#0891b2', '-strokewidth', '4', '-draw', 'line '.implode(',', array_slice($line, 0, 4)));
                }
                foreach ($layout['missing_markers'] as $marker) {
                    [$x, $y] = $marker;
                    array_push($command, '-stroke', $marker[2] ?? '#b45309', '-strokewidth', '3', '-draw', 'line '.($x - 5).','.($y - 5).','.($x + 5).','.($y + 5), '-draw', 'line '.($x - 5).','.($y + 5).','.($x + 5).','.($y - 5));
                }
                foreach ($layout['points'] as $point) {
                    [$x, $y] = $point;
                    array_push($command, '-stroke', '#ffffff', '-strokewidth', '2', '-fill', $point[2] ?? '#087f8c', '-draw', 'circle '.$x.','.$y.','.($x + 5).','.$y);
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
            array_push($command, '-resize', '1104x1104>', '-background', '#f7fafc', '-gravity', 'center', '-extent', '1200x1200', 'png:'.$file);
            $process = new Process($command);
            $process->setTimeout(20);
            $process->mustRun();
            $bytes = file_get_contents($file);
            $size = getimagesizefromstring($bytes);
            if (! $size || $size[0] !== 1200 || $size[1] !== 1200 || strlen($bytes) > 4000000) {
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
