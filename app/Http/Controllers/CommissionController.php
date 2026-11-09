<?php

namespace App\Http\Controllers;

use App\Enums\CommissionSettlementMethod;
use App\Enums\CommissionSettlementStatus;
use App\Enums\OperatorPayoutStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\Operator;
use App\Models\OrganizationRoute;
use App\Models\RentalCommission;
use App\Services\RentalCommissionService;
use App\Support\RentalCommissionPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommissionController extends Controller
{
    public function index(RentalCommissionService $commissions): Response
    {
        $commissions->syncFullRentalPayments((int) auth()->user()?->organization_id);
        $commissions->backfillMissingCommissions((int) auth()->user()?->organization_id);

        $records = RentalCommission::query()
            ->with(['booking.boat', 'operator', 'verifier', 'payoutRecorder', 'events.actor'])
            ->latest('id')
            ->get();

        $records->each(fn (RentalCommission $commission) => $commissions->reconcileOperatorPayoutStatus($commission));

        $presented = $records
            ->map(fn (RentalCommission $commission) => RentalCommissionPresenter::forAdmin($commission->fresh()));

        $boats = Boat::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Boat $boat) => ['id' => $boat->id, 'name' => $boat->name])
            ->values();

        return Inertia::render('commissions/Index', [
            'commissions' => $presented,
            'boats' => $boats,
            'stats' => $this->stats($presented),
            'can_manage' => auth()->user()?->role === UserRole::Administrator,
        ]);
    }

    public function recordOperatorPayout(
        Request $request,
        RentalCommission $rentalCommission,
        RentalCommissionService $commissions,
    ): RedirectResponse {
        $this->authorizeCommission($rentalCommission);
        abort_unless($request->user()?->role === UserRole::Administrator, 403);

        $data = $request->validate([
            'payment_method' => ['nullable', 'in:cash,gcash'],
            'gcash_reference' => ['nullable', 'required_if:payment_method,gcash', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'confirm_payout' => ['accepted'],
        ], [
            'gcash_reference.required_if' => 'Enter the GCash reference number of the transfer.',
        ]);

        $gcash = ($data['payment_method'] ?? 'cash') === 'gcash';

        $commissions->recordOperatorPayout(
            $rentalCommission,
            $request->user(),
            $gcash ? CommissionSettlementMethod::Gcash : CommissionSettlementMethod::Cash,
            $gcash ? trim((string) $data['gcash_reference']) : null,
            $data['remarks'] ?? null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Operator payout recorded as '.($gcash ? 'GCash' : 'Cash').'. Status updated to Paid Out.',
        ]);

        return back();
    }

    public function verify(Request $request, RentalCommission $rentalCommission, RentalCommissionService $commissions): RedirectResponse
    {
        $this->authorizeCommission($rentalCommission);

        $commissions->verify($rentalCommission, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Commission settlement marked as paid.']);

        return back();
    }

    public function reject(Request $request, RentalCommission $rentalCommission, RentalCommissionService $commissions): RedirectResponse
    {
        $this->authorizeCommission($rentalCommission);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $commissions->reject($rentalCommission, $request->user(), trim($data['reason']));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Settlement rejected. The operator can resubmit.']);

        return back();
    }

    public function recordCash(Request $request, RentalCommission $rentalCommission, RentalCommissionService $commissions): RedirectResponse
    {
        $this->authorizeCommission($rentalCommission);
        abort_unless($request->user()?->role === UserRole::Administrator, 403);

        $data = $request->validate([
            'received_at' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'confirm_received' => ['accepted'],
        ]);

        $updated = $commissions->recordCashPayment(
            $rentalCommission,
            $request->user(),
            Carbon::parse($data['received_at']),
            $data['remarks'] ?? null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Cash commission recorded. Receipt '.$updated->cash_receipt_number.'.',
        ]);

        return back();
    }

    public function followUp(Request $request, RentalCommission $rentalCommission, RentalCommissionService $commissions): RedirectResponse
    {
        $this->authorizeCommission($rentalCommission);
        abort_unless($request->user()?->role === UserRole::Administrator, 403);

        $commissions->sendAdminFollowUp($rentalCommission, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Follow-up notice sent to the operator.']);

        return back();
    }

    public function restrictOperator(Request $request, Operator $operator): RedirectResponse
    {
        abort_unless($request->user()?->role === UserRole::Administrator, 403);
        $operator->loadMissing('boat');
        abort_unless((int) $operator->boat?->organization_id === (int) $request->user()?->organization_id, 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'notice_given' => ['accepted'],
        ]);

        $operator->update([
            'exclusive_rental_restricted_at' => now(),
            'exclusive_rental_restricted_by' => $request->user()->id,
            'exclusive_rental_restriction_reason' => trim($data['reason']),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Operator restricted from new exclusive rental assignments. Existing bookings are not affected.',
        ]);

        return back();
    }

    public function liftOperatorRestriction(Request $request, Operator $operator): RedirectResponse
    {
        abort_unless($request->user()?->role === UserRole::Administrator, 403);
        $operator->loadMissing('boat');
        abort_unless((int) $operator->boat?->organization_id === (int) $request->user()?->organization_id, 403);

        $operator->update([
            'exclusive_rental_restricted_at' => null,
            'exclusive_rental_restricted_by' => null,
            'exclusive_rental_restriction_reason' => null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Exclusive rental restriction lifted.']);

        return back();
    }

    public function export(Request $request): StreamedResponse
    {
        $from = $this->validDate($request->query('from'));
        $to = $this->validDate($request->query('to'));
        $status = (string) $request->query('status', 'all');
        $method = (string) $request->query('method', 'all');
        $search = strtolower(trim((string) $request->query('search', '')));

        $rows = RentalCommission::query()
            ->with(['booking.boat', 'operator', 'verifier'])
            ->latest('id')
            ->get()
            ->map(fn (RentalCommission $commission) => RentalCommissionPresenter::forAdmin($commission))
            ->filter(function (array $row) use ($from, $to, $status, $method, $search): bool {
                return $this->matchesExportFilters($row, $from, $to, $status, $method, $search);
            })
            ->values();

        $filename = 'commission-records-'.now()->format('Y-m-d').'.csv';
        $headers = [
            'Booking Reference',
            'Operator',
            'Rental Date',
            'Commission',
            'Payment Method',
            'Due Date',
            'Days Pending',
            'Status',
            'GCash Reference',
            'Cash Receipt',
            'Verified By',
            'Verified At',
        ];

        return response()->streamDownload(function () use ($rows, $headers): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\u{FEFF}");
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['code'] ?? '',
                    $row['operator_name'] ?? '',
                    $row['rental_date_label'] ?? '',
                    $row['commission_amount'] ?? '',
                    $row['payment_method_label'] ?? '',
                    $row['settlement_due_label'] ?? '',
                    $row['days_pending'] ?? '',
                    $row['status_label'] ?? '',
                    $row['gcash_reference'] ?? '',
                    $row['cash_receipt_number'] ?? '',
                    $row['verified_by'] ?? '',
                    $row['verified_at_label'] ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function proof(RentalCommission $rentalCommission): StreamedResponse
    {
        $this->authorizeCommission($rentalCommission);

        $path = $rentalCommission->settlement_proof_path;

        if (! filled($path)) {
            abort(404);
        }

        foreach (['public', 'local'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->response($path);
            }
        }

        abort(404);
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function authorizeCommission(RentalCommission $commission): void
    {
        abort_unless(
            (int) $commission->organization_id === (int) auth()->user()?->organization_id,
            403,
        );
        abort_unless(auth()->user()?->role?->canAccessBarangayWeb(), 403);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $records
     * @return array{
     *     total_commission: string,
     *     total_commission_raw: float,
     *     pending_payouts: string,
     *     pending_payouts_raw: float,
     *     payouts_released: string,
     *     payouts_released_raw: float,
     *     refunded: string,
     *     refunded_raw: float
     * }
     */
    private function stats($records): array
    {
        $earned = $records->filter(fn ($row) => ! ($row['commission_reversed'] ?? false));
        $pending = $records->where('payout_status', OperatorPayoutStatus::ReadyForPayout->value);
        $released = $records->filter(fn ($row) => in_array($row['payout_status'] ?? '', [
            OperatorPayoutStatus::PaidOut->value,
            'paid_out',
        ], true));
        $refunded = $records->filter(fn ($row) => ($row['payout_status'] ?? '') === 'refunded'
            || ($row['commission_reversed'] ?? false) === true);

        return [
            'total_commission' => OrganizationRoute::formatPeso($earned->sum('commission_amount_raw')),
            'total_commission_raw' => (float) $earned->sum('commission_amount_raw'),
            'pending_payouts' => OrganizationRoute::formatPeso($pending->sum('operator_net_raw')),
            'pending_payouts_raw' => (float) $pending->sum('operator_net_raw'),
            'payouts_released' => OrganizationRoute::formatPeso($released->sum('operator_net_raw')),
            'payouts_released_raw' => (float) $released->sum('operator_net_raw'),
            'refunded' => OrganizationRoute::formatPeso($refunded->sum('commission_amount_raw')),
            'refunded_raw' => (float) $refunded->sum('commission_amount_raw'),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matchesExportFilters(array $row, ?string $from, ?string $to, string $status, string $method, string $search): bool
    {
        $rentalDate = $row['rental_date'] ?? '';

        if ($from && $rentalDate !== '' && $rentalDate < $from) {
            return false;
        }

        if ($to && $rentalDate !== '' && $rentalDate > $to) {
            return false;
        }

        if ($status === 'received') {
            if (! in_array($row['status'] ?? '', ['paid', 'collected_with_rental'], true)) {
                return false;
            }
        } elseif ($status !== 'all' && ($row['status'] ?? '') !== $status) {
            return false;
        }

        if ($method === 'gcash' && ($row['payment_method'] ?? '') !== 'gcash') {
            return false;
        }

        if ($method === 'cash' && ($row['payment_method'] ?? '') !== 'cash') {
            return false;
        }

        if ($search !== '') {
            $haystack = strtolower(trim(
                ($row['code'] ?? '').' '.($row['boat_name'] ?? '').' '.($row['operator_name'] ?? '').' '.($row['gcash_reference'] ?? ''),
            ));

            if (! str_contains($haystack, $search)) {
                return false;
            }
        }

        return true;
    }
}
