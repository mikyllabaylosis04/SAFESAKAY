<?php

namespace App\Http\Controllers\Settings;

use App\Enums\OperatorGcashStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\OperatorGcashAccount;
use App\Models\OrganizationCommissionSetting;
use App\Notifications\OperatorGcashReviewedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PaymentSettingsController extends Controller
{
    public function updateNoShowRules(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->role === UserRole::Administrator, 403);

        $organization = $request->user()?->organization;
        abort_unless($organization, 404);

        $data = $request->validate([
            'operator_grace_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'passenger_grace_minutes' => ['required', 'integer', 'min:5', 'max:120'],
        ]);

        $organization->forceFill([
            'operator_no_show_grace_minutes' => (int) $data['operator_grace_minutes'],
            'passenger_no_show_grace_minutes' => (int) $data['passenger_grace_minutes'],
        ])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Rental policy settings saved.',
        ]);

        return to_route('settings.rental-policies.edit');
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $request->user()?->organization;

        abort_unless($organization, 404);

        $data = $request->validate([
            'gcash_account_name' => ['required', 'string', 'max:120'],
            'gcash_qr' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_gcash_qr' => ['sometimes', 'boolean'],
        ]);

        $organization->gcash_account_name = trim($data['gcash_account_name']);

        if ($request->boolean('remove_gcash_qr') && $organization->gcash_qr_path) {
            Storage::disk('public')->delete($organization->gcash_qr_path);
            $organization->gcash_qr_path = null;
        }

        if ($request->hasFile('gcash_qr')) {
            if ($organization->gcash_qr_path) {
                Storage::disk('public')->delete($organization->gcash_qr_path);
            }

            $organization->gcash_qr_path = $request->file('gcash_qr')->store(
                'gcash-qr/'.$organization->id,
                'public',
            );
        }

        $organization->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'GCash payment details saved.',
        ]);

        return to_route('settings.payments.edit');
    }

    public function updateCommission(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->role === UserRole::Administrator, 403);

        $organization = $request->user()?->organization;
        abort_unless($organization, 404);

        $data = $request->validate([
            'commission_enabled' => ['required', 'boolean'],
            'commission_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'settlement_due_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);

        $setting = OrganizationCommissionSetting::query()->firstOrNew(['organization_id' => $organization->id]);
        $percentage = number_format((float) $data['commission_percentage'], 2, '.', '');
        $changed = ! $setting->exists
            || (bool) $setting->commission_enabled !== (bool) $data['commission_enabled']
            || number_format((float) $setting->commission_percentage, 2, '.', '') !== $percentage;

        $setting->commission_enabled = $data['commission_enabled'];
        $setting->commission_percentage = $percentage;

        if ($changed || ! $setting->effective_date) {
            $setting->effective_date = now()->toDateString();
        }

        if (isset($data['settlement_due_days'])) {
            $setting->settlement_due_days = (int) $data['settlement_due_days'];
        }

        $setting->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Commission rate saved. It applies to new rentals from now on.',
        ]);

        return to_route('settings.commission.edit');
    }

    public function approve(Request $request, OperatorGcashAccount $account): RedirectResponse
    {
        $account = $this->accountForOrganization($request, $account);

        abort_unless($account->status === OperatorGcashStatus::PendingApproval, 422);

        $account->operator?->gcashAccounts()
            ->where('status', OperatorGcashStatus::Approved)
            ->whereKeyNot($account->id)
            ->update([
                'status' => OperatorGcashStatus::Rejected,
                'rejection_reason' => 'Replaced by an approved GCash update.',
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()?->id,
            ]);

        $account->update([
            'status' => OperatorGcashStatus::Approved,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()?->id,
            'rejection_reason' => null,
        ]);

        $account->operator?->user?->notify(new OperatorGcashReviewedNotification($account->fresh()));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Payout account approved. Operator shares can now be sent to this GCash.',
        ]);

        return to_route('operator-payouts.index', ['view' => 'accounts']);
    }

    public function reject(Request $request, OperatorGcashAccount $account): RedirectResponse
    {
        $account = $this->accountForOrganization($request, $account);

        abort_unless($account->status === OperatorGcashStatus::PendingApproval, 422);

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $account->update([
            'status' => OperatorGcashStatus::Rejected,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()?->id,
            'rejection_reason' => trim($data['rejection_reason']),
        ]);

        $account->operator?->user?->notify(new OperatorGcashReviewedNotification($account->fresh()));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Payout account rejected. The operator was notified and can submit again.',
        ]);

        return to_route('operator-payouts.index', ['view' => 'accounts']);
    }

    private function accountForOrganization(Request $request, OperatorGcashAccount $account): OperatorGcashAccount
    {
        $organizationId = $request->user()?->organization_id;
        $account->loadMissing(['operator.boat', 'operator.user']);

        $belongs = (int) $account->organization_id === (int) $organizationId
            || (int) $account->operator?->boat?->organization_id === (int) $organizationId;

        abort_unless($belongs, 404);

        return $account;
    }
}
