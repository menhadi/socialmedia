<?php

namespace App\Services\Ai;

use App\Services\Research\ContentVisual;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CardPlanner
{
    public function caption(string $source, array $plan): string
    {
        Validator::make($plan, ['headline_quote' => 'required|string|min:3|max:200', 'excerpt_quote' => 'required|string|min:20|max:3000', 'hashtags' => 'present|array|max:5', 'hashtags.*' => ['string', 'regex:/^#[\p{L}\p{N}_]{1,30}$/uD']])->validate();
        foreach (['headline_quote', 'excerpt_quote'] as $field) {
            if (! str_contains($source, $plan[$field])) {
                throw ValidationException::withMessages(['cards' => 'AI caption could not be matched to the supplied facts. Review required.']);
            }
        }

        return $plan['headline_quote']."\n\n".$plan['excerpt_quote'].($plan['hashtags'] ? "\n\n".implode(' ', array_unique($plan['hashtags'])) : '');
    }

    public static function limit(string $channel): int
    {
        return match ($channel) {
            'facebook', 'instagram', 'linkedin' => 10,
            'x' => 4,
            default => 1,
        };
    }

    public function select(array $sources, array $plan, string $channel, int $maximum = 10): array
    {
        $sources = app(ContentVisual::class)->validate(['type' => 'collection', 'cards' => $sources], null)['cards'];
        $limit = min(self::limit($channel), $maximum);
        Validator::make($plan, ['card_indices' => 'required|array|min:1|max:'.$limit, 'card_indices.*' => 'required|integer|min:1|max:'.count($sources).'|distinct', 'concerns' => 'present|array', 'reason' => 'required|string|max:1500'])->validate();
        if ($plan['concerns']) {
            throw ValidationException::withMessages(['cards' => 'AI flagged the source material. Review the sources before generating cards.']);
        }

        // The model chooses source IDs, never rewrites source numbers, questions or provenance.
        return ['type' => 'collection', 'cards' => array_map(fn ($index) => $sources[$index - 1], $plan['card_indices'])];
    }
}
