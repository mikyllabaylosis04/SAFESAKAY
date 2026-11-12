<?php

namespace App\Services;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\UserRole;
use App\Models\BoatBooking;
use App\Models\RentalPayment;
use App\Models\RentalRefundRequest;
use App\Models\User;
use App\Notifications\RentalRefundNotification;
use App\Support\XenditMoney;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Refund rules for paid rentals:
 * - Passenger cancels before trip day: the downpayment is kept; anything paid above it creates a pending
 *   refund request for barangay admin review. Xendit runs only after the admin approves.
 * - On trip day or later, the passenger can no longer cancel a confirmed or paid rental.
 * - A request that is still waiting for operator approval can be cancelled any day.
 * - Weather hold: reschedule only, no refund.
 * - Operator cannot operate, admin cancels, or a verified boat no-show: the passenger is refunded in full or reschedules.
 * Refunds go back to the passenger's GCash through Xendit (test mode); the admin steps in when Xendit fails.
 */
class RentalRefundService
{
    private ?string $lastFailure = null;

    public function __construct(
        private readonly XenditService $xendit,
        private readonly RentalCommissionService $commissions,
        private readonly WeatherSuspensionService $weather,
    ) {}

    /**
     * Paid Xendit payments of a booking (including refunded ones).
     *
     * @return Collection<int, RentalPayment>
     */
    public function xenditPayments(BoatBooking $booking): Collection
    {
        return RentalPayment::query()
            ->where('boat_booking_id', $booking->id)
            ->where('provider', 'xendit')
            ->whereIn('status', ['paid', 'requires_reconciliation'])
            ->orderBy('id')
            ->get();
    }

    /** Online payments not yet refunded, in centavos. */
    public function paidOnline(BoatBooking $booking): int
    {
        return (int) $this->xenditPayments($booking)->sum(fn (RentalPayment $payment) => $this->remaining($payment));
    }

    /** Everything the passenger paid that is still held, in centavos (manual GCash rentals fall back to the downpayment). */
    public function paidTotal(BoatBooking $booking): int
    {
        $online = $this->paidOnline($booking);

        if ($online > 0) {
            return $online;
        }

        return $booking->paymentStatus() === BookingPaymentStatus::Verified
            ? $this->downpayment($booking)
            : 0;
    }

    public function isPaid(BoatBooking $booking): bool
    {
        return $this->paidTotal($booking) > 0;
    }

    /** Policy refund when a passenger cancels: only the part above the non-refundable downpayment. */
    public function passengerCancellationRefund(BoatBooking $booking): int
    {
        return max(0, $this->paidOnline($booking) - $this->downpayment($booking));
    }

    /** Already refunded through Xendit or recorded manually, in centavos. */
    public function refundedTotal(BoatBooking $booking): int
    {
        return (int) $this->xenditPayments($booking)->sum(fn (RentalPayment $payment) => (int) ($payment->refunded_amount ?? 0));
    }

    public function latestRequest(BoatBooking $booking): ?RentalRefundRequest
    {
        return RentalRefundRequest::query()
            ->where('boat_booking_id', $booking->id)
            ->latest('id')
            ->first();
    }

    public function isTestMode(): bool
    {
        return (bool) config('services.xendit.test_mode', str_starts_with((string) config('services.xendit.secret_key'), 'xnd_development'));
    }

    public function paidInFullOnline(BoatBooking $booking): bool
    {
        return $this->paidOnline($booking) > $this->downpayment($booking);
    }

    public function passengerCanCancel(BoatBooking $booking): bool
    {
        if (! $booking->canBeCancelled()) {
            return false;
        }

        if ($booking->status === BoatBookingStatus::Pending) {
            return true;
        }

        $tripDate = $booking->booking_date?->toDateString();

        if (! $tripDate) {
            return false;
        }

        // Cancel is allowed only before the trip date (Manila calendar day).
        return now('Asia/Manila')->toDateString() < $tripDate;
    }

    /**
     * Passenger cancelled a paid rental before trip day: keep the downpayment and open an admin
     * refund request for any amount paid above it. Xendit runs only after admin approval.
     */
    public function handlePassengerCancellation(BoatBooking $booking, User $passenger): ?RentalRefundRequest
    {
        $amount = $this->passengerCancellationRefund($booking);

        if ($amount <= 0) {
            return null;
        }

        $refund = $this->create(
            $booking,
            $passenger,
            RentalRefundRequest::TYPE_PASSENGER_CANCELLATION,
            RentalRefundRequest::STATUS_PENDING_REVIEW,
            $amount,
        );
        $booking->recordActivity('refund_chosen', $passenger, $passenger->name);
        $this->notify($booking, $refund, 'requested');

        return $refund->fresh();
    }

