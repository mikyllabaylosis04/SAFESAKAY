<?php

namespace App\Http\Controllers;

use App\Enums\OperatorNoShowRefundStatus;
use App\Models\BoatBooking;
use App\Models\OperatorNoShowReport;
use App\Models\PassengerNoShowReport;
use App\Services\OperatorNoShowService;
use App\Services\OperatorNoShowViolationService;
use App\Services\PassengerNoShowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperatorNoShowController extends Controller
{
    /**
     * Operator No-Show Management: every passenger report, from Pending Verification to Refunded / Rejected.
     */
    public function index(Request $request, OperatorNoShowViolationService $violations): Response
    {
        $organizationId = (int) $request->user()?->organization_id;
        abort_unless($organizationId && $request->user()?->role?->canAccessBarangayWeb(), 403);

        $strikes = [];
        $cases = OperatorNoShowReport::query()
            ->where('organization_id', $organizationId)
            ->latest('reported_at')
            ->latest('id')
            ->get()
            ->map(function (OperatorNoShowReport $report) use ($violations, &$strikes): array {
                $record = $report->toAdminRecord();
                $operator = $report->booking?->boat?->operator;
                if ($operator) {
                    $strikes[$operator->id] ??= $violations->confirmedStrikeCount($operator);
                }
                $record['operator_violation_count'] = $operator ? $strikes[$operator->id] : 0;
                $record['operator_violation_max'] = OperatorNoShowViolationService::MAX_STRIKES;

                return $record;
            })
            ->values();

        return Inertia::render('bookings/OperatorNoShows', [
            'cases' => $cases,
            'counts' => [
                'pending' => $cases->where('stage', 'pending')->count(),
                'refund' => $cases->where('stage', 'refund')->count(),
                'refunded' => $cases->where('stage', 'refunded')->count(),
                'rejected' => $cases->where('stage', 'rejected')->count(),
            ],
            'can_manage' => $request->user()?->role === \App\Enums\UserRole::Administrator,
        ]);
    }

    public function update(Request $request, OperatorNoShowReport $operatorNoShowReport, OperatorNoShowService $service): RedirectResponse
    {
        $operatorNoShowReport->loadMissing('booking');
        abort_unless(
            $operatorNoShowReport->booking
                && (int) $operatorNoShowReport->booking->organization_id === (int) $request->user()?->organization_id
                && $request->user()?->role?->canAccessBarangayWeb(),
            403,
        );

        $action = $request->string('action')->toString();

        $notesRule = ['admin_notes' => ['required', 'string', 'min:3', 'max:1000']];
        $notesMessages = ['admin_notes.required' => 'Add verification notes before saving.'];

        // Simplified workflow: verify or reject, then refund (Xendit or recorded GCash transfer).
        $result = match ($action) {
            'verify' => $service->verify(
                $operatorNoShowReport,
                $request->user(),
                $request->validate($notesRule, $notesMessages)['admin_notes'],
            ),
            'dismiss' => $service->dismiss(
                $operatorNoShowReport,
                $request->user(),
                $request->validate(
                    ['admin_notes' => ['required', 'string', 'min:5', 'max:1000']],
                    ['admin_notes.required' => 'Give the reason for rejecting this report.'],
                )['admin_notes'],
            ),
            'refund_xendit' => $service->sendXenditRefund($operatorNoShowReport, $request->user()),
            'complete_refund' => $this->recordRefund($request, $operatorNoShowReport, $service),
            default => abort(422, 'Unknown action.'),
        };

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match ($action) {
                'verify' => 'No-show verified. The booking is marked Operator No-Show, a violation was added to the operator, and the full refund is ready to process.',
                'dismiss' => 'Report rejected. The booking stays unchanged and the passenger was notified.',
                'refund_xendit' => $result->refund_status === OperatorNoShowRefundStatus::Refunded
                    ? 'Full refund sent through Xendit. The passenger was notified.'
                    : 'Refund sent to Xendit. It will show as Refunded once Xendit confirms.',
                'complete_refund' => 'Refund recorded. The passenger was notified.',
                default => 'Incident updated.',
            },
        ]);

        return back();
    }

    private function recordRefund(Request $request, OperatorNoShowReport $report, OperatorNoShowService $service): OperatorNoShowReport
    {
        $data = $request->validate([
            'refund_amount' => ['required', 'numeric', 'min:1', 'max:'.max(1, (float) ($report->refund_amount ?? 0))],
            'refunded_at' => ['required', 'date', 'before_or_equal:now'],
            'refund_reference' => ['required', 'string', 'min:6', 'max:80'],
            'refund_receipt' => ['nullable', 'image', 'max:5120'],
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'refund_amount.max' => 'The refund cannot be more than the amount the passenger paid.',
        ]);

        return $service->completeRefund(
            $report,
            $request->user(),
            $data['refund_reference'],
            $request->file('refund_receipt'),
            $data['admin_notes'] ?? null,
            (float) $data['refund_amount'],
            Carbon::parse($data['refunded_at'], 'Asia/Manila'),
        );
    }

    public function resolvePassengerNoShow(
        Request $request,
        PassengerNoShowReport $passengerNoShowReport,
        PassengerNoShowService $service,
    ): RedirectResponse {
        abort_unless(
            (int) $passengerNoShowReport->organization_id === (int) $request->user()?->organization_id
                && $request->user()?->role?->canAccessBarangayWeb(),
            403,
        );

        $data = $request->validate([
            'decision' => ['required', 'in:uphold,reverse'],
            'admin_notes' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $service->resolve($passengerNoShowReport, $request->user(), $data['decision'], $data['admin_notes']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $data['decision'] === 'uphold'
                ? 'Passenger no-show upheld. The passenger and operator were notified.'
                : 'Passenger no-show reversed. The passenger can now choose a refund or a new date.',
        ]);

        return back();
    }

    public function evidence(Request $request, OperatorNoShowReport $operatorNoShowReport): StreamedResponse|BinaryFileResponse
    {
        abort_unless(
            (int) $operatorNoShowReport->organization_id === (int) $request->user()?->organization_id
                && $request->user()?->role?->canAccessBarangayWeb(),
            403,
        );

        return $this->streamEvidence($operatorNoShowReport->evidence_photo_path);
    }

    public function passengerEvidence(Request $request, PassengerNoShowReport $passengerNoShowReport): StreamedResponse|BinaryFileResponse
    {
        abort_unless(
            (int) $passengerNoShowReport->organization_id === (int) $request->user()?->organization_id
                && $request->user()?->role?->canAccessBarangayWeb(),
            403,
        );

        return $this->streamEvidence($passengerNoShowReport->evidence_photo_path);
    }

    private function streamEvidence(?string $path): StreamedResponse|BinaryFileResponse
    {
        if (! filled($path)) {
            abort(404);
        }

        foreach (['public', 'local'] as $disk) {
            if (! Storage::disk($disk)->exists($path)) {
                continue;
            }

            return Storage::disk($disk)->response(
                $path,
                null,
                [
                    'Content-Type' => Storage::disk($disk)->mimeType($path) ?: 'image/jpeg',
                    'Cache-Control' => 'private, max-age=3600',
                ],
            );
        }

        abort(404);
    }

    public function acknowledgePassengerNoShow(
        Request $request,
        BoatBooking $boatBooking,
        OperatorNoShowService $service,
    ): RedirectResponse {
        $service->acknowledgePassengerNoShow($boatBooking, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Passenger no-show marked as reviewed. It is now in History.',
        ]);

        return back();
    }
}
