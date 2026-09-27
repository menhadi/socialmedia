<?php

namespace App\Http\Controllers;

use App\Models\Publication;
use App\Services\Social\DeletePublication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PublicationDeletionController extends Controller
{
    public function preview(Request $request, Publication $publication): View
    {
        abort_unless($publication->post->brand->user_id === $request->user()->id, 404);
        abort_unless($publication->status === 'published', 422);

        return view('delete-publication', ['publication' => $publication, 'attempts' => $publication->deletions()->latest()->get(), 'fingerprint' => DeletePublication::fingerprint($publication), 'requestKey' => (string) Str::uuid()]);
    }

    public function destroy(Request $request, Publication $publication, DeletePublication $service): RedirectResponse
    {
        abort_unless($publication->post->brand->user_id === $request->user()->id, 404);
        $data = $request->validate(['request_key' => 'required|uuid', 'fingerprint' => 'required|string|size:64', 'confirm' => 'accepted', 'confirmation_text' => 'required|in:DELETE']);
        $attempt = $service->run($request->user(), $publication, $data);

        return redirect()->route('publications.delete.preview', $publication)->with('success', match ($attempt->status) {
            'succeeded' => 'Platform deletion confirmed. The Hub record is archived with history retained.','rejected' => 'The platform rejected the deletion. See the details below.',default => 'Deletion is pending or uncertain. Check the platform; no automatic retry will be made.'
        });
    }

    public function external(Request $request, Publication $publication, DeletePublication $service): RedirectResponse
    {
        abort_unless($publication->post->brand->user_id === $request->user()->id, 404);
        $request->validate(['confirm_external' => 'accepted', 'remote_id' => ['required', 'string', Rule::in([$publication->remote_post_id])]]);
        $service->recordExternal($request->user(), $publication);

        return back()->with('success', 'Recorded your confirmation of platform removal. The Hub record is archived; no remote deletion request was sent.');
    }
}