    /**
     * Admin approves a refund request still pending review and sends it through Xendit (test mode only).
     *
     * @throws ValidationException
     */
    public function approve(RentalRefundRequest $refund, User $admin, int $amount, string $remarks): RentalRefundRequest
    {
        $booking = $this->booking($refund);
        $max = $this->paidOnline($booking);

        if ($amount <= 0 || $amount > $max) {
            throw ValidationException::withMessages([
                'approved_amount' => ['Enter an amount from ₱1.00 up to '.$this->peso($max).'.'],
            ]);
        }

        $claimed = DB::transaction(function () use ($refund, $admin, $amount, $remarks): bool {
            $locked = RentalRefundRequest::query()->whereKey($refund->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== RentalRefundRequest::STATUS_PENDING_REVIEW) {
                return false;
            }

            $locked->update([
                'status' => RentalRefundRequest::STATUS_REFUNDING,
                'refund_amount' => $amount,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
                'admin_notes' => mb_substr(trim($remarks), 0, 1000),
            ]);

            return true;
        });

        if (! $claimed) {
            throw ValidationException::withMessages(['refund' => ['This refund request was already reviewed.']]);
        }

        $refund = $refund->fresh();
        $refund->recordEvent('approved', 'Refund approved for '.$this->peso($amount), $remarks, $admin);
        $booking->recordActivity('refund_started', $admin, $admin->name);

        $this->refundOnline($booking, $amount);
        $this->recordProviderReference($refund, $booking);

        return $this->settle($refund->fresh(), $admin);
    }

    /**
     * @throws ValidationException
     */
    public function reject(RentalRefundRequest $refund, User $admin, string $reason): RentalRefundRequest
    {
        $this->assertStatus($refund, [RentalRefundRequest::STATUS_PENDING_REVIEW]);

        $refund->update([
            'status' => RentalRefundRequest::STATUS_REJECTED,
            'rejection_reason' => mb_substr(trim($reason), 0, 1000),
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
        ]);
        $refund->recordEvent('rejected', 'Refund request rejected', $reason, $admin);

        $booking = $this->booking($refund);
        $booking->recordActivity('refund_rejected', $admin, $admin->name);
        $this->notify($booking, $refund->fresh(), 'rejected');

        return $refund->fresh();
    }

    /** Operator or admin cancelled a paid rental: the passenger picks a full refund or a new date. */
    public function openForCancellation(BoatBooking $booking, ?User $actor, string $type): ?RentalRefundRequest
    {
        if (! $this->isPaid($booking)) {
            return null;
        }

        $latest = $this->latestRequest($booking);
        if ($latest && $latest->status === RentalRefundRequest::STATUS_AWAITING_CHOICE) {
            return $latest;
        }

        $refund = $this->create($booking, $actor, $type, RentalRefundRequest::STATUS_AWAITING_CHOICE, $this->paidTotal($booking));
        $this->notify($booking, $refund, 'choose');

        return $refund;
    }

    /**
     * A reversed passenger no-show is not a reschedule. Admin already accepted the dispute;
     * the refund stays pending until admin processes it. The amount is whatever was paid:
     * downpayment only, or the full payment when that was paid.
     */
    public function openReversedPassengerNoShowRefund(BoatBooking $booking, User $admin): ?RentalRefundRequest
    {
        $amount = $this->paidTotal($booking);

        if ($amount <= 0) {
            return null;
        }

        $latest = $this->latestRequest($booking);
        if ($latest && ! $latest->isClosed()) {
            return $this->moveReversedNoShowToAdminReview($latest, $booking);
        }

        $refund = $this->create(
            $booking,
            $admin,
            RentalRefundRequest::TYPE_ADMIN_CANCELLED,
            RentalRefundRequest::STATUS_PENDING_REVIEW,
            $amount,
        );
        $refund->recordEvent('requested', 'Refund waiting for admin to process', 'Only the amount the passenger paid is refunded. There is no reschedule.', $admin);
        $this->notify($booking, $refund, 'requested');

        return $refund->fresh();
    }

