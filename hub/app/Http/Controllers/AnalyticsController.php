<?php

namespace App\Http\Controllers;

use App\Models\ApplicationEvent;
use App\Models\Brand;
use App\Models\Publication;
use App\Services\Analytics\CollectAnalytics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function index(Request $r): View
    {
        $brands = Brand::where('user_id', $r->user()->id)->get();
        $query = Publication::whereHas('post', fn ($q) => $q->whereIn('brand_id', $brands->pluck('id')))->where('status', 'published');
        if ($r->filled('brand')) {
            $query->whereHas('post', fn ($q) => $q->where('brand_id', $r->integer('brand')));
        }
        if ($r->filled('channel')) {
            $query->where('provider', $r->string('channel')->toString());
        }
        if ($r->filled('post')) {
            $query->where('post_id', $r->integer('post'));
        }
        $publications = $query->with(['post.brand', 'latestAnalytics'])->latest('published_at')->paginate(20)->withQueryString();
        $events = ApplicationEvent::whereIn('brand_id', $brands->pluck('id'))->where('created_at', '>=', now()->subDays(30));
        if ($r->filled('brand')) {
            $events->where('brand_id', $r->integer('brand'));
        }
        if ($r->filled('channel') || $r->filled('post')) {
            $events->whereIn('publication_id', (clone $query)->select('publications.id'));
        }
        $totals = $events->selectRaw('name, COUNT(*) as total')->groupBy('name')->pluck('total', 'name');

        return view('analytics', compact('brands', 'publications', 'totals'));
    }

    public function refresh(Request $r, Publication $publication, CollectAnalytics $collector): RedirectResponse
    {
        abort_unless($publication->post->brand->user_id === $r->user()->id, 404);
        $collector->run($publication);

        return back()->with('success','Analytics checked (minimum refresh interval: 30 minutes).');
    }
}
