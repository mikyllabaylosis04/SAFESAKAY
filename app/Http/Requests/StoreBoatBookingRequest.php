<?php

namespace App\Http\Requests;

use App\Enums\BoatBookingStatus;
use App\Models\Boat;
use App\Services\RentalPricingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBoatBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $time = $this->input('start_time');

        if (is_string($time) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            $this->merge([
                'start_time' => substr($time, 0, 5),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $capacity = Boat::query()->find($this->input('boat_id'))?->capacity ?? 200;
        $walkIn = $this->boolean('walk_in');

        return [
            'walk_in' => ['sometimes', 'boolean'],
            'boat_id' => [
                'required',
                'integer',
                Rule::exists('boats', 'id')
                    ->whereNull('deleted_at')
                    ->where('organization_id', $this->user()?->organization_id),
            ],
            'customer_name' => ['required', 'string', 'max:120'],
            'contact_number' => [
                $walkIn ? 'required' : 'nullable',
                'string',
                'max:30',
            ],
            'package' => ['nullable', 'string', 'max:120'],
            'destination' => ['required', 'string', 'max:120'],
            'pickup_point' => [
                $walkIn ? 'required' : 'nullable',
                'string',
                'max:180',
            ],
            'purpose' => [
                $walkIn ? 'required' : 'nullable',
                'string',
                'max:180',
            ],
            'booking_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'passenger_count' => ['required', 'integer', 'min:1', 'max:'.$capacity],
            'status' => [
                Rule::requiredIf(! $walkIn),
                Rule::enum(BoatBookingStatus::class),
            ],
            'walk_in_payment_method' => [
                Rule::requiredIf($walkIn),
                Rule::in(['cash', 'gcash']),
            ],
            'gcash_reference' => [
                Rule::requiredIf($walkIn && $this->input('walk_in_payment_method') === 'gcash'),
                'nullable',
                'string',
                'min:6',
                'max:80',
            ],
            'walk_in_cash_received' => [
                Rule::requiredIf($walkIn && $this->input('walk_in_payment_method') === 'cash'),
                'nullable',
                'numeric',
                'min:0',
            ],
            'payment_receipt' => [
                Rule::requiredIf($walkIn && $this->input('walk_in_payment_method') === 'gcash'),
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:5120',
            ],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'boat_id' => 'boat',
            'customer_name' => 'customer name',
            'booking_date' => 'date',
            'start_time' => 'time',
            'passenger_count' => 'passengers',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $boat = Boat::query()->find($this->input('boat_id'));
                $date = (string) $this->input('booking_date');
                $time = (string) $this->input('start_time');
                $exceptId = $this->route('boatBooking')?->id;

                if ($boat && $date !== '' && ! $boat->isEligibleForPrivateBooking($date)) {
                    $validator->errors()->add(
                        'boat_id',
                        $boat->slotConflictMessage($date, $time ?: null, $exceptId)
                            ?: 'This boat is not available for private booking.',
                    );

                    return;
                }

                if (
                    $boat
                    && ($conflict = $boat->slotConflictMessage($date, $time ?: null, $exceptId))
                ) {
                    $validator->errors()->add('start_time', $conflict);
                }

                if ($boat && $date !== '') {
                    $blackout = app(\App\Services\RentalBlackoutService::class)->blockMessage(
                        (int) $boat->organization_id,
                        $date,
                    );

                    if ($blackout) {
                        $validator->errors()->add('booking_date', $blackout);
                    }
                }

                if (
                    $this->boolean('walk_in')
                    && $this->input('walk_in_payment_method') === 'cash'
                    && $boat
                ) {
                    $pricing = app(RentalPricingService::class)->snapshotForDestination(
                        $boat->loadMissing('rentalDestinations'),
                        (string) $this->input('destination'),
                    );
                    $due = (float) ($pricing['down_payment_amount'] ?? 0);
                    $received = (float) $this->input('walk_in_cash_received');

                    if ($due > 0 && $received + 0.009 < $due) {
                        $validator->errors()->add(
                            'walk_in_cash_received',
                            'Amount received must be at least the total amount due.',
                        );
                    }
                }
            },
        ];
    }
}