    /** Older reversed no-shows were left waiting for a passenger choice. Move those to admin processing. */
    public function promoteReversedPassengerNoShowRefunds(?int $organizationId = null): void
    {
        RentalRefundRequest::query()
            ->with('booking')
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->where('status', RentalRefundRequest::STATUS_AWAITING_CHOICE)
            ->whereHas('booking', fn ($query) => $query->where('cancellation_reason', 'Passenger no-show reversed by barangay'))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(function (RentalRefundRequest $refund): void {
                if ($refund->booking) {
                    $this->moveReversedNoShowToAdminReview($refund, $refund->booking);
                }
            });
    }

    public function canChoose(BoatBooking $booking, ?User $user): bool
    {
        if ($booking->cancellation_reason === 'Passenger no-show reversed by barangay') {
            return false;
        }

        $latest = $this->latestRequest($booking);

        return $user !== null
            && (int) $booking->user_id === (int) $user->id
            && $latest !== null
            && $latest->isNotPassengersFault()
            && in_array($latest->status, [RentalRefundRequest::STATUS_AWAITING_CHOICE, RentalRefundRequest::STATUS_RESCHEDULED], true)
            && in_array($booking->status, [BoatBookingStatus::Cancelled, BoatBookingStatus::WeatherHold], true);
    }

    public function chooseRefund(BoatBooking $booking, User $passenger): RentalRefundRequest
    {
        $refund = $this->assertCanChoose($booking, $passenger);

        if ($booking->status !== BoatBookingStatus::Cancelled) {
            $booking->update([
                'status' => BoatBookingStatus::Cancelled,
                'cancelled_at' => $booking->cancelled_at ?? now(),
            ]);
        }

        $refund->update([
            'status' => RentalRefundRequest::STATUS_REFUNDING,
            'refund_amount' => $this->paidTotal($booking),
        ]);
        $booking->recordActivity('refund_chosen', $passenger, $passenger->name);

        if ($this->paidOnline($booking) <= 0) {
            $refund->update([
                'status' => RentalRefundRequest::STATUS_FAILED,
                'failure_message' => 'Paid by manual GCash proof. Send the refund by GCash, then record it here.',
            ]);
            $this->notify($booking, $refund, 'failed');

            return $refund->fresh();
        }

        $refund->recordEvent('approved', 'Passenger chose a full refund', null, $passenger);
        $this->refundOnline($booking, $this->paidOnline($booking));
        $this->recordProviderReference($refund->fresh(), $booking);

        return $this->settle($refund->fresh(), $passenger);
    }

    public function chooseReschedule(BoatBooking $booking, User $passenger, string $date): RentalRefundRequest
    {
        $refund = $this->assertCanChoose($booking, $passenger);

        DB::transaction(function () use ($booking, $passenger, $date, $refund): void {
            if ($booking->status === BoatBookingStatus::Cancelled) {
                // Reuse the weather reschedule review so the operator/admin approves the new date the usual way.
                $booking->update(['status' => BoatBookingStatus::WeatherHold]);
            }

            $this->weather->requestReschedule($booking->fresh(), $passenger, $date);
            $refund->update(['status' => RentalRefundRequest::STATUS_RESCHEDULED]);
        });

        $booking->recordActivity('refund_reschedule_chosen', $passenger, $passenger->name);
        $this->notify($booking, $refund->fresh(), 'rescheduled');

        return $refund->fresh();
    }

