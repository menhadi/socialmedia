<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\HealthMonitor;
use App\Services\Monitoring\CheckMonitor;
use App\Services\Monitoring\PublicEndpoint;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MonitoringController extends Controller
{
    private function own(Request $request, HealthMonitor $monitor): void
    {
        abort_unless($monitor->brand->user_id === $request->user()->id, 404);
    }

    public function index(Request $request): View
    {
        $brands = Brand::where('user_id', $request->user()->id)->with('healthMonitor')->orderBy('name')->get();

        return view('monitoring.index', ['brands' => $brands, 'lastSweep' => Cache::get('monitoring.last_sweep_at')]);
    }

    public function save(Request $request, Brand $brand, PublicEndpoint $endpoint): RedirectResponse
    {
        abort_unless($brand->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'url' => 'required|string|url:http,https|max:2048',
            'expected_status' => 'required|integer|min:200|max:299',
            'interval_minutes' => ['required', 'integer', Rule::in([5, 15, 60])],
            'enabled' => 'nullable|boolean',
        ]);
        try {
            $endpoint->host($data['url']);
        } catch (\RuntimeException) {
            throw ValidationException::withMessages(['url' => 'Use a public HTTP(S) hostname on port 80 or 443, without login details, query parameters or fragments.']);
        }
        DB::transaction(function () use ($brand, $data, $request): void {
            Brand::whereKey($brand->id)->lockForUpdate()->firstOrFail();
            $monitor = HealthMonitor::where('brand_id', $brand->id)->lockForUpdate()->first() ?? new HealthMonitor;
            $changed = ! $monitor->exists || $monitor->url !== $data['url'] || $monitor->expected_status !== (int) $data['expected_status'];
            if ($monitor->exists) {
                $monitor->closeIncident($changed ? 'settings_changed' : 'settings_saved');
            }
            $monitor->forceFill([
                'brand_id' => $brand->id, 'url' => $data['url'], 'expected_status' => $data['expected_status'],
                'interval_minutes' => $data['interval_minutes'], 'enabled' => $request->boolean('enabled'),
                'configuration_version' => (string) Str::uuid(), 'running_key' => null, 'running_since' => null,
                'next_check_at' => $request->boolean('enabled') ? now() : null,
            ]);
            if ($changed) {
                $monitor->forceFill(['last_status' => 'unknown', 'last_checked_at' => null,
                    'last_response_ms' => null, 'last_http_status' => null, 'last_error' => null]);
            }
            $monitor->save();
        }, 5);

        return back()->with('success', 'Monitor settings saved. No health request was made. Enabled monitors will be checked when the scheduler runs.');
    }

    public function check(Request $request, HealthMonitor $monitor, CheckMonitor $service): RedirectResponse
    {
        $this->own($request, $monitor);
        $check = $service->run($monitor);

        return redirect()->route('monitoring.show', $monitor)->with('success', $check
            ? 'Check completed: '.ucfirst($check->status).'. Details are recorded below.'
            : 'A check is already running. Refresh shortly to see its result.');
    }

    public function show(Request $request, HealthMonitor $monitor): View
    {
        $this->own($request, $monitor);

        return view('monitoring.show', [
            'monitor' => $monitor,
            'checks' => $monitor->checks()->latest('id')->paginate(30, ['*'], 'checks_page'),
            'incidents' => $monitor->incidents()->latest('id')->paginate(10, ['*'], 'incidents_page'),
        ]);
    }
}
