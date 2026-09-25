<?php

namespace App\Services\Ai;

use RuntimeException;

class AiFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $uncertain = false)
    {
        parent::__construct(self::explanation($reason));
    }

    public static function explanation(?string $reason): string
    {
        return match ($reason) {
            'authentication' => 'The provider rejected the API key. Check the saved key and account access.',
            'rate_limit' => 'The provider rate or credit limit was reached. Check your provider account before trying again.',
            'model' => 'The model or request was rejected. Check the model identifier and that it supports this provider’s text API.',
            'unavailable' => 'The provider is unavailable. No automatic retry was made; billing may be uncertain.',
            'timeout' => 'The connection ended without a confirmed result. The provider may have charged for this request. Check its dashboard before making a new request.',
            'empty' => 'The provider returned no usable text, or declined the request. Nothing was saved as a post.',
            'response' => 'The provider returned an unexpected response. Nothing was saved as a post.',
            default => 'The request did not finish. No automatic retry was made. Check your request history before trying again.',
        };
    }
}
