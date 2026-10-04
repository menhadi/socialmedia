<?php

namespace App\Services\Social;

use App\Models\Post;
use App\Services\Ai\CardPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ChannelRules
{
    public static function supported(string $channel): bool
    {
        return isset(AccountSetup::PROVIDERS[$channel]);
    }

    public static function options(string $channel, array $input): array
    {
        $rules = match ($channel) {
            'youtube' => ['privacy' => 'required|in:private,unlisted,public', 'made_for_kids' => 'required|boolean'],
            'whatsapp' => ['recipient' => ['required', 'string', 'regex:/^[1-9][0-9]{7,14}$/D'], 'consent' => 'accepted',
                'mode' => 'required|in:session,template', 'last_inbound_at' => 'exclude_unless:mode,session|required|date',
                'template_name' => 'exclude_unless:mode,template|required|regex:/^[a-z0-9_]{1,512}$/D',
                'template_language' => 'exclude_unless:mode,template|required|regex:/^[a-z]{2,3}(?:_[A-Z]{2})?$/D',
                'template_values' => 'exclude_unless:mode,template|nullable|string|max:4096', 'template_confirm' => 'exclude_unless:mode,template|required|accepted'],
            default => [],
        };
        $options = Validator::make($input, $rules)->validate();
        ksort($options);

        return $options;
    }

    public static function validate(Post $post, bool $link, array $options, ?\DateTimeInterface $when = null): void
    {
        $post->assertContentPolicy();
        $fail = fn (string $message) => throw ValidationException::withMessages(['post' => $message]);
        $cards = $post->card_images ?? [];
        if (count($cards) > 1 && ! in_array($post->channel, ['facebook', 'instagram', 'linkedin', 'x'], true)) {
            $fail('This channel does not support this multi-image workflow. Choose a single card or prepare a video.');
        }
        if (count($cards) > CardPlanner::limit($post->channel)) {
            $fail('The card count exceeds this channel’s limit. Ask AI for a shorter plan.');
        }
        if ($cards && $post->video_path) {
            $fail('A card set cannot be combined with a video.');
        }
        foreach ($cards as $card) {
            if (! Storage::disk('local')->exists($card['path']) || Storage::disk('local')->size($card['path']) > 4000000 || ! hash_equals($card['hash'], hash('sha256', Storage::disk('local')->get($card['path'])))) {
                $fail('A card is missing or changed. Regenerate and review the complete set.');
            }
        }
        if (! self::supported($post->channel)) {
            $fail('Choose a supported publishing channel.');
        }
        $text = $post->body.($link && $post->source_url ? "\n\n".$post->source_url : '');
        $limit = match ($post->channel) {
            'instagram' => 2200, 'linkedin' => 3000, 'x' => 280, 'youtube' => 5000,
            'whatsapp' => ($post->image_path || $post->video_path) ? 1024 : 4096, default => 63206,
        };
        // X wraps the explicit source URL in t.co, including tracking parameters, for 23 characters.
        // Keep the remaining Unicode count conservative without guessing about arbitrary pasted links.
        if ($post->channel === 'x' && filter_var($post->source_url, FILTER_VALIDATE_URL) && in_array(parse_url($post->source_url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $text = str_replace($post->source_url, str_repeat('x', 23), $text);
        }
        $length = $post->channel === 'x' ? array_sum(array_map(fn ($c) => mb_ord($c) <= 0x10FF ? 1 : 2, mb_str_split($text))) : mb_strlen($text);
        if ($length > $limit && ! ($post->channel === 'whatsapp' && ($options['mode'] ?? '') === 'template')) {
            $fail("This channel allows {$limit} characters (X uses weighted characters). Shorten the message or remove the link.");
        }
        if ($post->image_path && $post->video_path) {
            $fail('Attach one image or one video, not both.');
        }
        if ($post->channel === 'instagram' && ! $post->image_path && ! $post->video_path) {
            $fail('Instagram requires an image or a video Reel.');
        }
        if ($post->channel === 'youtube' && (! $post->video_path || mb_strlen($post->title) > 100 || preg_match('/[<>]/', $post->title.$text))) {
            $fail('YouTube requires an MP4 video and a title of at most 100 characters. Remove angle brackets from title and description.');
        }
        if ($post->channel === 'whatsapp') {
            if (($options['mode'] ?? '') === 'session') {
                $last = CarbonImmutable::parse($options['last_inbound_at'], 'UTC')->utc();
                $delivery = $when ? CarbonImmutable::instance($when) : now();
                if ($last->isFuture() || $delivery->greaterThanOrEqualTo($last->addDay())) {
                    $fail('Free-form WhatsApp messages require a customer message in the preceding 24 hours at delivery time. Choose an approved template instead.');
                }
            } elseif ($post->image_path || $post->video_path) {
                $fail('This template workflow supports text body parameters only. Use a text draft, or send the attached media during an active customer service window.');
            }
        }
        foreach (['image_path' => 8, 'video_path' => 40] as $field => $megabytes) {
            if (! $post->$field) {
                continue;
            }
            if ($field === 'image_path' && in_array($post->channel, ['x', 'whatsapp'], true)) {
                $megabytes = 5;
            }
            if ($field === 'video_path' && $post->channel === 'whatsapp') {
                $megabytes = 16;
            }
            if (! Storage::disk('local')->exists($post->$field) || Storage::disk('local')->size($post->$field) > $megabytes * 1024 * 1024) {
                $fail("The attached file is missing or exceeds this workflow's {$megabytes} MB limit for this channel.");
            }
        }
    }
}
