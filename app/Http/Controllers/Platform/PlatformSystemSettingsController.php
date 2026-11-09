<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformEvent;
use App\Support\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PlatformSystemSettingsController extends Controller
{
    private const LABELS = [
        'platform_name' => 'Platform name',
        'platform_description' => 'Description',
        'logo_path' => 'Logo',
        'notify_organization_registration' => 'Registration alerts',
        'notify_approval_requests' => 'Request alerts',
        'notify_system_activity' => 'Activity alerts',
        'session_timeout' => 'Session timeout',
        'max_login_attempts' => 'Login attempts',
    ];

    public function index(): Response
    {
        $settings = PlatformSettings::model();

        return Inertia::render('platform/SystemSettings', [
            'settings' => [
                'platform_name' => $settings->platform_name,
                'platform_description' => $settings->platform_description,
                'logo_url' => $settings->logo_path
                    ? Storage::disk('public')->url($settings->logo_path)
                    : null,
                'notify_organization_registration' => (bool) $settings->notify_organization_registration,
                'notify_approval_requests' => (bool) $settings->notify_approval_requests,
                'notify_system_activity' => (bool) $settings->notify_system_activity,
                'session_timeout' => PlatformSettings::sessionTimeout(),
                'max_login_attempts' => PlatformSettings::maxLoginAttempts(),
            ],
            'options' => [
                'session_timeouts' => PlatformSettings::SESSION_TIMEOUTS,
                'login_attempts' => PlatformSettings::LOGIN_ATTEMPTS,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'platform_name' => ['required', 'string', 'max:100'],
            'platform_description' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
            'notify_organization_registration' => ['required', 'boolean'],
            'notify_approval_requests' => ['required', 'boolean'],
            'notify_system_activity' => ['required', 'boolean'],
            'session_timeout' => ['required', 'integer', Rule::in(PlatformSettings::SESSION_TIMEOUTS)],
            'max_login_attempts' => ['required', 'integer', Rule::in(PlatformSettings::LOGIN_ATTEMPTS)],
        ]);

        $settings = PlatformSettings::model();
        $oldLogo = $settings->logo_path;

        unset($validated['logo'], $validated['remove_logo']);
        $validated['platform_name'] = trim($validated['platform_name']);

        if ($request->hasFile('logo')) {
            $validated['logo_path'] = $request->file('logo')->store('platform/logos', 'public');
        } elseif ($request->boolean('remove_logo')) {
            $validated['logo_path'] = null;
        }

        $settings->fill($validated);
        $changed = array_keys($settings->getDirty());
        $settings->save();

        if (array_key_exists('logo_path', $validated) && $oldLogo && $oldLogo !== $settings->logo_path) {
            Storage::disk('public')->delete($oldLogo);
        }

        PlatformSettings::forget();

        if ($changed !== []) {
            PlatformEvent::query()->create([
                'actor_id' => $request->user()?->id,
                'organization_id' => null,
                'organization_name' => 'Platform',
                'action' => 'settings_updated',
                'note' => 'Changed: '.collect($changed)
                    ->map(fn (string $key) => self::LABELS[$key] ?? $key)
                    ->implode(', '),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $changed === [] ? 'No changes to save.' : 'System settings saved.',
        ]);

        return to_route('platform.system-settings');
    }
}
