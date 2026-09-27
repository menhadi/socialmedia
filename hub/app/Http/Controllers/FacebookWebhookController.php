<?php

namespace App\Http\Controllers;

use App\Models\Publication;
use App\Services\Social\DeletePublication;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FacebookWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = config('services.facebook.webhook_verify_token');
        $given = $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        abort_unless(is_string($token) && strlen($token) >= 32 && is_string($given) && hash_equals($token, $given) && $request->query('hub_mode', $request->query('hub.mode')) === 'subscribe', 403);
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge', ''));
        abort_unless(is_string($challenge) && preg_match('/^[0-9]{1,100}$/D', $challenge), 400);

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, DeletePublication $service): Response
    {
        abort_if(strlen($request->getContent()) > 262144, 413);
        $secret = config('services.facebook.app_secret');
        $signature = $request->header('X-Hub-Signature-256', '');
        abort_unless(is_string($secret) && strlen($secret) >= 16 && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature), 403);
        $payload = json_decode($request->getContent(), true, 32);
        abort_unless(is_array($payload) && ($payload['object'] ?? '') === 'page' && is_array($payload['entry'] ?? null), 400);
        foreach (array_slice($payload['entry'], 0, 100) as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null) || ! preg_match('/^[0-9]+$/D', $entry['id']) || ! is_numeric($entry['time'] ?? null) || $entry['time'] < 1 || $entry['time'] > time() + 300 || ! is_array($entry['changes'] ?? null)) {
                continue;
            }
            foreach (array_slice($entry['changes'], 0, 100) as $change) {
                if (! is_array($change)) {
                    continue;
                }
                $value = $change['value'] ?? [];
                if (($change['field'] ?? '') !== 'feed' || ! is_array($value) || ($value['verb'] ?? '') !== 'remove' || ($value['item'] ?? '') !== 'post' || ! is_string($value['post_id'] ?? null)) {
                    continue;
                }
                foreach (Publication::where('provider', 'facebook')->where('page_id', $entry['id'])->where('remote_post_id', $value['post_id'])->where('status', 'published')->whereNull('remote_deleted_at')->where('published_at', '<=', Carbon::createFromTimestampUTC((int) $entry['time']))->get() as $publication) {
                    $service->confirm($publication, 'facebook_webhook');
                }
            }
        }

        return response('EVENT_RECEIVED');
    }
}
