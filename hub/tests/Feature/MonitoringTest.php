<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\HealthCheck;
use App\Models\HealthIncident;
use App\Models\HealthMonitor;
use App\Models\User;
use App\Services\Monitoring\CheckMonitor;
use App\Services\Monitoring\PublicEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as OutboundRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
    }

    private function monitor(array $attributes = []): HealthMonitor
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return HealthMonitor::factory()->create($attributes + ['brand_id' => Brand::factory()->create(['user_id' => $user->id])->id]);
    }

    private function publicDns(array $addresses = ['8.8.8.8']): void
    {
        $this->partialMock(PublicEndpoint::class, function ($mock) use ($addresses): void {
            $mock->shouldReceive('addresses')->andReturn($addresses);
        });
    }

    private function settings(array $overrides = []): array
    {
        return $overrides + ['url' => 'https://health.example.com/up', 'expected_status' => 200, 'interval_minutes' => 5, 'enabled' => 0];
    }

    public function test_monitoring_routes_require_authentication_and_owner_access(): void
    {
        $monitor = HealthMonitor::factory()->create();
        $this->get('/monitoring')->assertRedirect('/login');
        $this->get("/monitoring/{$monitor->id}")->assertRedirect('/login');
        $this->post("/monitoring/{$monitor->id}/check")->assertRedirect('/login');
        $this->put("/monitoring/applications/{$monitor->brand_id}", $this->settings())->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->get('/monitoring')->assertDontSee($monitor->brand->name);
        $this->get("/monitoring/{$monitor->id}")->assertNotFound();
        $this->post("/monitoring/{$monitor->id}/check")->assertNotFound();
        $this->put("/monitoring/applications/{$monitor->brand_id}", $this->settings())->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_saving_a_monitor_is_local_only_and_unknown_until_checked(): void
    {
        $user = User::factory()->create();
        $brand = Brand::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user)->put("/monitoring/applications/{$brand->id}", $this->settings(['enabled' => 1, 'last_status' => 'online']))->assertSessionHasNoErrors();
        $monitor = HealthMonitor::firstOrFail();
        $this->assertTrue($monitor->enabled);
        $this->assertSame('unknown', $monitor->last_status);
        $this->assertNull($monitor->last_checked_at);
        $this->get('/monitoring')->assertOk()->assertSee('No scheduler sweep')->assertSee('Unknown');
        $this->get('/')->assertOk()->assertSee('Application health')->assertSee('Unknown');
        $this->assertDatabaseCount('health_checks', 0);
        Http::assertNothingSent();
    }

    public static function invalidUrls(): array
    {
        return array_map(fn (string $url): array => [$url], [
            'http://127.0.0.1/up', 'http://[::1]/up', 'http://localhost/up',
            'http://2130706433/up', 'http://0x7f000001/up', 'https://example.com:8443/up',
            'https://user:secret@example.com/up', 'https://example.com/up?token=secret',
            'https://example.com/up#section', 'file:///etc/passwd', 'javascript:alert(1)',
        ]);
    }

    #[DataProvider('invalidUrls')]
    public function test_unsafe_endpoint_urls_are_rejected_before_saving(string $url): void
    {
        $monitor = $this->monitor();
        $this->put("/monitoring/applications/{$monitor->brand_id}", $this->settings(['url' => $url]))->assertSessionHasErrors('url');
        $this->assertSame('https://health.example.com/up', $monitor->fresh()->url);
        Http::assertNothingSent();
    }

    public function test_intervals_and_expected_codes_are_validated(): void
    {
        $monitor = $this->monitor();
        $this->put("/monitoring/applications/{$monitor->brand_id}", $this->settings(['interval_minutes' => 1, 'expected_status' => 500]))->assertSessionHasErrors(['interval_minutes', 'expected_status']);
        Http::assertNothingSent();
    }

    public function test_manual_check_uses_head_pinned_dns_and_no_redirects_and_saves_only_status(): void
    {
        $monitor = $this->monitor(['expected_status' => 204]);
        $this->publicDns();
        Http::fake(['https://health.example.com/up' => function (OutboundRequest $request, array $options) {
            $this->assertSame('HEAD', $request->method());
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            $this->assertTrue($options['verify']);
            $this->assertSame(['health.example.com:443:8.8.8.8'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertSame(10, $options['timeout']);
            $this->assertFalse($request->hasHeader('Authorization'));

            return Http::response('private response body must not be saved', 204);
        }]);
        $this->post("/monitoring/{$monitor->id}/check")->assertSessionHasNoErrors()->assertRedirect("/monitoring/{$monitor->id}");
        $this->assertSame('online', $monitor->fresh()->last_status);
        $this->assertSame(204, $monitor->fresh()->last_http_status);
        $this->assertNotNull($monitor->fresh()->last_response_ms);
        $this->assertNull($monitor->fresh()->running_key);
        $this->assertSame('paused', $monitor->fresh()->displayStatus());
        $this->get("/monitoring/{$monitor->id}")->assertOk()->assertSee('Online')->assertDontSee('private response body must not be saved');
        $this->assertDatabaseCount('health_checks', 1);
        $this->assertDatabaseCount('health_incidents', 0);
        Http::assertSentCount(1);
    }

    public function test_offline_checks_share_one_outage_until_recovery(): void
    {
        $monitor = $this->monitor(['enabled' => true]);
        $this->publicDns();
        Http::fake(['https://health.example.com/up' => Http::sequence()->push('', 503)->push('', 500)->push('', 200)]);
        $started = now();
        $this->post("/monitoring/{$monitor->id}/check")->assertSessionHasNoErrors();
        $this->assertSame('offline', $monitor->fresh()->last_status);
        $this->travel(5)->minutes();
        $this->post("/monitoring/{$monitor->id}/check")->assertSessionHasNoErrors();
        $this->assertDatabaseCount('health_incidents', 1);
        $this->travel(5)->minutes();
        $this->post("/monitoring/{$monitor->id}/check")->assertSessionHasNoErrors();
        $incident = HealthIncident::firstOrFail();
        $this->assertTrue($incident->started_at->equalTo($started));
        $this->assertTrue($incident->ended_at->equalTo(now()));
        $this->assertSame('recovered', $incident->end_reason);
        $this->assertSame('online', $monitor->fresh()->last_status);
        $this->get("/monitoring/{$monitor->id}")->assertOk()->assertSee('10 min observed');
        Http::assertSentCount(3);
    }

    public function test_a_redirect_is_recorded_as_a_failed_check_without_following_it(): void
    {
        $monitor = $this->monitor();
        $this->publicDns();
        Http::fake(['https://health.example.com/up' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin'])]);
        $this->post("/monitoring/{$monitor->id}/check")->assertSessionHasNoErrors();
        $this->assertSame('offline', $monitor->fresh()->last_status);
        $this->assertSame('status', $monitor->fresh()->last_error);
        Http::assertSentCount(1);
    }

    public function test_connection_failure_is_recorded_without_exposing_exception_details(): void
    {
        $monitor = $this->monitor();
        $this->publicDns();
        Http::fake(['https://health.example.com/up' => Http::failedConnection('private network details')]);
        $this->post("/monitoring/{$monitor->id}/check")->assertSessionHasNoErrors();
        $this->assertSame('offline', $monitor->fresh()->last_status);
        $this->assertSame('connection', $monitor->fresh()->last_error);
        $this->get("/monitoring/{$monitor->id}")->assertDontSee('private network details')->assertSee('could not be reached');
        $this->assertDatabaseCount('health_incidents', 1);
    }

    public static function nonPublicAddresses(): array
    {
        return array_map(fn (string $ip): array => [$ip], ['127.0.0.1', '10.0.0.1', '169.254.169.254', '172.16.0.1', '192.168.1.1', '100.64.0.1', '0.0.0.0', '224.0.0.1', '::1']);
    }

    #[DataProvider('nonPublicAddresses')]
    public function test_private_or_mixed_dns_answers_are_blocked_and_not_called(string $ip): void
    {
        $monitor = $this->monitor();
        $this->publicDns(['8.8.8.8', $ip]);
        $this->post("/monitoring/{$monitor->id}/check")->assertSessionHasNoErrors();
        $this->assertSame('error', $monitor->fresh()->last_status);
        $this->assertSame('blocked', $monitor->fresh()->last_error);
        $this->assertDatabaseCount('health_incidents', 0);
        Http::assertNothingSent();
    }

    public function test_dns_failure_is_recorded_as_unreachable(): void
    {
        $monitor = $this->monitor();
        $this->publicDns([]);
        $this->post("/monitoring/{$monitor->id}/check")->assertSessionHasNoErrors();
        $this->assertSame('offline', $monitor->fresh()->last_status);
        $this->assertSame('dns', $monitor->fresh()->last_error);
        Http::assertNothingSent();
    }

    public function test_scheduler_checks_only_enabled_due_monitors_and_records_sweep(): void
    {
        $due = $this->monitor(['enabled' => true]);
        HealthMonitor::factory()->create(['enabled' => false]);
        HealthMonitor::factory()->create(['enabled' => true, 'next_check_at' => now()->addMinutes(15)]);
        $this->publicDns();
        Http::fake(['https://health.example.com/up' => Http::response('', 200)]);
        $this->artisan('hub:check-monitors')->expectsOutput('Completed 1 health checks.')->assertSuccessful();
        $this->assertNotNull(Cache::get('monitoring.last_sweep_at'));
        $this->assertTrue($due->fresh()->next_check_at->equalTo(now()->addMinutes(5)));
        $this->artisan('hub:check-monitors')->expectsOutput('Completed 0 health checks.')->assertSuccessful();
        $this->assertDatabaseCount('health_checks', 1);
        Http::assertSentCount(1);
    }

    public function test_stale_results_do_not_claim_current_uptime_and_monitoring_gaps_split_outages(): void
    {
        $monitor = $this->monitor(['enabled' => true]);
        $this->publicDns();
        Http::fake(['https://health.example.com/up' => Http::response('', 503)]);
        $this->post("/monitoring/{$monitor->id}/check")->assertSessionHasNoErrors();
        $lastObserved = now();
        $this->travel(20)->minutes();
        $this->assertSame('stale', $monitor->fresh()->displayStatus());
        $this->get('/')->assertSee('Stale');
        $this->post("/monitoring/{$monitor->id}/check")->assertSessionHasNoErrors();
        $this->assertDatabaseCount('health_incidents', 2);
        $first = HealthIncident::orderBy('id')->firstOrFail();
        $this->assertSame('gap', $first->end_reason);
        $this->assertTrue($first->ended_at->equalTo($lastObserved));
        Http::assertSentCount(2);
    }

    public function test_pausing_or_changing_url_closes_observations_and_retains_history(): void
    {
        $monitor = $this->monitor(['enabled' => true, 'last_status' => 'offline', 'last_checked_at' => now()]);
        HealthIncident::factory()->create(['health_monitor_id' => $monitor->id, 'started_at' => now()->subMinutes(5)]);
        HealthCheck::factory()->create(['health_monitor_id' => $monitor->id, 'status' => 'offline']);
        $this->put("/monitoring/applications/{$monitor->brand_id}", $this->settings())->assertSessionHasNoErrors();
        $this->assertFalse($monitor->fresh()->enabled);
        $this->assertNotNull(HealthIncident::firstOrFail()->ended_at);
        $this->put("/monitoring/applications/{$monitor->brand_id}", $this->settings(['url' => 'https://another.example.com/health']))->assertSessionHasNoErrors();
        $this->assertSame('unknown', $monitor->fresh()->last_status);
        $this->assertNull($monitor->fresh()->last_checked_at);
        $this->assertDatabaseCount('health_checks', 1);
        Http::assertNothingSent();
    }

    public function test_overlapping_checks_are_skipped_and_abandoned_checks_can_recover(): void
    {
        $monitor = $this->monitor(['running_key' => (string) Str::uuid(), 'running_since' => now()]);
        $old = HealthCheck::factory()->create(['health_monitor_id' => $monitor->id, 'request_key' => $monitor->running_key, 'status' => 'running', 'completed_at' => null]);
        $this->post("/monitoring/{$monitor->id}/check")->assertSessionHasNoErrors();
        Http::assertNothingSent();
        $this->travel(3)->minutes();
        $this->publicDns();
        Http::fake(['https://health.example.com/up' => Http::response('', 200)]);
        $this->post("/monitoring/{$monitor->id}/check")->assertSessionHasNoErrors();
        $this->assertSame('interrupted', $old->fresh()->error_code);
        $this->assertSame('online', $monitor->fresh()->last_status);
        $this->assertDatabaseCount('health_checks', 2);
        Http::assertSentCount(1);
    }

    public function test_inflight_checks_cannot_overwrite_changed_settings_or_send_twice(): void
    {
        $monitor = $this->monitor(['enabled' => true]);
        $this->publicDns();
        Http::fake(['https://health.example.com/up' => function () use ($monitor) {
            $this->assertNull(app(CheckMonitor::class)->run($monitor));
            $monitor->forceFill(['configuration_version' => (string) Str::uuid(), 'url' => 'https://new.example.com/up', 'last_status' => 'unknown', 'running_key' => null, 'running_since' => null])->save();

            return Http::response('', 200);
        }]);
        $this->post("/monitoring/{$monitor->id}/check")->assertSessionHasNoErrors();
        $this->assertSame('changed', HealthCheck::firstOrFail()->error_code);
        $this->assertSame('unknown', $monitor->fresh()->last_status);
        $this->assertNull($monitor->fresh()->last_checked_at);
        Http::assertSentCount(1);
    }

    public function test_pruning_keeps_incidents_and_only_removes_completed_checks_older_than_30_days(): void
    {
        $monitor = $this->monitor();
        HealthCheck::factory()->create(['health_monitor_id' => $monitor->id, 'checked_at' => now()->subDays(31)]);
        $recent = HealthCheck::factory()->create(['health_monitor_id' => $monitor->id]);
        HealthIncident::factory()->create(['health_monitor_id' => $monitor->id, 'started_at' => now()->subDays(40), 'ended_at' => now()->subDays(39), 'end_reason' => 'recovered']);
        $this->artisan('hub:check-monitors')->assertSuccessful();
        $this->assertDatabaseCount('health_checks', 1);
        $this->assertNotNull($recent->fresh());
        $this->assertDatabaseCount('health_incidents', 1);
        Http::assertNothingSent();
    }

    public function test_monitor_views_escape_application_names_and_show_a_paused_state(): void
    {
        $monitor = $this->monitor();
        $monitor->brand->update(['name' => '<script>alert(1)</script>']);
        foreach (['/monitoring', "/monitoring/{$monitor->id}", '/'] as $url) {
            $this->get($url)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        }
        $this->get('/monitoring')->assertSee('Paused');
        Http::assertNothingSent();
    }
}
