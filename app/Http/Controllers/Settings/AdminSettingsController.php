<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Operator;
use App\Models\Organization;
use App\Models\OrganizationCommissionSetting;
use App\Models\RentalCheckoutPolicyAcceptance;
use App\Support\AdminNotificationPreferences;
use App\Support\RentalCheckoutPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminSettingsController extends Controller
{
    public function commission(Request $request): Response
    {
        $organization = $request->user()?->organization;
        abort_unless($organization, 404);

        $commission = OrganizationCommissionSetting::query()
            ->where('organization_id', $organization->id)
            ->first();

        return Inertia::render('settings/Commission', [
            'can_manage' => $request->user()?->role === UserRole::Administrator,
            'commission' => [
                'enabled' => (bool) ($commission?->commission_enabled),
                'percentage' => $commission?->percentageAmount() ?? 0,
                'effective_date_label' => $commission?->effective_date?->format('M d, Y'),
            ],
        ]);
    }

    public function rentalPolicies(Request $request): Response
    {
        $organization = $request->user()?->organization;
        abort_unless($organization, 404);

        $passengerGrace = $organization->passengerNoShowGraceMinutes();
        $operatorGrace = $organization->operatorNoShowGraceMinutes();

        return Inertia::render('settings/RentalPolicies', [
            'can_manage' => $request->user()?->role === UserRole::Administrator,
            'rules' => [
                'passenger_grace_minutes' => $passengerGrace,
                'operator_grace_minutes' => $operatorGrace,
            ],
            'policy' => [
                'version' => RentalCheckoutPolicy::VERSION,
                'acceptances' => RentalCheckoutPolicyAcceptance::query()
                    ->whereHas('booking', fn ($query) => $query->where('organization_id', $organization->id))
                    ->where('policy_version', RentalCheckoutPolicy::VERSION)
                    ->count(),
                'sections' => $this->policySections($organization, $passengerGrace, $operatorGrace),
            ],
        ]);
    }

    public function notifications(Request $request): Response
    {
        $user = $request->user();
        $preferences = AdminNotificationPreferences::for($user);

        return Inertia::render('settings/Notifications', [
            'items' => collect(AdminNotificationPreferences::catalog())
                ->map(fn (array $meta, string $key) => [
                    'key' => $key,
                    'label' => $meta['label'],
                    'description' => $meta['description'],
                    'locked' => $meta['locked'],
                    'enabled' => $preferences[$key],
                ])
                ->values()
                ->all(),
        ]);
    }

    public function updateNotifications(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['boolean'],
        ]);

        $request->user()->forceFill([
            'notification_preferences' => AdminNotificationPreferences::normalize($data['preferences']),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notification preferences saved.']);

        return to_route('settings.notifications.edit');
    }

    /**
     * Read-only summary of the rules passengers accept before paying. Money terms come from the
     * versioned checkout policy, so editing them here could never change an accepted booking.
     *
     * @return list<array{key: string, title: string, body: string}>
     */
    private function policySections(Organization $organization, int $passengerGrace, int $operatorGrace): array
    {
        $checkout = collect(RentalCheckoutPolicy::sectionsFor($organization->name))->pluck('body')->all();

        return [
            [
                'key' => 'operator_eligibility',
                'title' => 'Operator Eligibility',
                'body' => 'Boat operators must be at least '.Operator::MIN_AGE.' years old. Minors and underage skippers cannot be registered, assigned, or allowed to operate a SafeSakay boat. This is a safety requirement for every regular trip and private rental.',
            ],
            [
                'key' => 'readiness',
                'title' => 'Operator Readiness Confirmation',
                'body' => 'The assigned operator confirms readiness in the app one day before the trip. Unconfirmed rentals appear in your notifications so you can follow up or arrange a replacement.',
            ],
            [
                'key' => 'passenger_cancellation',
                'title' => 'Passenger Cancellation Policy',
                'body' => trim(($checkout[0] ?? '').' '.($checkout[1] ?? '')),
            ],
            [
                'key' => 'passenger_no_show',
                'title' => 'Passenger No-Show Policy',
                'body' => 'The operator may report a passenger no-show only on the trip date, '.$passengerGrace.' minutes after the scheduled departure, with a signed declaration. '.($checkout[2] ?? '').' The passenger can dispute it and you review the dispute.',
            ],
            [
                'key' => 'operator_no_show',
                'title' => 'Boat / Operator No-Show Policy',
                'body' => 'The passenger may report that the boat or operator did not arrive '.$operatorGrace.' minutes after the scheduled departure. You verify the report first. The passenger then chooses a replacement boat, a new date, or a full refund.',
            ],
            [
                'key' => 'weather',
                'title' => 'Weather Cancellation Policy',
                'body' => $checkout[3] ?? '',
            ],
            [
                'key' => 'refund',
                'title' => 'Refund Policy',
                'body' => trim(($checkout[4] ?? '').' Refunds are sent through Xendit to the passenger’s original payment method and only after the booking terms and verified incident details are applied.'),
            ],
        ];
    }
}
