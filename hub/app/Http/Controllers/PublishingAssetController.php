<?php

namespace App\Http\Controllers;

use App\Models\Publication;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublishingAssetController extends Controller
{
    public function __invoke(Publication $publication): BinaryFileResponse
    {
        abort_unless($publication->provider === 'instagram' && $publication->asset_path
            && $publication->created_at->gt(now()->subHours(2))
            && in_array($publication->status, ['publishing', 'published'], true), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($publication->asset_path), 404);

        return response()->file($disk->path($publication->asset_path), [
            'Content-Type' => $publication->video_path ? 'video/mp4' : 'image/jpeg',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
