<?php

namespace App\Http\Requests;

use App\Enums\PassengerFareType;
use App\Models\RegularTrip;
use App\Models\TripPassenger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTripPassengerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $age = (int) $this->input('age');
        $fareType = PassengerFareType::tryFrom((string) $this->input('fare_type'));

        $this->merge([
            'fare_type' => ($fareType ?? PassengerFareType::fromAge($age))->value,
            'contact_number' => trim((string) $this->input('contact_number', '')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:120'],
            'age' => ['required', 'integer', 'min:0', 'max:120'],
            'fare_type' => ['required', Rule::enum(PassengerFareType::class)],
            'gender' => ['required', 'string', 'in:Male,Female,Prefer not to say'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'full_name' => 'full name',
            'fare_type' => 'fare type',
            'contact_number' => 'contact number',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $trip = $this->route('regularTrip');

                if (! $trip instanceof RegularTrip) {
                    return;
                }

                if (! $trip->canEncodePassengers()) {
                    $validator->errors()->add(
                        'full_name',
                        'Passenger encoding is closed for this trip.',
                    );

                    return;
                }

                $existing = $this->route('tripPassenger');

                if ($existing instanceof TripPassenger) {
                    return;
                }

                if ($trip->isAtCapacity()) {
                    $validator->errors()->add(
                        'full_name',
                        'Boat capacity has been reached for this trip.',
                    );
                }
            },
        ];
    }
}