    /**
     * Admin-approved refund for a verified operator/boat no-show (service not delivered).
     * Returns null when the rental was not paid online, so the manual GCash flow applies.
     */
    public function refundForOperatorNoShow(BoatBooking $booking, User $admin): ?RentalRefundRequest
    {
        $online = $this->paidOnline($booking);

        if ($online <= 0) {
            return null;
        }

        $existing = RentalRefundRequest::query()
            ->where('boat_booking_id', $booking->id)
            ->where('type', RentalRefundRequest::TYPE_OPERATOR_NO_SHOW)
            ->whereIn('status', [RentalRefundRequest::STATUS_REFUNDING, RentalRefundRequest::STATUS_REFUNDED, RentalRefundRequest::STATUS_FAILED])
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'refund' => ['A refund was already started for this rental.'],
            ]);
        }

        $refund = $this->create($booking, $admin, RentalRefundRequest::TYPE_OPERATOR_NO_SHOW, RentalRefundRequest::STATUS_REFUNDING, $online);
        $this->review($refund, $admin, null);
        $booking->recordActivity('refund_started', $admin, $admin->name);

        $refund->recordEvent('approved', 'Refund approved for verified boat no-show', null, $admin);
        $this->refundOnline($booking, $online);
        $this->recordProviderReference($refund->fresh(), $booking);

        return $this->settle($refund->fresh(), $admin);
    }

    /** Admin retries a failed Xendit refund. */
    public function retry(RentalRefundRequest $refund, User $admin): RentalRefundRequest
    {
        $this->assertStatus($refund, [RentalRefundRequest::STATUS_FAILED]);
        $booking = $this->booking($refund);
        $this->review($refund, $admin, null);

        $owed = $this->owed($refund, $booking);
        if ($owed <= 0 || $this->xenditPayments($booking)->isEmpty()) {
            throw ValidationException::withMessages([
                'refund' => ['Nothing can be refunded through Xendit for this rental. Record a manual refund instead.'],
            ]);
        }

        $this->xenditPayments($booking)
            ->filter(fn (RentalPayment $payment) => $payment->refund_status === 'failed')
            ->each(fn (RentalPayment $payment) => $payment->update(['refund_status' => null]));

        $refund->recordEvent('retried', 'Admin retried the Xendit refund', null, $admin);
        $this->refundOnline($booking, $owed);
        $this->recordProviderReference($refund->fresh(), $booking);

        return $this->settle($refund->fresh(), $admin);
    }

    /** Admin already sent the money back by GCash themselves. */
    public function recordManualRefund(RentalRefundRequest $refund, User $admin, string $reference, ?string $notes = null): RentalRefundRequest
    {
        $this->assertStatus($refund, [RentalRefundRequest::STATUS_FAILED, RentalRefundRequest::STATUS_REFUNDING]);
        $booking = $this->booking($refund);
        $this->review($refund, $admin, $notes);

        $owed = $this->owed($refund, $booking);
        foreach ($this->xenditPayments($booking) as $payment) {
            if ($owed <= 0) {
                break;
            }

            $take = min($owed, $this->remaining($payment));
            if ($take <= 0) {
                continue;
            }

            $payment->update([
                'refund_status' => 'manual',
                'refunded_amount' => (int) ($payment->refunded_amount ?? 0) + $take,
                'refunded_at' => now(),
            ]);
            $owed -= $take;
        }

        $refund->update(['manual_reference' => trim($reference), 'failure_message' => null]);
        $refund->recordEvent('manual', 'Manual refund recorded', 'Reference: '.trim($reference), $admin);

        return $this->markRefunded($refund->fresh(), $admin);
    }

    /** Re-check refunds Xendit is still processing (when the webhook has not arrived yet). */
    public function syncPending(RentalRefundRequest $refund): RentalRefundRequest
    {
        if ($refund->status !== RentalRefundRequest::STATUS_REFUNDING) {
            return $refund;
        }

        foreach ($this->xenditPayments($this->booking($refund)) as $payment) {
            if ($payment->refund_status !== 'pending' || ! filled($payment->refund_id)) {
                continue;
            }

            try {
                $this->applyRefundStatus($payment, $this->xendit->getRefund((string) $payment->refund_id));
            } catch (Throwable $exception) {
                Log::warning('xendit.refund.sync_failed', ['rental_payment_id' => $payment->id, 'message' => $exception->getMessage()]);
            }
        }

        return $this->settle($refund->fresh(), null);
    }

    /**
     * Xendit refund.succeeded / refund.failed webhook.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyWebhook(array $data): bool
    {
        $refundId = (string) ($data['id'] ?? '');
        $reference = (string) ($data['reference_id'] ?? '');

        $payment = RentalPayment::query()
            ->where('provider', 'xendit')
            ->where(function ($query) use ($refundId, $reference) {
                $query->where('refund_id', $refundId);

                if (preg_match('/^RFD-(\d+)-/', $reference, $match) === 1) {
                    $query->orWhere('id', (int) $match[1]);
                }
            })
            ->first();

        if (! $payment) {
            return false;
        }

        $this->applyRefundStatus($payment, $data);

        $booking = BoatBooking::withoutGlobalScope('organization')->find($payment->boat_booking_id);
        $refund = $booking ? $this->latestRequest($booking) : null;

        if ($refund && in_array($refund->status, [RentalRefundRequest::STATUS_REFUNDING, RentalRefundRequest::STATUS_FAILED], true)) {
            $this->settle($refund, null);
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function adminPayload(?RentalRefundRequest $refund): ?array
    {
        if (! $refund) {
            return null;
        }

        $refund->loadMissing(['requester', 'reviewer', 'events']);
        $booking = $this->booking($refund);
        $booking->loadMissing(['boat.operator', 'operatorNoShowReports', 'passengerNoShowReport']);
        $payments = $this->xenditPayments($booking);
        $paidTotal = (int) $payments->sum('amount');
        $refunded = $this->refundedTotal($booking);
        $maxRefundable = $this->paidOnline($booking);
        $policy = $refund->type === RentalRefundRequest::TYPE_PASSENGER_CANCELLATION
            ? max(0, $maxRefundable - $this->downpayment($booking))
            : $maxRefundable;
        $pending = $refund->status === RentalRefundRequest::STATUS_PENDING_REVIEW;
        $rental = (int) round((float) ($booking->rental_price ?? 0) * 100);
        $incident = $booking->operatorNoShowReports->sortByDesc('id')->first();

        return [
            'id' => $refund->id,
            'reference' => $refund->displayReference(),
            'type' => $refund->type,
            'type_label' => $refund->typeLabel(),
            'status' => $refund->status,
            'status_label' => $refund->statusLabel(),
            'status_badge_class' => $refund->statusBadgeClass(),
            'reason' => $refund->reason,
            'reason_label' => $refund->reasonLabel(),
            'details' => $refund->details,
            'refund_amount' => $refund->refund_amount,
            'refund_amount_label' => $refund->refundAmountLabel(),
            'requested_amount_label' => $this->peso((int) ($refund->requested_amount ?? $refund->refund_amount ?? $maxRefundable)),
            'requested_by' => $refund->requester?->name,
            'requested_at' => $refund->created_at?->toIso8601String(),
            'requested_at_label' => $this->label($refund->created_at),
            'reviewed_by' => $refund->reviewer?->name,
            'reviewed_at_label' => $this->label($refund->reviewed_at),
            'refunded_at_label' => $this->label($refund->refunded_at),
            'processed_at_label' => $this->label($refund->refunded_at ?? $refund->reviewed_at),
            'admin_notes' => $refund->admin_notes,
            'rejection_reason' => $refund->rejection_reason,
            'failure_message' => $refund->failure_message,
            'manual_reference' => $refund->manual_reference,
            'provider_refund_id' => $refund->provider_refund_id ?: $payments->pluck('refund_id')->filter()->implode(', ') ?: null,
            'refund_method_label' => $refund->manual_reference ? 'Manual GCash transfer' : 'Original Xendit payment channel (GCash)',
            'is_test_mode' => $this->isTestMode(),
            'booking' => [
                'id' => $booking->id,
                'code' => $booking->code,
                'href' => '/bookings/requests/'.$booking->id,
                'passenger_name' => $booking->customer_name,
                'boat_name' => $booking->boat?->name,
                'operator_name' => $booking->boat?->operator?->full_name,
                'date_label' => trim($booking->booking_date?->format('M d, Y').' '.($booking->start_time ? \Illuminate\Support\Carbon::parse($booking->start_time)->format('g:i A') : '')),
                'pickup' => $booking->pickup_point,
                'destination' => $booking->destination ?: $booking->package,
                'status_label' => $booking->status?->label(),
                'incident_label' => $booking->passengerNoShowReport
                    ? 'Passenger no-show · '.$booking->passengerNoShowReport->statusLabel()
                    : ($incident ? 'Boat / operator no-show · '.$incident->incidentDisplayStatus()->label() : ($booking->cancellation_reason ? 'Cancelled: '.$booking->cancellation_reason : null)),
            ],
            'payment' => [
                'rental_price_label' => $this->peso($rental),
                'payment_type_label' => $payments->contains('payment_type', 'full_rental') ? 'Full Payment' : ($payments->contains('payment_type', 'full_payment') ? 'Downpayment + Balance' : 'Downpayment'),
                'amount_paid' => $paidTotal,
                'amount_paid_label' => $this->peso($paidTotal),
                'remaining_balance_label' => $this->peso(max(0, (int) round((float) ($booking->remaining_balance ?? 0) * 100))),
                'refunded_label' => $this->peso($refunded),
                'status_label' => $paidTotal > 0 && $refunded >= $paidTotal ? 'Refunded' : ($refunded > 0 ? 'Partially Refunded' : 'Paid'),
                'max_refundable' => $maxRefundable,
                'max_refundable_label' => $this->peso($maxRefundable),
                'policy_refund' => $policy,
                'policy_refund_label' => $this->peso($policy),
                'policy_note' => $refund->type === RentalRefundRequest::TYPE_PASSENGER_CANCELLATION
                    ? 'Passenger cancelled, so the downpayment ('.$this->peso($maxRefundable - $policy).') is kept. Refund more only for a proven emergency.'
                    : 'The passenger is not at fault, so the full amount paid is refunded.',
                'payment_references' => $payments->map(fn (RentalPayment $payment) => $payment->paymentRequestId() ?? $payment->provider_payment_id)->filter()->values()->all(),
            ],
            'timeline' => $refund->events->map(fn ($event) => [
                'title' => trim(str_ireplace('(test mode)', '', (string) $event->title)),
                'detail' => $event->detail,
                'actor' => $event->actor_name,
                'at_label' => $this->label($event->created_at),
            ])->values()->all(),
            'can_approve' => $pending && $maxRefundable > 0,
            'can_reject' => $pending,
            'can_retry' => $refund->status === RentalRefundRequest::STATUS_FAILED && $payments->isNotEmpty(),
            'can_record_manual' => in_array($refund->status, [RentalRefundRequest::STATUS_FAILED, RentalRefundRequest::STATUS_REFUNDING], true),
            'can_sync' => $refund->status === RentalRefundRequest::STATUS_REFUNDING,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mobilePayload(BoatBooking $booking, ?User $viewer): array
    {
        $this->promoteReversedPassengerNoShowRefunds((int) $booking->organization_id);
        $refund = $this->latestRequest($booking);
        $isPassenger = $viewer && ! $viewer->isMobileOperator();
        $cancelRefund = $this->passengerCancellationRefund($booking);
        $owner = $isPassenger && (int) $booking->user_id === (int) $viewer?->id;

        return [
            'is_paid_rental' => $this->isPaid($booking),
            'is_paid_in_full' => $this->paidInFullOnline($booking),
            'cancel_refund_amount_label' => $cancelRefund > 0 ? $this->peso($cancelRefund) : null,
            'paid_online_label' => $this->peso($this->paidOnline($booking)),
            'can_passenger_cancel' => $owner && $this->passengerCanCancel($booking),
            'can_choose_refund_or_reschedule' => $isPassenger && $this->canChoose($booking, $viewer),
            'refund_request' => $refund ? [
                'id' => $refund->id,
                'reference' => $refund->displayReference(),
                'type' => $refund->type,
                'type_label' => $refund->typeLabel(),
                'status' => $refund->status,
                'status_label' => $refund->statusLabel(),
                'reason_label' => $refund->reasonLabel(),
                'refund_amount_label' => $refund->refundAmountLabel(),
                'requested_amount_label' => $refund->requested_amount !== null ? $this->peso((int) $refund->requested_amount) : null,
                'rejection_reason' => $refund->rejection_reason,
                'requested_at_label' => $this->label($refund->created_at),
                'refunded_at_label' => $this->label($refund->refunded_at),
            ] : null,
        ];
    }

    private function moveReversedNoShowToAdminReview(RentalRefundRequest $refund, BoatBooking $booking): RentalRefundRequest
    {
        $amount = $this->paidTotal($booking);

        if ($refund->status === RentalRefundRequest::STATUS_PENDING_REVIEW && (int) $refund->requested_amount === $amount) {
            return $refund;
        }

        $refund->update([
            'status' => RentalRefundRequest::STATUS_PENDING_REVIEW,
            'requested_amount' => $amount,
            'refund_amount' => $amount,
            'amount_paid' => $amount,
        ]);

        return $refund->fresh();
    }

    private function create(BoatBooking $booking, ?User $actor, string $type, string $status, int $amount): RentalRefundRequest
    {
        $refund = RentalRefundRequest::query()->create([
            'organization_id' => $booking->organization_id,
            'boat_booking_id' => $booking->id,
            'requested_by_user_id' => $actor?->id,
            'type' => $type,
            'status' => $status,
            'reason' => filled($booking->cancellation_reason) ? mb_substr(trim((string) $booking->cancellation_reason), 0, 1000) : null,
            'refund_amount' => $amount,
            'requested_amount' => $amount,
            'amount_paid' => $this->paidTotal($booking),
        ]);
        $refund->recordEvent('opened', 'Refund case opened', $refund->typeLabel(), $actor);

        return $refund;
    }

    private function recordProviderReference(RentalRefundRequest $refund, BoatBooking $booking): void
    {
        $ids = $this->xenditPayments($booking)->pluck('refund_id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $refund->update(['provider_refund_id' => mb_substr($ids->implode(', '), 0, 80)]);
        $refund->recordEvent('xendit_initiated', 'Xendit refund initiated', 'Refund ID: '.$ids->implode(', '), null, 'Xendit');
    }

    /** Refund up to $amount across the booking's payments, balance payments first. */
    private function refundOnline(BoatBooking $booking, int $amount): void
    {
        $payments = $this->xenditPayments($booking)
            ->filter(fn (RentalPayment $payment) => $this->remaining($payment) > 0 && $payment->refund_status !== 'pending')
            ->sortBy(fn (RentalPayment $payment) => [$payment->payment_type === 'full_payment' ? 0 : 1, -$payment->id]);

        foreach ($payments as $payment) {
            if ($amount <= 0) {
                break;
            }

            $take = min($amount, $this->remaining($payment));
            $this->refundPayment($booking, $payment, $take);
            $amount -= $take;
        }
    }

    private function refundPayment(BoatBooking $booking, RentalPayment $payment, int $amount): void
    {
        $paymentRequestId = $payment->paymentRequestId();

        if ($paymentRequestId === null) {
            $payment->update(['refund_status' => 'failed']);
            $this->lastFailure = 'The original Xendit payment request was not recorded for this payment.';

            return;
        }

        try {
            $body = $this->xendit->createRefund(
                $paymentRequestId,
                'RFD-'.$payment->id.'-'.((int) ($payment->refunded_amount ?? 0) + $amount),
                $amount,
            );
            $payment->update(['refund_id' => is_string($body['id'] ?? null) ? $body['id'] : null]);
            $this->applyRefundStatus($payment->fresh(), $body + ['amount' => $amount / 100]);
        } catch (XenditGatewayException $exception) {
            $payment->update(['refund_status' => 'failed']);
            Log::warning('xendit.refund.failed', [
                'booking_reference' => $booking->code,
                'rental_payment_id' => $payment->id,
                'http_status' => $exception->httpStatus(),
                'error_code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ]);
            $this->lastFailure = trim(($exception->errorCode() ? $exception->errorCode().': ' : '').$exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function applyRefundStatus(RentalPayment $payment, array $body): void
    {
        $status = strtoupper((string) ($body['status'] ?? ''));

        if ($status === 'SUCCEEDED') {
            if ($payment->refund_status === 'succeeded') {
                return;
            }

            $amount = isset($body['amount']) ? XenditMoney::toMinorFromApiAmount($body['amount']) : $this->remaining($payment);
            $payment->update([
                'refund_status' => 'succeeded',
                'refunded_amount' => min((int) $payment->amount, (int) ($payment->refunded_amount ?? 0) + $amount),
                'refunded_at' => now(),
            ]);

            return;
        }

        if (in_array($status, ['FAILED', 'CANCELLED'], true)) {
            $payment->update(['refund_status' => 'failed']);
            $this->lastFailure = filled($body['failure_code'] ?? null) ? (string) $body['failure_code'] : 'Xendit could not complete the refund.';

            return;
        }

        $payment->update(['refund_status' => 'pending']);
    }

    private function settle(RentalRefundRequest $refund, ?User $actor): RentalRefundRequest
    {
        $booking = $this->booking($refund);
        $payments = $this->xenditPayments($booking);

        if ($payments->contains(fn (RentalPayment $payment) => $payment->refund_status === 'failed')) {
            $message = mb_substr($this->lastFailure ?? 'Xendit could not complete the refund.', 0, 500);
            if ($refund->status !== RentalRefundRequest::STATUS_FAILED) {
                $refund->recordEvent('failed', 'Refund failed', $message, null, 'Xendit');
            }
            $refund->update([
                'status' => RentalRefundRequest::STATUS_FAILED,
                'failure_message' => $message,
            ]);
            $this->notify($booking, $refund, 'failed');

            return $refund->fresh();
        }

        if ($payments->contains(fn (RentalPayment $payment) => $payment->refund_status === 'pending')) {
            if ($refund->status !== RentalRefundRequest::STATUS_REFUNDING) {
                $refund->update(['status' => RentalRefundRequest::STATUS_REFUNDING, 'failure_message' => null]);
            }
            if (! $refund->events()->where('event', 'processing')->exists()) {
                $refund->recordEvent('processing', 'Refund processing at Xendit', 'Waiting for Xendit to confirm the refund.', null, 'Xendit');
            }

            return $refund->fresh();
        }

        return $this->markRefunded($refund, $actor);
    }

    private function markRefunded(RentalRefundRequest $refund, ?User $actor): RentalRefundRequest
    {
        if ($refund->status === RentalRefundRequest::STATUS_REFUNDED) {
            return $refund;
        }

        $booking = $this->booking($refund);

        DB::transaction(function () use ($refund, $booking, $actor): void {
            $refund->update([
                'status' => RentalRefundRequest::STATUS_REFUNDED,
                'refunded_at' => now(),
                'failure_message' => null,
            ]);

            $refund->recordEvent('succeeded', 'Refund succeeded', $refund->refundAmountLabel(), $actor, $actor?->name ?? 'Xendit');

            // A partial passenger refund keeps the downpayment (and its commission); a full refund reverses it.
            if ($refund->isNotPassengersFault() || $this->paidOnline($booking) <= 0) {
                $booking->loadMissing('commission');
                if ($booking->commission) {
                    $this->commissions->reverseCommissionForRefund($booking->commission, $actor);
                }
            }

            $booking->recordActivity('refund_completed', $actor, $actor?->name ?? 'Xendit');
        });

        if ($refund->type === RentalRefundRequest::TYPE_OPERATOR_NO_SHOW) {
            app(OperatorNoShowService::class)->completeLinkedRefund($refund->fresh(), $actor);
        }

        $this->notify($booking, $refund, 'refunded');

        return $refund->fresh();
    }

    /** Amount of this request that has not been refunded yet. */
    private function owed(RentalRefundRequest $refund, BoatBooking $booking): int
    {
        $requested = (int) ($refund->refund_amount ?? 0);
        $online = $this->paidOnline($booking);

        return $online > 0 ? min($requested, $online) : $requested;
    }

    private function remaining(RentalPayment $payment): int
    {
        return max(0, (int) $payment->amount - (int) ($payment->refunded_amount ?? 0));
    }

    private function downpayment(BoatBooking $booking): int
    {
        return (int) round((float) ($booking->down_payment_amount ?? 0) * 100);
    }

    private function assertCanChoose(BoatBooking $booking, User $passenger): RentalRefundRequest
    {
        if (! $this->canChoose($booking, $passenger)) {
            throw ValidationException::withMessages([
                'booking' => ['This rental has no refund or reschedule option right now.'],
            ]);
        }

        return $this->latestRequest($booking);
    }

    private function review(RentalRefundRequest $refund, User $admin, ?string $notes): void
    {
        $refund->update([
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
            'admin_notes' => filled($notes) ? mb_substr(trim((string) $notes), 0, 1000) : $refund->admin_notes,
        ]);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(RentalRefundRequest $refund, array $allowed): void
    {
        if (! in_array($refund->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'refund' => ['This refund is already '.strtolower($refund->statusLabel()).'.'],
            ]);
        }
    }

    private function booking(RentalRefundRequest $refund): BoatBooking
    {
        return BoatBooking::withoutGlobalScope('organization')->findOrFail($refund->boat_booking_id);
    }

    private function label(?\DateTimeInterface $at): ?string
    {
        return $at ? \Illuminate\Support\Carbon::instance($at)->timezone('Asia/Manila')->format('M d, Y · g:i A') : null;
    }

    private function peso(int $centavos): string
    {
        return '₱'.number_format($centavos / 100, 2);
    }

    private function notify(BoatBooking $booking, RentalRefundRequest $refund, string $event): void
    {
        $recipients = collect();

        if (! in_array($event, ['failed', 'requested'], true) && $booking->user_id) {
            $recipients->push(User::query()->find($booking->user_id));
        }

        if (in_array($event, ['failed', 'rescheduled', 'requested'], true)) {
            $recipients = $recipients->merge(
                User::query()
                    ->where('organization_id', $booking->organization_id)
                    ->whereIn('role', [UserRole::Administrator, UserRole::User])
                    ->where('is_active', true)
                    ->get(),
            );
        }

        $recipients->filter()->unique('id')->each(function (User $user) use ($booking, $refund, $event): void {
            try {
                $user->notify(new RentalRefundNotification($booking, $refund, $event));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
