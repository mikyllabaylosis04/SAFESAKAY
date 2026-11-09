<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\OrganizationCommissionSetting;
use App\Models\RentalCommission;
use App\Services\RentalCommissionService;
use App\Support\AdminFinanceLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MyCommissionController extends Controller
{
    public function index(Request $request, AdminFinanceLedger $finance, RentalCommissionService $commissions): Response
    {
        $organizationId = (int) $request->user()?->organization_id;
        abort_unless($organizationId, 404);

        $commissions->backfillMissingCommissions($organizationId);
        $rows = $finance->commissionRows($organizationId);
        $setting = OrganizationCommissionSetting::query()->where('organization_id', $organizationId)->first();

        return Inertia::render('my-commission/Index', [
            'commissions' => $rows,
            'stats' => $finance->commissionStats($rows),
            'commission_rate' => $setting?->commission_enabled ? $setting->percentageAmount() : 0,
            'can_manage' => $request->user()?->role === UserRole::Administrator,
        ]);
    }

    /**
     * Only older rentals paid straight to the operator need this: the operator remits the
     * barangay share and the admin records it here.
     */
    public function markPaid(Request $request, RentalCommission $rentalCommission, RentalCommissionService $commissions): RedirectResponse
    {
        abort_unless((int) $rentalCommission->organization_id === (int) $request->user()?->organization_id, 403);
        abort_unless($request->user()?->role === UserRole::Administrator, 403);

        if ($rentalCommission->canVerify()) {
            $commissions->verify($rentalCommission, $request->user());
        } elseif ($rentalCommission->canRecordCashPayment()) {
            $commissions->recordCashPayment($rentalCommission, $request->user(), now(), 'Recorded from My Commission.');
        } else {
            throw ValidationException::withMessages([
                'commission' => ['This commission is already collected or cannot be marked as received.'],
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Commission marked as received from the operator.']);

        return back();
    }

    public function export(Request $request, AdminFinanceLedger $finance): StreamedResponse
    {
        $organizationId = (int) $request->user()?->organization_id;
        abort_unless($organizationId, 404);

        $rows = $finance->commissionRows($organizationId);
        $filename = 'my-commission-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\u{FEFF}");
            fputcsv($handle, ['Commission ID', 'Rental Reference', 'Customer', 'Boat', 'Total Rental', 'Rate', 'Commission', 'Operator Share', 'Status', 'Earned At']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['commission_id'],
                    $row['rental_reference'],
                    $row['customer_name'],
                    $row['boat_name'],
                    $row['total_rental_payment_label'],
                    $row['commission_rate_label'],
                    number_format((float) $row['commission_amount_raw'], 2, '.', ''),
                    $row['operator_share_label'],
                    $row['status_label'],
                    $row['earned_at_label'],
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
