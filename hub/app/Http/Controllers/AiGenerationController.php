<?php

namespace App\Http\Controllers;

use App\Models\AiConnection;
use App\Models\AiGeneration;
use App\Models\Brand;
use App\Models\Post;
use App\Services\Ai\GenerateContent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AiGenerationController extends Controller
{
    public function index(Request $request): View
    {
        $brands = Brand::where('user_id', $request->user()->id)->orderBy('name')->get();
        $connections = AiConnection::where('user_id', $request->user()->id)->get();
        $generations = AiGeneration::where('user_id', $request->user()->id)->with('brand')->latest()->paginate(10);
        $post = $request->filled('post') ? Post::whereHas('brand', fn ($query) => $query->where('user_id', $request->user()->id))->findOrFail($request->integer('post')) : null;

        return view('ai.index', compact('brands', 'connections', 'generations', 'post') + ['requestKey' => (string) Str::uuid()]);
    }

    public function generate(Request $request, GenerateContent $service): RedirectResponse
    {
        $data = $request->validate([
            'request_key' => 'required|uuid',
            'brand_id' => ['required', Rule::exists('brands', 'id')->where('user_id', $request->user()->id)],
            'ai_connection_id' => ['nullable', Rule::exists('ai_connections', 'id')->where('user_id', $request->user()->id)],
            'task' => ['required', Rule::in(array_keys(AiGeneration::TASKS))],
            'title' => 'required|string|max:200', 'channel' => ['required', Rule::in(array_keys(Post::CHANNELS))],
            'language' => 'nullable|string|max:100', 'source_text' => 'nullable|required_if:task,rewrite,translate|string|max:8000',
            'source_url' => 'nullable|url:http,https|max:2048',
        ]);
        $brand = Brand::where('user_id', $request->user()->id)->findOrFail($data['brand_id']);
        $data['language'] = ($data['language'] ?? null) ?: $brand->language;
        $generation = $service->run($request->user(), $brand, $data);

        return redirect()->route('ai.show', $generation);
    }

    public function show(Request $request, AiGeneration $generation): View
    {
        abort_unless($generation->user_id === $request->user()->id, 404);

        return view('ai.show', compact('generation'));
    }

    public function save(Request $request, AiGeneration $generation): RedirectResponse
    {
        abort_unless($generation->user_id === $request->user()->id, 404);
        abort_unless(in_array($generation->status, ['completed', 'partial'], true), 422);
        $data = $request->validate(['title' => 'required|string|max:200', 'body' => 'required|string|max:20000']);
        $post = DB::transaction(function () use ($generation, $data) {
            $locked = AiGeneration::whereKey($generation->id)->lockForUpdate()->firstOrFail();
            if ($locked->post_id) {
                return $locked->post;
            }
            $post = $locked->brand->posts()->create([
                'title' => $data['title'], 'body' => $data['body'], 'channel' => $locked->channel, 'source_url' => $locked->source_url,
            ]);
            $locked->post_id = $post->id;
            $locked->save();

            return $post;
        }, 3);

        return redirect()->route('posts.edit',$post)->with('success','Saved as a new draft. Review it before publishing.');
    }
}
