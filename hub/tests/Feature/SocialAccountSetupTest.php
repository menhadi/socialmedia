<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Post;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SocialAccountSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function brand(): Brand
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return Brand::factory()->create(['user_id' => $user->id]);
    }

    public static function platforms(): array
    {
        return [
            'instagram' => ['instagram', '17841401234567890', ['facebook_page_id' => '123456']],
            'linkedin member' => ['linkedin', 'urn:li:person:Member_12', []],
            'linkedin organization' => ['linkedin', 'urn:li:organization:123456', []],
            'x' => ['x', '123456789', []],
            'youtube' => ['youtube', 'UCabcdefghijklmnopqrstuv', []],
            'whatsapp' => ['whatsapp', '1234567890123', ['business_account_id' => '9988776655']],
        ];
    }

    #[DataProvider('platforms')]
    public function test_platform_setup_can_be_saved_without_credentials_and_remains_untested(string $provider, string $id, array $settings): void
    {
        $brand = $this->brand();
        $this->get('/social-accounts?provider='.$provider)->assertOk()->assertSee('Verify when you are ready');
        $payload = ['provider' => $provider, 'brand_id' => $brand->id, 'page_id' => $id, 'display_name' => 'My saved account'] + $settings;
        $this->post('/social-accounts', $payload)->assertSessionHasNoErrors()->assertRedirect(route('social', ['provider' => $provider, 'brand' => $brand->id]));
        $account = SocialAccount::firstOrFail();
        $this->assertSame($provider, $account->provider);
        $this->assertSame($brand->id, $account->brand_id);
        $this->assertSame($id, $account->page_id);
        $this->assertSame('My saved account', $account->display_name);
        $this->assertSame($settings, $account->settings);
        $this->assertNull($account->access_token);
        $this->assertNull($account->verified_at);
        $this->get('/social-accounts')->assertSee('My saved account')->assertSee('Not tested')->assertSee('Awaiting credentials')->assertSee('Publishing available')->assertDontSee(route('social.verify', $account), false);
        $this->post('/social-accounts', $payload)->assertSessionHasErrors('page_id');
        $this->assertDatabaseCount('social_accounts', 1);
        Http::assertNothingSent();
    }

    #[DataProvider('platforms')]
    public function test_each_platform_encrypts_tokens_and_rejects_invalid_credentials(string $provider, string $id, array $settings): void
    {
        $brand = $this->brand();
        $this->post('/social-accounts', ['provider' => $provider, 'brand_id' => $brand->id, 'page_id' => $id, 'access_token' => 'private-token', 'verified_at' => now(), 'page_name' => 'Forged verification'] + $settings)->assertSessionHasNoErrors();
        $account = SocialAccount::firstOrFail();
        $this->assertSame('private-token', $account->access_token);
        $this->assertNull($account->verified_at);
        $this->assertNull($account->page_name);
        $this->assertNotSame('private-token', DB::table('social_accounts')->value('access_token'));
        $this->assertArrayNotHasKey('access_token', $account->toArray());
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);
        $this->post("/social-accounts/{$account->id}/verify")->assertSessionHasErrors('connection');
        $this->get('/social-accounts')->assertOk()->assertSee('Credentials saved')->assertDontSee('private-token')->assertDontSee('Identity verified')->assertSee(route('social.verify', $account), false);
        Http::assertSentCount(1);
    }

    public static function invalidIds(): array
    {
        return [
            'instagram handle' => ['instagram', '@myaccount'],
            'linkedin web address' => ['linkedin', 'https://linkedin.com/company/example'],
            'x handle' => ['x', '@example'],
            'youtube handle' => ['youtube', '@example'],
            'whatsapp display number' => ['whatsapp', '+919876543210'],
            'facebook path' => ['facebook', '../me'],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_platform_ids_are_rejected_and_tokens_not_flashed(string $provider, string $id): void
    {
        $brand = $this->brand();
        $this->post('/social-accounts', ['provider' => $provider, 'brand_id' => $brand->id, 'page_id' => $id, 'access_token' => 'private-token'])->assertSessionHasErrors('page_id');
        $this->assertArrayNotHasKey('access_token', session()->getOldInput());
        $this->assertDatabaseCount('social_accounts', 0);
        Http::assertNothingSent();
    }

    public function test_settings_and_tokens_can_be_updated_preserved_and_removed_explicitly(): void
    {
        $brand = $this->brand();
        $this->post('/social-accounts', ['provider' => 'whatsapp', 'brand_id' => $brand->id, 'page_id' => '12345', 'business_account_id' => '67890', 'access_token' => 'first-token'])->assertSessionHasNoErrors();
        $account = SocialAccount::firstOrFail();
        $this->put("/social-accounts/{$account->id}", ['display_name' => 'Support team', 'access_token' => ''])->assertSessionHasNoErrors();
        $this->assertSame('first-token', $account->fresh()->access_token);
        $this->assertSame(['business_account_id' => '67890'], $account->fresh()->settings);
        $this->assertSame('Support team', $account->fresh()->display_name);
        $this->put("/social-accounts/{$account->id}", ['access_token' => 'new-token', 'business_account_id' => '67891'])->assertSessionHasNoErrors();
        $this->assertSame('new-token', $account->fresh()->access_token);
        $this->assertSame(['business_account_id' => '67891'], $account->fresh()->settings);
        $this->assertNotSame($account->credential_version, $account->fresh()->credential_version);
        $this->put("/social-accounts/{$account->id}", ['business_account_id' => ''])->assertSessionHasNoErrors();
        $this->assertSame(['business_account_id' => null], $account->fresh()->settings);
        $this->delete("/social-accounts/{$account->id}")->assertSessionHasNoErrors();
        $this->assertNull($account->fresh()->access_token);
        $this->assertSame('12345', $account->fresh()->page_id);
        $this->assertSame('Support team', $account->fresh()->display_name);
        Http::assertNothingSent();
    }

    public function test_credentials_and_ids_remain_scoped_to_application_and_provider(): void
    {
        $brand = $this->brand();
        foreach (['facebook', 'instagram', 'whatsapp'] as $provider) {
            $this->post('/social-accounts', ['provider' => $provider, 'brand_id' => $brand->id, 'page_id' => '12345'])->assertSessionHasNoErrors();
        }
        $secondBrand = Brand::factory()->create(['user_id' => $brand->user_id]);
        $this->post('/social-accounts', ['provider' => 'whatsapp', 'brand_id' => $secondBrand->id, 'page_id' => '12345'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('social_accounts', 4);
        $account = SocialAccount::where('provider', 'whatsapp')->where('brand_id', $brand->id)->firstOrFail();
        $this->put("/social-accounts/{$account->id}", ['provider' => 'facebook', 'brand_id' => $secondBrand->id, 'page_id' => '999', 'verified_at' => now(), 'access_token' => 'new-token'])->assertSessionHasNoErrors();
        $this->assertSame('whatsapp', $account->fresh()->provider);
        $this->assertSame('999', $account->fresh()->page_id);
        $this->assertSame($brand->id, $account->fresh()->brand_id);
        $this->assertNull($account->fresh()->verified_at);
        Http::assertNothingSent();
    }

    public function test_another_owner_cannot_read_or_change_new_platform_setup(): void
    {
        $brand = $this->brand();
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id, 'provider' => 'whatsapp', 'display_name' => 'Private sender', 'verified_at' => null]);
        $this->actingAs(User::factory()->create());
        $this->get('/social-accounts')->assertDontSee('Private sender');
        $this->post('/social-accounts', ['provider' => 'whatsapp', 'brand_id' => $brand->id, 'page_id' => '12345'])->assertSessionHasErrors('brand_id');
        $this->put("/social-accounts/{$account->id}", ['page_id' => '999', 'access_token' => 'stolen'])->assertNotFound();
        $this->post("/social-accounts/{$account->id}/verify")->assertNotFound();
        $this->delete("/social-accounts/{$account->id}")->assertNotFound();
        $this->assertSame('test-page-token', $account->fresh()->access_token);
        Http::assertNothingSent();
    }

    public function test_whatsapp_hindi_draft_needs_credentials_and_recipient_before_sending(): void
    {
        $brand = $this->brand();
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id, 'provider' => 'whatsapp', 'verified_at' => null]);
        $this->post('/posts', ['brand_id' => $brand->id, 'title' => 'Hindi update', 'body' => 'नमस्ते! आपका स्वागत है।', 'channel' => 'whatsapp'])->assertSessionHasNoErrors();
        $post = Post::firstOrFail();
        $this->post("/posts/{$post->id}/review")->assertSessionHasNoErrors();
        $this->get("/posts/{$post->id}/publish")->assertOk()->assertSee('Connect an account first')->assertDontSee('Publish now');
        $this->post("/posts/{$post->id}/publish", ['social_account_id' => $account->id, 'request_key' => (string) Str::uuid(), 'fingerprint' => $post->fresh()->publishingFingerprint(), 'confirm' => '1'])->assertSessionHasErrors('recipient');
        $this->assertSame('reviewed', $post->fresh()->status);
        $this->assertSame('whatsapp', $post->fresh()->channel);
        $this->assertSame('नमस्ते! आपका स्वागत है।', $post->fresh()->body);
        $this->assertDatabaseCount('publications', 0);
        Http::assertNothingSent();
    }

    public function test_unknown_providers_and_platform_mismatched_settings_are_rejected(): void
    {
        $brand = $this->brand();
        $this->post('/social-accounts', ['provider' => 'unknown', 'brand_id' => $brand->id, 'page_id' => '12345'])->assertSessionHasErrors('provider');
        $this->post('/social-accounts', ['provider' => ['facebook'], 'brand_id' => $brand->id, 'page_id' => '12345'])->assertSessionHasErrors('provider');
        $this->post('/social-accounts', ['provider' => 'x', 'brand_id' => $brand->id, 'page_id' => '12345', 'business_account_id' => '9988'])->assertSessionHasErrors('business_account_id');
        $this->post('/social-accounts', ['provider' => 'whatsapp', 'brand_id' => $brand->id, 'page_id' => '12345', 'facebook_page_id' => '9988'])->assertSessionHasErrors('facebook_page_id');
        $this->get('/social-accounts?provider[]=x')->assertOk();
        $this->assertDatabaseCount('social_accounts', 0);
        Http::assertNothingSent();
    }

    public function test_account_labels_are_escaped_and_display_edits_preserve_facebook_verification(): void
    {
        $brand = $this->brand();
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id]);
        $this->put("/social-accounts/{$account->id}", ['page_id' => $account->page_id, 'display_name' => '<script>alert(1)</script>', 'access_token' => ''])->assertSessionHasNoErrors();
        $this->assertNotNull($account->fresh()->verified_at);
        $this->get('/social-accounts')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/')->assertOk()->assertSee('Social accounts saved')->assertSee('1 accounts verified');
        Http::assertNothingSent();
    }

    public function test_page_id_correction_updates_existing_account_and_preserves_token_and_history(): void
    {
        $brand = $this->brand();
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id, 'page_id' => '12345', 'error_code' => 'rejected']);
        $publication = Publication::factory()->create(['social_account_id' => $account->id, 'status' => 'published']);
        $history = $publication->fresh()->getAttributes();

        $this->put("/social-accounts/{$account->id}", ['page_id' => '67890', 'access_token' => ''])->assertSessionHasNoErrors();

        $saved = $account->fresh();
        $this->assertSame('67890', $saved->page_id);
        $this->assertSame($account->access_token, $saved->access_token);
        $this->assertNull($saved->verified_at);
        $this->assertNull($saved->page_name);
        $this->assertNull($saved->error_code);
        $this->assertNotSame($account->credential_version, $saved->credential_version);
        $this->assertSame($history, $publication->fresh()->getAttributes());
        $this->assertDatabaseCount('social_accounts', 1);
        $this->get('/social-accounts')->assertOk()->assertSee('value="67890"', false)->assertSee('Not tested');
        Http::assertNothingSent();
    }

    public function test_duplicate_page_id_edit_is_rejected_without_changing_credentials(): void
    {
        $brand = $this->brand();
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id, 'page_id' => '12345']);
        SocialAccount::factory()->create(['brand_id' => $brand->id, 'page_id' => '67890']);

        $this->put("/social-accounts/{$account->id}", ['page_id' => '67890', 'access_token' => 'replacement-token'])
            ->assertSessionHasErrors('page_id');

        $this->assertSame('12345', $account->fresh()->page_id);
        $this->assertSame($account->access_token, $account->fresh()->access_token);
        $this->assertNotNull($account->fresh()->verified_at);
        $this->assertDatabaseCount('social_accounts', 2);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_id_edits_leave_the_saved_account_unchanged(string $provider, string $id): void
    {
        $brand = $this->brand();
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id, 'provider' => $provider]);
        $this->put("/social-accounts/{$account->id}", ['page_id' => $id])->assertSessionHasErrors('page_id');
        $this->assertSame($account->page_id, $account->fresh()->page_id);
        Http::assertNothingSent();
    }
}
