<?php

namespace App\Http\Controllers;

use App\Enums\RentalBlackoutReason;
use App\Models\RentalBookingBlackout;
use App\Services\RentalBlackoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class RentalBlackoutController extends Controller
{
    public function store(Request $request, RentalBlackoutService $blackouts): RedirectResponse
    {
        $data = $this->validated($request);
        $blackouts->create($request->user(), $data);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Rental booking blackout saved. Exclusive rentals are blocked for those dates.',
        ]);

        return back();
    }

    public function update(Request $request, RentalBookingBlackout $rentalBlackout, RentalBlackoutService $blackouts): RedirectResponse
    {
        $data = $this->validated($request);
        $blackouts->update($rentalBlackout, $request->user(), $data);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Rental booking blackout updated.',
        ]);

        return back();
    }

    public function cancel(Request $request, RentalBookingBlackout $rentalBlackout, RentalBlackoutService $blackouts): RedirectResponse
    {
        $data = $request->validate([
            'cancel_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $blackouts->cancel($rentalBlackout, $request->user(), $data['cancel_notes'] ?? null);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Rental booking blackout cancelled. Exclusive rentals are open again on those dates.',
        ]);

        return back();
    }

    /**
     * @return array{
     *     start_date: string,
     *     end_date: string,
     *     reason: string,
     *     remarks: string|null,
     *     auto_resume: bool,
     *     acknowledge_existing: bool
     * }
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', Rule::enum(RentalBlackoutReason::class)],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'auto_resume' => ['sometimes', 'boolean'],
            'acknowledge_existing' => ['sometimes', 'boolean'],
        ]);

        return [
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'],
            'remarks' => $data['remarks'] ?? null,
            'auto_resume' => $request->boolean('auto_resume', true),
            'acknowledge_existing' => $request->boolean('acknowledge_existing'),
        ];
    }
}
