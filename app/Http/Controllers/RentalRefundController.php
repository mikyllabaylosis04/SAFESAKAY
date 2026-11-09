<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\RentalRefundRequest;
use App\Services\RentalRefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RentalRefundController extends Controller
{
    /**
     * PATCH /bookings/refund-requests/{rentalRefundRequest}
     * Admin approves or rejects passenger refund requests, retries failed Xendit refunds, records a manual
     * GCash refund, or re-checks Xendit.
     */
    public function update(Request $request, RentalRefundRequest $rentalRefundRequest, RentalRefundService $refunds): RedirectResponse
    {
        abort_unless(
            (int) $rentalRefundRequest->organization_id === (int) $request->user()?->organization_id
                && $request->user()?->role?->canAccessBarangayWeb(),
            403,
        );

        $data = $request->validate([
            'action' => ['required', 'in:approve,reject,retry,record_manual,sync'],
            'admin_notes' => ['nullable', 'required_if:action,approve', 'string', 'min:5', 'max:1000'],
            'approved_amount' => ['required_if:action,approve', 'nullable', 'numeric', 'min:1'],
            'confirm' => ['required_if:action,approve', 'accepted_if:action,approve'],
            'rejection_reason' => ['required_if:action,reject', 'nullable', 'string', 'min:5', 'max:1000'],
            'manual_reference' => ['required_if:action,record_manual', 'nullable', 'string', 'min:6', 'max:100'],
        ], [
            'admin_notes.required_if' => 'Add an approval remark.',
            'rejection_reason.required_if' => 'Add the reason for rejecting this refund.',
            'confirm.accepted_if' => 'Confirm the refund before processing.',
        ]);

        if (in_array($data['action'], ['approve', 'reject'], true)) {
            abort_unless($request->user()?->role === UserRole::Administrator, 403);
        }

        if ($data['action'] === 'reject') {
            $refunds->reject($rentalRefundRequest, $request->user(), (string) $data['rejection_reason']);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Refund request rejected. The passenger was notified.']);

            return back();
        }

        $result = match ($data['action']) {
            'approve' => $refunds->approve(
                $rentalRefundRequest,
                $request->user(),
                (int) round(((float) $data['approved_amount']) * 100),
                (string) $data['admin_notes'],
            ),
            'retry' => $refunds->retry($rentalRefundRequest, $request->user()),
            'record_manual' => $refunds->recordManualRefund(
                $rentalRefundRequest,
                $request->user(),
                (string) $data['manual_reference'],
                filled($data['admin_notes'] ?? null) ? $data['admin_notes'] : null,
            ),
            'sync' => $refunds->syncPending($rentalRefundRequest),
        };

        Inertia::flash('toast', [
            'type' => $result->status === RentalRefundRequest::STATUS_FAILED ? 'error' : 'success',
            'message' => match ($result->status) {
                RentalRefundRequest::STATUS_REFUNDED => 'Refund completed. The passenger was notified.',
                RentalRefundRequest::STATUS_REFUNDING => 'Refund sent to Xendit. It will show as Refunded once Xendit confirms.',
                RentalRefundRequest::STATUS_FAILED => 'Xendit could not refund this payment: '.($result->failure_message ?: 'unknown error').'. Retry or record a manual refund.',
                default => 'Refund updated.',
            },
        ]);

        return back();
    }
}
