<?php

namespace App\Services\Social;

class AccountSetup
{
    public const PROVIDERS = [
        'facebook' => [
            'name' => 'Facebook', 'kind' => 'Page',
            'id_label' => 'Facebook Page ID', 'id_hint' => 'Numeric Page ID, not a profile URL.',
            'id_pattern' => '/^[0-9]{1,50}$/D',
            'token_label' => 'Page access token',
            'description' => 'Connect a Facebook Page. Identity verification and reviewed text/link publishing are available.',
            'help' => 'Use a Page access token from your Meta app. Publishing requires pages_manage_posts, pages_read_engagement and permission to create Page content.',
            'docs' => 'https://developers.facebook.com/docs/pages-api/getting-started/',
        ],
        'instagram' => [
            'name' => 'Instagram', 'kind' => 'Professional account',
            'id_label' => 'Instagram professional account ID', 'id_hint' => 'Numeric business or creator account ID, not your @username.',
            'id_pattern' => '/^[0-9]{1,50}$/D',
            'token_label' => 'Instagram access token',
            'description' => 'Publish an image or video Reel to a business or creator account.',
            'help' => 'Use credentials for your professional account. If you use Facebook Login, optionally save its linked Facebook Page ID below. Choose the token login method below. Instagram Login needs instagram_business_basic and instagram_business_content_publish; Facebook Login needs instagram_basic and instagram_content_publish plus Page access.',
            'docs' => 'https://developers.facebook.com/docs/instagram-platform/',
        ],
        'linkedin' => [
            'name' => 'LinkedIn', 'kind' => 'Member or organization',
            'id_label' => 'LinkedIn account URN', 'id_hint' => 'urn:li:person:YOUR_ID or urn:li:organization:123456',
            'id_pattern' => '/^urn:li:(?:person:[a-zA-Z0-9_-]{1,30}|organization:[0-9]{1,20})$/D',
            'token_label' => 'LinkedIn member access token',
            'description' => 'Publish text, an image or a video to a member profile or organization Page.',
            'help' => 'Use an OAuth access token from your LinkedIn developer app. Member publishing needs w_member_social plus openid/profile for identity checks. Organization publishing needs w_organization_social and organization administration access.',
            'docs' => 'https://learn.microsoft.com/en-us/linkedin/',
        ],
        'x' => [
            'name' => 'X', 'kind' => 'User account',
            'id_label' => 'X user ID', 'id_hint' => 'Numeric user ID, not your @handle.',
            'id_pattern' => '/^[0-9]{1,50}$/D',
            'token_label' => 'X OAuth 2.0 user access token',
            'description' => 'Publish text, an image or a video to X.',
            'help' => 'Use an OAuth 2.0 user access token from your developer app. App-only bearer tokens cannot authorize posts on your behalf. Grant users.read, tweet.read, tweet.write and media.write for media. Your X API plan must allow these endpoints.',
            'docs' => 'https://docs.x.com/fundamentals/authentication/oauth-2-0/authorization-code',
        ],
        'youtube' => [
            'name' => 'YouTube', 'kind' => 'Channel',
            'id_label' => 'YouTube channel ID', 'id_hint' => 'Channel ID beginning with UC, not a channel URL or @handle.',
            'id_pattern' => '/^UC[a-zA-Z0-9_-]{22}$/D',
            'token_label' => 'Google OAuth access token',
            'description' => 'Upload a video with a title, description, privacy and audience setting.',
            'help' => 'Use OAuth credentials associated with your channel. Grant youtube.upload and youtube.readonly. Google may restrict uploads from unaudited apps to private visibility.',
            'docs' => 'https://developers.google.com/youtube/v3/guides/authentication',
        ],
        'whatsapp' => [
            'name' => 'WhatsApp Business', 'kind' => 'Business phone number',
            'id_label' => 'WhatsApp phone number ID', 'id_hint' => 'Numeric Phone Number ID from Meta API Setup, not the phone number itself.',
            'id_pattern' => '/^[0-9]{1,50}$/D',
            'token_label' => 'WhatsApp Cloud API access token',
            'description' => 'Send a message to a consenting recipient with the WhatsApp Business Cloud API.',
            'help' => 'This setup is for WhatsApp Business Cloud API. Grant whatsapp_business_messaging and whatsapp_business_management. Free-form text or media needs a 24-hour customer service window; otherwise use an approved text template. This does not publish to WhatsApp Status or Channels. Saving a sender does not send a message.',
            'docs' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/get-started',
        ],
    ];

    public static function rules(string $provider): array
    {
        return [
            'display_name' => 'nullable|string|max:100',
            'access_token' => ['nullable', 'string', 'max:4096', 'regex:/^\\S+$/uD'],
            'youtube_client_id' => [$provider === 'youtube' ? 'nullable' : 'prohibited', 'required_with:youtube_client_secret,youtube_refresh_token', 'string', 'max:500'],
            'youtube_client_secret' => [$provider === 'youtube' ? 'nullable' : 'prohibited', 'required_with:youtube_client_id,youtube_refresh_token', 'string', 'max:4096'],
            'youtube_refresh_token' => [$provider === 'youtube' ? 'nullable' : 'prohibited', 'required_with:youtube_client_id,youtube_client_secret', 'string', 'max:4096'],
            'facebook_page_id' => [$provider === 'instagram' ? 'nullable' : 'prohibited', 'string', 'regex:/^[0-9]{1,50}$/D'],
            'login_method' => [$provider === 'instagram' ? 'nullable' : 'prohibited', 'in:facebook,instagram'],
            'business_account_id' => [$provider === 'whatsapp' ? 'nullable' : 'prohibited', 'string', 'regex:/^[0-9]{1,50}$/D'],
        ];
    }

    public static function settings(string $provider, array $data): array
    {
        return match ($provider) {
            'instagram' => array_intersect_key($data, ['facebook_page_id' => true, 'login_method' => true]),
            'whatsapp' => array_intersect_key($data, ['business_account_id' => true]),
            default => [],
        };
    }
}
