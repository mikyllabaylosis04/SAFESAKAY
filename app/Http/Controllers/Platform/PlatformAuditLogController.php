<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformAuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $logs = $this->query($filters)
            ->with('actor')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('platform/AuditLogs', [
            'logs' => $logs->through(
                fn (PlatformEvent $event) => $this->formatLog($event)
            ),
            'filters' => $filters,
            'action_options' => PlatformEvent::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->map(fn (string $action) => [
                    'value' => $action,
                    'label' => (new PlatformEvent(['action' => $action]))->actionLabel(),
                ])
                ->values(),
            'stats' => [
                'total' => PlatformEvent::query()->count(),
                'today' => PlatformEvent::query()->whereDate('created_at', today())->count(),
                'this_month' => PlatformEvent::query()
                    ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->count(),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'safesakay-audit-logs-'
            . now()->format('Y-m-d')
            . '.csv';

        return response()->streamDownload(
            function () use ($filters): void {
                $handle = fopen('php://output', 'w');

                if ($handle === false) {
                    return;
                }

                fwrite($handle, "\u{FEFF}");

                fputcsv($handle, [
                    'Date and Time',
                    'Action',
                    'Performed By',
                    'Organization',
                    'Details',
                ]);

                $this->query($filters)
                    ->with('actor')
                    ->latest('id')
                    ->chunkById(
                        500,
                        function ($events) use ($handle): void {
                            foreach ($events as $event) {
                                $row = $this->formatLog($event);

                                fputcsv($handle, array_map(
                                    fn ($value) => $this->safeCsvValue($value),
                                    [
                                        $row['created_at'],
                                        $row['action_label'],
                                        $row['actor_name'],
                                        $row['organization_name'],
                                        $row['note'],
                                    ]
                                ));
                            }
                        },
                        'id',
                        'id',
                        true
                    );

                fclose($handle);
            },
            $filename,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]
        );
    }

    /**
     * @return array{search: string, action: string, date: string}
     */
    private function filters(Request $request): array
    {
        $date = (string) $request->query('date', '');

        return [
            'search' => trim((string) $request->query('search', '')),
            'action' => (string) $request->query('action', ''),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '',
        ];
    }

    /**
     * @param  array{search: string, action: string, date: string}  $filters
     * @return Builder<PlatformEvent>
     */
    private function query(array $filters): Builder
    {
        return PlatformEvent::query()
            ->when($filters['action'] !== '', fn (Builder $query) => $query->where('action', $filters['action']))
            ->when($filters['date'] !== '', fn (Builder $query) => $query->whereDate('created_at', $filters['date']))
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['search']).'%';

                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('action', 'like', $term)
                        ->orWhere('organization_name', 'like', $term)
                        ->orWhere('note', 'like', $term)
                        ->orWhereHas('actor', fn (Builder $actor) => $actor->where('name', 'like', $term));
                });
            });
    }

    private function formatLog(PlatformEvent $event): array
    {
        return [
            'id' => $event->id,
            'action' => $event->action,
            'action_label' => $event->actionLabel(),
            'actor_name' => $event->actor?->name ?? 'System',
            'organization_name' => $event->organization_name ?? '—',
            'note' => $event->note,
            'created_at' => $event->created_at
                ?->format('M d, Y · h:i A'),
        ];
    }

    private function safeCsvValue(mixed $value): string
    {
        $text = (string) ($value ?? '');

        if (preg_match('/^\s*[=+\-@\t\r]/u', $text)) {
            return "'" . $text;
        }

        return $text;
    }
}
