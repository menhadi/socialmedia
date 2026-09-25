<?php

namespace App\Services\Monitoring;

use App\Models\HealthCheck;
use App\Models\HealthIncident;
use App\Models\HealthMonitor;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class CheckMonitor
{
    public function __construct(private PublicEndpoint $endpoint) {}

    public function run(HealthMonitor $monitor, bool $scheduled = false): ?HealthCheck
    {
        $check = DB::transaction(function () use ($monitor, $scheduled): ?HealthCheck {
            $current = HealthMonitor::whereKey($monitor->id)->lockForUpdate()->firstOrFail();
            if (($scheduled && (! $current->enabled || $current->next_check_at?->isFuture()))
                || ($current->running_key && $current->running_since?->gt(now()->subMinutes(2)))) {
                return null;
            }
            if ($current->running_key) {
                $current->checks()->where('request_key', $current->running_key)->where('status', 'running')
                    ->update(['status' => 'error', 'error_code' => 'interrupted', 'completed_at' => now()]);
            }
            $check = new HealthCheck;
            $check->forceFill([
                'health_monitor_id' => $current->id, 'request_key' => (string) Str::uuid(),
                'configuration_version' => $current->configuration_version, 'url' => $current->url,
                'checked_at' => now(), 'status' => 'running',
            ])->save();
            $current->forceFill([
                'running_key' => $check->request_key, 'running_since' => now(),
                'next_check_at' => now()->addMinutes($current->interval_minutes),
            ])->save();

            return $check;
        }, 5);
        if (! $check) {
            return null;
        }
        $start = hrtime(true);
        $result = ['status' => 'error', 'http_status' => null, 'error_code' => 'internal'];
        try {
            $options = $this->endpoint->options($check->url);
            $response = Http::withOptions($options)->setHandler(new CurlHandler)
                ->withUserAgent('ContentHub-HealthCheck/1.0')->connectTimeout(5)->timeout(10)
                ->withoutRedirecting()->head($check->url);
            $expected = HealthMonitor::findOrFail($monitor->id)->expected_status;
            $result = ['status' => $response->status() === $expected ? 'online' : 'offline',
                'http_status' => $response->status(), 'error_code' => $response->status() === $expected ? null : 'status'];
        } catch (ConnectionException) {
            $result = ['status' => 'offline', 'http_status' => null, 'error_code' => 'connection'];
        } catch (RuntimeException $error) {
            $code = in_array($error->getMessage(), ['blocked', 'dns', 'configuration'], true) ? $error->getMessage() : 'internal';
            $result['error_code'] = $code;
            $result['status'] = $code === 'dns' ? 'offline' : 'error';
        } catch (\Throwable) {
            // Store a safe local error code, never exception text or response bodies.
        }
        $result['response_ms'] = (int) min(4294967295, round((hrtime(true) - $start) / 1000000));

        return DB::transaction(function () use ($check, $result): HealthCheck {
            $current = HealthMonitor::whereKey($check->health_monitor_id)->lockForUpdate()->firstOrFail();
            if ($current->configuration_version !== $check->configuration_version || $current->running_key !== $check->request_key) {
                $check->forceFill(['status' => 'error', 'error_code' => 'changed', 'completed_at' => now()])->save();

                return $check;
            }
            if ($current->last_checked_at?->lt($check->checked_at->copy()->subSeconds($current->interval_minutes * 120 + 60))) {
                $current->closeIncident('gap');
            }
            if ($result['status'] === 'offline') {
                if (! $current->incidents()->whereNull('ended_at')->exists()) {
                    $incident = new HealthIncident;
                    $incident->forceFill(['health_monitor_id' => $current->id, 'url' => $check->url, 'started_at' => $check->checked_at])->save();
                }
            } elseif ($result['status'] === 'online') {
                $current->incidents()->whereNull('ended_at')->update(['ended_at' => $check->checked_at, 'end_reason' => 'recovered']);
            } else {
                $current->closeIncident('unknown');
            }
            $check->forceFill($result + ['completed_at' => now()])->save();
            $current->forceFill([
                'last_status' => $result['status'], 'last_checked_at' => $check->checked_at,
                'last_http_status' => $result['http_status'], 'last_response_ms' => $result['response_ms'],
                'last_error' => $result['error_code'], 'running_key' => null, 'running_since' => null,
            ])->save();

            return $check;
        }, 5);
    }
}
