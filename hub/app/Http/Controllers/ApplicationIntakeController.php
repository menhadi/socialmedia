<?php

namespace App\Http\Controllers;

use App\Models\ApplicationEvent;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Publication;
use App\Services\Research\ContentVisual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ApplicationIntakeController extends Controller
{
    public function token(Request $r, Brand $brand): RedirectResponse
    {
        abort_unless($brand->user_id === $r->user()->id, 404);
        $token = Str::random(64);
        $brand->forceFill(['intake_token_hash' => hash('sha256', $token)])->save();

        return back()->with('intake_token', $token)->with('success', 'New application token created. Previous token is revoked. Save it in your application server settings.');
    }

    private function authenticate(Request $r): Brand
    {
        $token = $r->bearerToken();
        abort_unless(is_string($token) && strlen($token) === 64, 401);

        return Brand::where('intake_token_hash', hash('sha256', $token))->first() ?? abort(401);
    }

    public function store(Request $r): JsonResponse
    {
        $brand = $this->authenticate($r);
        $item = $this->saveItem($r, $brand, false);

        return response()->json(['id' => $item->id, 'status' => $item->status, 'reason' => $item->reason], $item->wasRecentlyCreated ? 201 : 200);
    }

    public function manual(Request $r): RedirectResponse
    {
        $brand = Brand::where('user_id', $r->user()->id)->findOrFail($r->integer('brand_id'));
        $this->saveItem($r, $brand, $r->boolean('approved'));

        return back()->with('success', 'Content saved. Enabled automation rules process new approved items on the next scheduler run.');
    }

    private function saveItem(Request $r, Brand $brand, bool $approved): ContentItem
    {
        $data = $r->validate(['external_id' => 'required|string|max:150', 'category' => 'required|in:general,question,announcement,result', 'channel' => ['required', Rule::in(['facebook', 'instagram', 'linkedin', 'x', 'youtube', 'whatsapp'])], 'title' => 'required|string|max:200', 'body' => 'required|string|min:20|max:12000', 'source_url' => 'nullable|url:http,https|max:2048']);
        $visual = app(ContentVisual::class)->validate($r->input('visual'), $data['source_url'] ?? null);
        if ($visual) {
            $data['visual'] = $visual;
        }
        if ($r->has('source_cards')) {
            $data['visual'] = app(ContentVisual::class)->validate(['type' => 'collection', 'cards' => $r->input('source_cards')], $data['source_url'] ?? null);
        }
        $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($brand, $data, $fingerprint, $approved): ContentItem {
            Brand::whereKey($brand->id)->lockForUpdate()->firstOrFail();
            $existing = ContentItem::where('brand_id', $brand->id)->where('external_id', $data['external_id'])->where('channel', $data['channel'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409, 'This external ID already contains different content. Use a new revision ID.');

                return $existing;
            }

            return ContentItem::create($data + ['brand_id' => $brand->id, 'fingerprint' => $fingerprint, 'approved' => $approved]);
        });
    }

    public function event(Request $r): JsonResponse
    {
        $brand = $this->authenticate($r);
        $data = $r->validate(['external_id' => 'required|string|max:150', 'publication_id' => 'nullable|integer', 'name' => 'required|in:visit,registration,conversion']);
        if (! empty($data['publication_id'])) {
            abort_unless(Publication::whereKey($data['publication_id'])->where('status', 'published')->whereHas('post', fn ($q) => $q->where('brand_id', $brand->id))->exists(), 422, 'Publication does not belong to this application.');
        }
        $event = DB::transaction(function () use ($brand, $data): ApplicationEvent {
            Brand::whereKey($brand->id)->lockForUpdate()->firstOrFail();
            $event = ApplicationEvent::firstOrCreate(['brand_id' => $brand->id, 'external_id' => $data['external_id']], $data);
            abort_unless($event->name === $data['name'] && $event->publication_id === ($data['publication_id'] ?? null), 409, 'Event ID already used.');

            return $event;
        });

        return response()->json(['id' => $event->id], $event->wasRecentlyCreated ? 201 : 200);
    }
}
