<?php

namespace App\Http\Controllers\Platform;

use App\Enums\OperatorPayoutStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PlatformEvent;
use App\Models\RentalCommission;
use App\Models\User;
use App\Support\AdminFinanceLedger;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformReportController extends Controller
{
    /**
     * Display overall SafeSakay system reports.
     */
    public function index(): Response
    {
        return Inertia::render('platform/Reports', [
            // Retained for compatibility with the previous reports.
            'access_history' => $this->transformAccessEvents(
                $this->accessHistoryQuery()
                    ->limit(500)
                    ->get()
            ),

            // Existing platform activity records.
            'activity' => $this->transformActivityEvents(
                PlatformEvent::query()
                    ->with('actor')
                    ->latest('id')
                    ->limit(200)
                    ->get()
            ),

            // Organization activity report.
            'organizations' => $this->organizationRows(),

            // Passenger and operator registration report.
            'registrations' => $this->registrationRows(),

            // Overall platform usage statistics.
            'usage_statistics' => $this->usageStatistics(),

            // Monthly platform-wide activity.
            'monthly_activity' => $this->monthlyActivity(),

            'finance' => $this->financeRows(),
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function financeRows(): Collection
    {
        $ledger = app(AdminFinanceLedger::class);

        $paidOut = RentalCommission::query()
            ->where('operator_payout_status', OperatorPayoutStatus::PaidOut)
            ->selectRaw('organization_id, SUM(operator_net_amount) as total')
            ->groupBy('organization_id')
            ->pluck('total', 'organization_id');

        return Organization::query()
            ->tenants()
            ->orderBy('name')
            ->get()
            ->map(function (Organization $organization) use ($ledger, $paidOut): array {
                $rows = collect($ledger->paymentRows($organization->id));
                $received = $rows->filter(fn (array $row) => $row['is_verified'] && $row['received_by'] === 'Barangay');
                $collected = round((float) $received->sum('amount_paid'), 2);
                $commission = $ledger->commissionTotal($organization->id);
                $lastPaid = $received->max('paid_at');

                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'status_label' => $organization->accessStateLabel(),
                    'rentals' => $rows->pluck('booking_id')->unique()->count(),
                    'payments' => $received->count(),
                    'collected' => $collected,
                    'commission' => $commission,
                    'operator_share' => round(max(0, $collected - $commission), 2),
                    'paid_out' => round((float) ($paidOut[$organization->id] ?? 0), 2),
                    'last_payment_label' => $lastPaid ? Carbon::parse($lastPaid)->format('M d, Y') : null,
                ];
            })
            ->values();
    }

    private function exportFinance(): StreamedResponse
    {
        return $this->csvDownload(
            'platform-finance-'.now()->format('Y-m-d').'.csv',
            ['Organization', 'Status', 'Rentals', 'Payments', 'Collected', 'Commission', 'Operator Share', 'Paid Out', 'Last Payment'],
            $this->financeRows()->map(fn (array $row) => [
                $row['name'],
                $row['status_label'],
                $row['rentals'],
                $row['payments'],
                number_format($row['collected'], 2, '.', ''),
                number_format($row['commission'], 2, '.', ''),
                number_format($row['operator_share'], 2, '.', ''),
                number_format($row['paid_out'], 2, '.', ''),
                $row['last_payment_label'] ?? '',
            ]),
        );
    }

    /**
     * Keep the old access-history URL working.
     */
    public function legacyAccessHistory(): RedirectResponse
    {
        return redirect()->route('platform.reports');
    }

    /**
     * Export system reports as CSV.
     */
    public function export(string $report): StreamedResponse
    {
        return match ($report) {
            'access-history' => $this->exportAccessHistory(),
            'activity' => $this->exportActivity(),
            'organizations' => $this->exportOrganizations(),
            'registrations' => $this->exportRegistrations(),
            'monthly-activity' => $this->exportMonthlyActivity(),
            'usage-statistics' => $this->exportUsageStatistics(),
            'finance' => $this->exportFinance(),
            default => abort(404),
        };
    }

    /**
     * Access history query.
     */
    private function accessHistoryQuery()
    {
        return PlatformEvent::query()
            ->with('actor')
            ->whereIn(
                'action',
                PlatformEvent::accessHistoryActions()
            )
            ->latest('id');
    }

    /**
     * Convert access history events into report rows.
     *
     * @param Collection<int, PlatformEvent> $events
     */
    private function transformAccessEvents(
        Collection $events
    ): Collection {
        return $events->map(
            fn (PlatformEvent $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'action_label' => $event->actionLabel(),
                'organization_id' => $event->organization_id,
                'organization_name' => $event->organization_name,
                'actor_name' => $event->actor?->name,
                'note' => $event->note,
                'created_at_label' => $event->created_at
                    ?->format('M d, Y · g:i A'),
                'period' => $event->created_at
                    ?->format('Y-m'),
            ]
        )->values();
    }

    /**
     * Convert platform events into activity report rows.
     *
     * @param Collection<int, PlatformEvent> $events
     */
    private function transformActivityEvents(
        Collection $events
    ): Collection {
        return $events->map(
            fn (PlatformEvent $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'action_label' => $event->actionLabel(),
                'organization_id' => $event->organization_id,
                'organization_name' => $event->organization_name,
                'actor_name' => $event->actor?->name,
                'note' => $event->note,
                'created_at_label' => $event->created_at
                    ?->format('M d, Y · g:i A'),
            ]
        )->values();
    }

    /**
     * Organization Activity Report.
     *
     * Includes organization status, administrator,
     * number of registered users, and fleet size.
     */
    private function organizationRows(): Collection
    {
        return Organization::query()
            ->tenants()
            ->withCount(['boats', 'users'])
            ->orderBy('name')
            ->get()
            ->map(function (Organization $organization) {
                $admin = User::query()
                    ->where(
                        'organization_id',
                        $organization->id
                    )
                    ->where(
                        'role',
                        UserRole::Administrator
                    )
                    ->first();

                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'address' => $organization->address,
                    'status_label' =>
                        $organization->accessStateLabel(),
                    'admin_name' => $admin?->name,
                    'admin_email' => $admin?->email,
                    'boats_count' =>
                        (int) $organization->boats_count,
                    'users_count' =>
                        (int) $organization->users_count,
                ];
            })
            ->values();
    }

    /**
     * User Registration Report.
     *
     * Passenger and operator accounts only.
     * Excludes barangay administrators and super admins.
     */
    private function registrationRows(): Collection
    {
        $users = User::query()
            ->whereIn('role', [
                UserRole::Passenger,
                UserRole::Operator,
            ])
            ->orderByDesc('created_at')
            ->get([
                'id',
                'name',
                'email',
                'role',
                'organization_id',
                'created_at',
            ]);

        $organizationNames = Organization::query()
            ->whereIn(
                'id',
                $users->pluck('organization_id')
                    ->filter()
                    ->unique()
                    ->values()
            )
            ->pluck('name', 'id');

        return $users->map(function (User $user) use (
            $organizationNames
        ) {
            $role = $user->role;

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $role instanceof \BackedEnum
                    ? $role->value
                    : (string) $role,
                'organization_name' =>
                    $organizationNames->get(
                        $user->organization_id
                    ),
                'created_at' => $user->created_at
                    ?->toIso8601String(),
            ];
        })->values();
    }

    /**
     * System Usage Statistics.
     *
     * These are recorded platform totals,
     * not invented login/session measurements.
     */
    private function usageStatistics(): Collection
    {
        $organizationCount = Organization::query()
            ->tenants()
            ->count();

        $registeredUsers = User::query()
            ->whereIn('role', [
                UserRole::Passenger,
                UserRole::Operator,
            ])
            ->count();

        $passengerCount = User::query()
            ->where('role', UserRole::Passenger)
            ->count();

        $operatorCount = User::query()
            ->where('role', UserRole::Operator)
            ->count();

        $boatCount = Organization::query()
            ->tenants()
            ->withCount('boats')
            ->get()
            ->sum('boats_count');

        $activityCount = PlatformEvent::query()->count();

        return collect([
            [
                'label' => 'Organizations',
                'value' => $organizationCount,
                'description' =>
                    'Registered barangay organizations',
            ],
            [
                'label' => 'Registered Users',
                'value' => $registeredUsers,
                'description' =>
                    'Passenger and operator accounts',
            ],
            [
                'label' => 'Passenger Accounts',
                'value' => $passengerCount,
                'description' =>
                    'Registered passenger accounts',
            ],
            [
                'label' => 'Operator Accounts',
                'value' => $operatorCount,
                'description' =>
                    'Registered boat operator accounts',
            ],
            [
                'label' => 'Registered Boats',
                'value' => (int) $boatCount,
                'description' =>
                    'Boats across tenant organizations',
            ],
            [
                'label' => 'Recorded Platform Events',
                'value' => $activityCount,
                'description' =>
                    'Platform events stored in the system',
            ],
        ]);
    }

    /**
     * Monthly Platform Activity Report.
     *
     * Shows the most recent 12 calendar months,
     * including the current month.
     */
    private function monthPeriodExpression(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";
    }

    private function monthlyActivity(): Collection
    {
        $start = now()
            ->startOfMonth()
            ->subMonths(11);

        $organizationCounts = Organization::query()
            ->tenants()
            ->where(
                'created_at',
                '>=',
                $start->toDateTimeString()
            )
            ->selectRaw($this->monthPeriodExpression().' as period')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('period')
            ->pluck('total', 'period');

        $registrationCounts = User::query()
            ->whereIn('role', [
                UserRole::Passenger,
                UserRole::Operator,
            ])
            ->where(
                'created_at',
                '>=',
                $start->toDateTimeString()
            )
            ->selectRaw($this->monthPeriodExpression().' as period')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('period')
            ->pluck('total', 'period');

        $activityCounts = PlatformEvent::query()
            ->where(
                'created_at',
                '>=',
                $start->toDateTimeString()
            )
            ->selectRaw($this->monthPeriodExpression().' as period')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('period')
            ->pluck('total', 'period');

        $rows = collect();

        for ($index = 0; $index < 12; $index++) {
            $month = $start->copy()->addMonths($index);
            $period = $month->format('Y-m');

            $rows->push([
                'month' => $month->format('M Y'),
                'organizations' => (int) (
                    $organizationCounts[$period] ?? 0
                ),
                'registrations' => (int) (
                    $registrationCounts[$period] ?? 0
                ),
                'activities' => (int) (
                    $activityCounts[$period] ?? 0
                ),
            ]);
        }

        return $rows->values();
    }

    /**
     * Export Access History.
     */
    private function exportAccessHistory(): StreamedResponse
    {
        $rows = $this->transformAccessEvents(
            $this->accessHistoryQuery()
                ->limit(2000)
                ->get()
        );

        return $this->csvDownload(
            'platform-access-history-'
                .now()->format('Y-m-d').'.csv',
            [
                'When',
                'Action',
                'Organization',
                'By',
                'Reason',
            ],
            $rows->map(
                fn (array $row) => [
                    $row['created_at_label'] ?? '',
                    $row['action_label'] ?? '',
                    $row['organization_name'] ?? '',
                    $row['actor_name'] ?? '',
                    $row['note'] ?? '',
                ]
            )
        );
    }

    /**
     * Export Platform Activity.
     */
    private function exportActivity(): StreamedResponse
    {
        $rows = $this->transformActivityEvents(
            PlatformEvent::query()
                ->with('actor')
                ->latest('id')
                ->limit(2000)
                ->get()
        );

        return $this->csvDownload(
            'platform-activity-'
                .now()->format('Y-m-d').'.csv',
            [
                'When',
                'Action',
                'Organization',
                'By',
                'Note',
            ],
            $rows->map(
                fn (array $row) => [
                    $row['created_at_label'] ?? '',
                    $row['action_label'] ?? '',
                    $row['organization_name'] ?? '',
                    $row['actor_name'] ?? '',
                    $row['note'] ?? '',
                ]
            )
        );
    }

    /**
     * Export Organization Activity.
     */
    private function exportOrganizations(): StreamedResponse
    {
        $rows = $this->organizationRows();

        return $this->csvDownload(
            'platform-organizations-'
                .now()->format('Y-m-d').'.csv',
            [
                'Organization',
                'Address',
                'Status',
                'Admin',
                'Admin Email',
                'Boats',
                'Users',
            ],
            $rows->map(
                fn (array $row) => [
                    $row['name'] ?? '',
                    $row['address'] ?? '',
                    $row['status_label'] ?? '',
                    $row['admin_name'] ?? '',
                    $row['admin_email'] ?? '',
                    $row['boats_count'] ?? 0,
                    $row['users_count'] ?? 0,
                ]
            )
        );
    }

    /**
     * Export User Registrations.
     */
    private function exportRegistrations(): StreamedResponse
    {
        $rows = $this->registrationRows();

        return $this->csvDownload(
            'platform-user-registrations-'
                .now()->format('Y-m-d').'.csv',
            [
                'Name',
                'Email',
                'Role',
                'Organization',
                'Registered On',
            ],
            $rows->map(
                fn (array $row) => [
                    $row['name'] ?? '',
                    $row['email'] ?? '',
                    $row['role'] ?? '',
                    $row['organization_name'] ?? '',
                    $row['created_at'] ?? '',
                ]
            )
        );
    }

    /**
     * Export Monthly Platform Activity.
     */
    private function exportMonthlyActivity(): StreamedResponse
    {
        $rows = $this->monthlyActivity();

        return $this->csvDownload(
            'platform-monthly-activity-'
                .now()->format('Y-m-d').'.csv',
            [
                'Month',
                'New Organizations',
                'User Registrations',
                'Recorded Activities',
            ],
            $rows->map(
                fn (array $row) => [
                    $row['month'],
                    $row['organizations'],
                    $row['registrations'],
                    $row['activities'],
                ]
            )
        );
    }

    /**
     * Export System Usage Statistics.
     */
    private function exportUsageStatistics(): StreamedResponse
    {
        $rows = $this->usageStatistics();

        return $this->csvDownload(
            'platform-usage-statistics-'
                .now()->format('Y-m-d').'.csv',
            [
                'Metric',
                'Total',
                'Description',
            ],
            $rows->map(
                fn (array $row) => [
                    $row['label'],
                    $row['value'],
                    $row['description'] ?? '',
                ]
            )
        );
    }

    /**
     * Generate CSV download.
     */
    private function csvDownload(
        string $filename,
        array $headers,
        Collection $rows
    ): StreamedResponse {
        return response()->streamDownload(
            function () use ($headers, $rows): void {
                $handle = fopen('php://output', 'w');

                if ($handle === false) {
                    return;
                }

                fwrite($handle, "\u{FEFF}");

                fputcsv($handle, $headers);

                foreach ($rows as $row) {
                    fputcsv($handle, array_map(
                        fn ($value) => $this->safeCsvValue($value),
                        $row
                    ));
                }

                fclose($handle);
            },
            $filename,
            [
                'Content-Type' =>
                    'text/csv; charset=UTF-8',
            ]
        );
    }

    /**
     * Prevent CSV formula injection.
     */
    private function safeCsvValue(mixed $value): string
    {
        $text = (string) ($value ?? '');

        if (preg_match('/^\s*[=+\-@\t\r]/u', $text)) {
            return "'".$text;
        }

        return $text;
    }
}
