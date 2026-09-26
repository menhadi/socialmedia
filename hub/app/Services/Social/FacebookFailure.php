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
            'platform_token' => 'The access token expired or was rejected. Save a new token and verify the account.',
            'platform_permission' => 'The platform denied access. Check your app permissions, subscription and account role.',
            'platform_invalid' => 'The platform rejected the details. Check the text, media, destination and channel options.',
            'platform_identity' => 'The token did not identify the saved account. Check the account ID and token.',
            'platform_rate' => 'The platform rate limit or quota was reached. Check usage before a new submission.',
            'platform_media' => 'The platform could not process this media, or processing timed out. Check its format and size.',
            'platform_aspect' => 'Instagram images need an aspect ratio between 4:5 and 1.91:1. Generate a square or landscape image.',
            'platform_public_url' => 'Instagram needs a publicly reachable HTTPS application URL to retrieve the attached media.',
            'platform_upload_url' => 'The platform returned an unsupported upload destination. No file was sent to it.',
            'platform_changed' => 'Account credentials changed during media processing. Verify the account and open a new preview.',
            'platform_approval' => 'Official source approval changed while media was processing. Review the source before publishing again.',
            'platform_configuration' => 'The platform connection settings are not configured correctly.',
            'platform_response' => 'No confirmed result was received. Check the destination before retrying. Publishing requests are never repeated automatically.',
            'token' => 'Facebook rejected the Page token. Save a valid Page access token and verify it again.',
            'permission' => 'Facebook rejected the request because of missing Page permissions. Check pages_manage_posts, pages_read_engagement and your Page access.',
            'invalid' => 'Facebook rejected these post details. Check the message, link and Page settings before opening a new publishing preview.',
            'identity' => 'The token did not identify the specified Facebook Page. Use that Page’s access token.',
            'configuration' => 'The Facebook API version is not configured correctly. Contact the workspace administrator.',
            default => 'Facebook did not return a confirmed result. Check the Page directly. This submission will not be retried automatically.',
        };
    }
}
