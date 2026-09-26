<?php

namespace App\Services\Media;

use App\Models\MediaGeneration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GeminiMediaClient
{
    private function request(MediaGeneration $job): PendingRequest
    {
        return Http::withHeaders(['x-goog-api-key' => $job->api_key])->acceptJson()
            ->connectTimeout(8)->timeout(120)->withoutRedirecting()->withOptions([
                'on_headers' => function ($response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > 24000000) {
                        throw new RuntimeException('response');
                    }
                },
                'progress' => function ($total, $downloaded): void {
                    if ($downloaded > 24000000) {
                        throw new RuntimeException('response');
                    }
                },
            ]);
    }

    private function check(Response $response): void
    {
        if (! $response->successful()) {
            throw new RuntimeException(match ($response->status()) {
                401, 403 => 'credentials', 402 => 'billing', 429 => 'rate_limit',
                400, 404, 422 => 'configuration', default => 'uncertain',
            });
        }
    }

    public function image(MediaGeneration $job): string
    {
        $response = $this->request($job)->post('https://generativelanguage.googleapis.com/v1beta/interactions', [
            'model' => $job->model, 'input' => $job->prompt, 'store' => false,
            'response_format' => ['type' => 'image', 'mime_type' => 'image/jpeg', 'image_size' => '1K', 'aspect_ratio' => $job->aspect_ratio],
        ]);
        $this->check($response);
        foreach ($response->json('steps', []) as $step) {
            if (($step['type'] ?? '') !== 'model_output') {
                continue;
            }
            foreach ($step['content'] ?? [] as $content) {
                if (($content['type'] ?? '') === 'image' && is_string($content['data'] ?? null)) {
                    $bytes = base64_decode($content['data'], true);
                    $size = $bytes === false ? false : @getimagesizefromstring($bytes);
                    if (! $size || ! in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
                        || $size[0] > 4096 || $size[1] > 4096 || strlen($bytes) > 16000000) {
                        throw new RuntimeException('invalid_media');
                    }
                    $image = @imagecreatefromstring($bytes);
                    if (! $image) {
                        throw new RuntimeException('invalid_media');
                    }
                    ob_start();
                    try {
                        imagepng($image);

                        return (string) ob_get_contents();
                    } finally {
                        ob_end_clean();
                        imagedestroy($image);
                    }
                }
            }
        }
        throw new RuntimeException('empty');
    }

    public function startVideo(MediaGeneration $job): string
    {
        $instance = ['prompt' => $job->prompt];
        if ($job->input_image) {
            $instance['image'] = ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode(Storage::disk('local')->get($job->input_image))]];
        }
        $response = $this->request($job)->post('https://generativelanguage.googleapis.com/v1beta/models/'.$job->model.':predictLongRunning', [
            'instances' => [$instance], 'parameters' => ['aspectRatio' => $job->aspect_ratio, 'durationSeconds' => 8, 'resolution' => '720p', 'numberOfVideos' => 1],
        ]);
        $this->check($response);
        $name = $response->json('name');
        if (! is_string($name) || ! preg_match('~^models/[a-zA-Z0-9._-]+/operations/[a-zA-Z0-9._-]+$~D', $name)) {
            throw new RuntimeException('uncertain');
        }

        return $name;
    }

    public function poll(MediaGeneration $job): ?string
    {
        if (! preg_match('~^models/[a-zA-Z0-9._-]+/operations/[a-zA-Z0-9._-]+$~D', $job->operation ?? '')) {
            throw new RuntimeException('configuration');
        }
        $response = $this->request($job)->get('https://generativelanguage.googleapis.com/v1beta/'.$job->operation);
        $this->check($response);
        if (! $response->json('done')) {
            return null;
        }
        if ($response->json('error')) {
            throw new RuntimeException('provider_failed');
        }
        $uri = $response->json('response.generateVideoResponse.generatedSamples.0.video.uri');
        if (! is_string($uri)) {
            throw new RuntimeException('empty');
        }

        return $this->download($job, $uri);
    }

    private function download(MediaGeneration $job, string $uri): string
    {
        for ($redirects = 0; $redirects < 4; $redirects++) {
            $parts = parse_url($uri);
            $host = $parts['host'] ?? '';
            if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['port']) || isset($parts['fragment']) || ! in_array($host, ['generativelanguage.googleapis.com', 'storage.googleapis.com', 'video-generativeai.googleusercontent.com'], true)) {
                throw new RuntimeException('download');
            }
            $request = Http::connectTimeout(8)->timeout(60)->withoutRedirecting()->withOptions([
                'progress' => function ($total, $downloaded): void {
                    if ($downloaded > 40000000) {
                        throw new RuntimeException('download');
                    }
                },
                'on_headers' => function ($response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > 40000000) {
                        throw new RuntimeException('download');
                    }
                },
            ]);
            if ($host === 'generativelanguage.googleapis.com') {
                $request->withHeaders(['x-goog-api-key' => $job->api_key]);
            }
            $response = $request->get($uri);
            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $uri = $response->header('Location');

                continue;
            }
            $this->check($response);
            $bytes = $response->body();
            if (strlen($bytes) < 16 || strlen($bytes) > 40000000 || substr($bytes, 4, 4) !== 'ftyp') {
                throw new RuntimeException('invalid_media');
            }

            return $bytes;
        }
        throw new RuntimeException('download');
    }
}
