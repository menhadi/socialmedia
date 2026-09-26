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
            $process = new Process([$binary, '-limit', 'memory', '64MiB', '-limit', 'map', '128MiB',
                '-background', '#102f35', '-size', '1040x', 'pango:'.$markup,
                '-resize', '1040x740>', '-gravity', 'center', '-extent', '1200x900', 'png:'.$file]);
            $process->setTimeout(20);
            $process->mustRun();
            $bytes = file_get_contents($file);
            $size = getimagesizefromstring($bytes);
            if (! $size || $size[0] !== 1200 || $size[1] !== 900 || strlen($bytes) > 4000000) {
                throw new RuntimeException('Image rendering failed.');
            }
            $hash = hash('sha256', $bytes);
            $path = 'post-images/'.$post->id.'-'.$hash.'.png';
            if (! Storage::disk('local')->put($path, $bytes)) {
                throw new RuntimeException('Could not store the post image.');
            }

            return ['image_path' => $path, 'image_hash' => $hash];
        } finally {
            @unlink($file);
        }
    }
}
