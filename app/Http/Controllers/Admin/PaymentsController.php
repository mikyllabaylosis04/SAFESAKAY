<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\RentalRefundRequest;
use App\Services\RentalRefundService;
use App\Support\AdminFinanceLedger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentsController extends Controller
{
    public function index(Request $request, AdminFinanceLedger $finance): Response
    {
        $organizationId = (int) $request->user()?->organization_id;
        abort_unless($organizationId, 404);

        $rows = $finance->paymentRows($organizationId);
        $refunds = app(RentalRefundService::class);

        // Local servers never receive Xendit's refund webhook, so ask Xendit directly.
        RentalRefundRequest::query()
            ->where('organization_id', $organizationId)
            ->where('status', RentalRefundRequest::STATUS_REFUNDING)
            ->limit(5)
            ->get()
            ->each(fn (RentalRefundRequest $refund) => $refunds->syncPending($refund));

        $requests = RentalRefundRequest::query()
            ->where('organization_id', $organizationId)
            ->whereNotIn('status', [RentalRefundRequest::STATUS_AWAITING_CHOICE, RentalRefundRequest::STATUS_RESCHEDULED])
            ->latest('id')
            ->get();

        $latestByBooking = $requests->groupBy('boat_booking_id')->map->first();
        $rows = collect($rows)->map(function (array $row) use ($latestByBooking): array {
            $refund = $latestByBooking->get($row['booking_id'] ?? null);

            return [
                ...$row,
                'refund_reference' => $refund?->displayReference(),
                'refund_status' => $refund?->status,
                'refund_status_label' => $refund?->statusLabel(),
            ];
        })->all();

        return Inertia::render('payments/Index', [
            'payments' => $rows,
            'stats' => $finance->paymentStats($rows, $organizationId),
            'commission_rate' => null,
            'is_test_mode' => $refunds->isTestMode(),
            'refunds' => $requests->map(fn (RentalRefundRequest $refund) => $refunds->adminPayload($refund))->values()->all(),
            'can_manage_refunds' => $request->user()?->role === UserRole::Administrator,
        ]);
    }

    public function export(Request $request, AdminFinanceLedger $finance): StreamedResponse
    {
        $organizationId = (int) $request->user()?->organization_id;
        abort_unless($organizationId, 404);

        $rows = $finance->paymentRows($organizationId);
        $filename = 'payments-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\u{FEFF}");
            fputcsv($handle, ['Booking Reference', 'Passenger', 'Boat', 'Payment Type', 'Amount', 'Commission', 'Operator Share', 'Method', 'Received By', 'Status', 'Reference', 'Date']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['booking_reference'],
                    $row['passenger_name'],
                    $row['boat_name'],
                    $row['payment_type'],
                    number_format((float) $row['amount_paid'], 2, '.', ''),
                    number_format((float) $row['commission_amount'], 2, '.', ''),
                    number_format((float) $row['operator_share'], 2, '.', ''),
                    $row['payment_method'],
                    $row['received_by'],
                    $row['status'],
                    $row['transaction_id'],
                    $row['paid_at'],
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
