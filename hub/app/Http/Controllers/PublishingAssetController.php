<?php

namespace App\Http\Controllers;

use App\Models\Publication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublishingAssetController extends Controller
{
    public function __invoke(Request $request, Publication $publication): BinaryFileResponse
    {
        $path = $publication->asset_path;
        if ($request->has('card')) {
            $index = $request->query('card');
            abort_unless(ctype_digit((string) $index), 404);
            $path = $publication->card_images[(int) $index]['asset_path'] ?? null;
        }
        abort_unless($publication->provider === 'instagram' && $path
            && $publication->created_at->gt(now()->subHours(2))
            && in_array($publication->status, ['publishing', 'published'], true), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $publication->video_path ? 'video/mp4' : 'image/jpeg',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
