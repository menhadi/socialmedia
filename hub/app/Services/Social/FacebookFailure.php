<?php

namespace App\Services\Social;

use RuntimeException;

class FacebookFailure extends RuntimeException
{
    public function __construct(public string $reason, public bool $uncertain = false)
    {
        parent::__construct(self::description($reason));
    }

    public static function description(?string $reason): string
    {
        return match ($reason) {
            'token' => 'Facebook rejected the Page token. Save a valid Page access token and verify it again.',
            'permission' => 'Facebook rejected the request because of missing Page permissions. Check pages_manage_posts, pages_read_engagement and your Page access.',
            'invalid' => 'Facebook rejected these post details. Check the message, link and Page settings before opening a new publishing preview.',
            'identity' => 'The token did not identify the specified Facebook Page. Use that Page’s access token.',
            'configuration' => 'The Facebook API version is not configured correctly. Contact the workspace administrator.',
            default => 'Facebook did not return a confirmed result. Check the Page directly. This submission will not be retried automatically.',
        };
    }
}
