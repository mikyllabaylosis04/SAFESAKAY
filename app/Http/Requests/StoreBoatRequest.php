<?php

namespace App\Http\Requests;

use App\Enums\BoatRegistrationStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\OrganizationRouteType;
use App\Enums\SafetyEquipment;
use App\Models\Boat;
use App\Models\Operator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBoatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $equipment = $this->input('safety_equipment', []);

        if (! is_array($equipment)) {
            $equipment = filled($equipment) ? [$equipment] : [];
        }

        $boat = $this->route('boat');
        $boat = $boat instanceof Boat ? $boat : null;
        $services = $this->input(
            'service_availability',
            $this->has('service_type') ? $this->legacyServiceAvailability() : [],
        );

        if (! is_array($services)) {
            $services = filled($services) ? [$services] : [];
        }

        $defaults = [
            'safety_equipment' => array_values(array_filter(
                $equipment,
                fn ($value) => is_string($value) && $value !== '',
            )),
            'service_availability' => array_values(array_intersect(
                BoatService::values(),
                array_filter($services, fn ($value) => is_string($value) && $value !== ''),
            )),
        ];

        if (! $this->filled('status')) {
            $defaults['status'] = $boat?->status?->value
                ?? BoatStatus::Unavailable->value;
        }

        if (in_array($this->input('status', $defaults['status'] ?? null), [
            BoatStatus::OnDuty->value,
            BoatStatus::OnTrip->value,
        ], true)) {
            $defaults['status'] = BoatStatus::Available->value;
        }

        $defaults['registration_status'] = BoatRegistrationStatus::Registered->value;

        $first = Operator::sanitizeName($this->input('operator_first_name'));
        $middle = Operator::sanitizeName($this->input('operator_middle_name'));
        $last = Operator::sanitizeName($this->input('operator_last_name'));

        if (($first === null || $last === null) && $this->filled('operator_full_name')) {
            $parts = Operator::splitFullName((string) $this->input('operator_full_name'));
            $first ??= Operator::sanitizeName($parts['first']);
            $middle ??= Operator::sanitizeName($parts['middle']);
            $last ??= Operator::sanitizeName($parts['last']);
        }

        $defaults['operator_first_name'] = $first;
        $defaults['operator_middle_name'] = $middle;
        $defaults['operator_last_name'] = $last;
        $defaults['operator_full_name'] = Operator::composeFullName($first, $middle, $last);
        $defaults['operator_contact_number'] = Operator::sanitizeMobile(
            $this->input('operator_contact_number'),
        );
        $defaults['operator_emergency_contact_name'] = Operator::sanitizeName(
            $this->input('operator_emergency_contact_name'),
        );
        $defaults['operator_emergency_contact_number'] = Operator::sanitizeMobile(
            $this->input('operator_emergency_contact_number'),
        );

        if ($this->exists('operator_address')) {
            $defaults['operator_address'] = Operator::sanitizeName(
                $this->input('operator_address'),
            );
        }

        foreach (['operator_middle_name'] as $optional) {
            if (! array_key_exists($optional, $defaults) && $this->input($optional) === '') {
                $defaults[$optional] = null;
            }
        }

        if (($defaults['operator_middle_name'] ?? $this->input('operator_middle_name')) === '') {
            $defaults['operator_middle_name'] = null;
        }

        if (! in_array(BoatService::PrivateBooking->value, $defaults['service_availability'], true)) {
            $defaults['rental_destination_ids'] = [];
        }

        $this->merge($defaults);
    }

    /**
     * @return list<string>
     */
    public function selectedSafetyEquipment(): array
    {
        return array_values(array_intersect(
            SafetyEquipment::values(),
            $this->input('safety_equipment', []),
        ));
    }

    public function lifeJacketQuantity(): ?int
    {
        if (! in_array(SafetyEquipment::LifeJackets->value, $this->selectedSafetyEquipment(), true)) {
            return null;
        }

        $quantity = $this->input('life_jacket_quantity');

        return $quantity === null || $quantity === '' ? null : (int) $quantity;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required',
                'string',
                'max:40',
                Rule::unique('boats', 'code')
                    ->where('organization_id', $this->user()?->organization_id)
                    ->ignore($this->route('boat')),
            ],
            'capacity' => ['required', 'integer', 'min:1', 'max:200'],
            'license_no' => ['nullable', 'string', 'max:80'],
            'registration_date' => ['nullable', 'date'],
            'registration_status' => ['required', Rule::enum(BoatRegistrationStatus::class)],
            'status' => ['required', Rule::enum(BoatStatus::class)],
            'service_availability' => ['nullable', 'array'],
            'service_availability.*' => ['string', Rule::in(BoatService::values())],
            'rental_destination_ids' => ['nullable', 'array'],
            'rental_destination_ids.*' => [
                'integer',
                Rule::exists('organization_routes', 'id')->where(function ($query) {
                    $query->where('organization_id', $this->user()?->organization_id)
                        ->where('trip_type', OrganizationRouteType::ExclusiveBoatRental->value)
                        ->where('is_active', true);
                }),
            ],
            'safety_equipment' => ['nullable', 'array'],
            'safety_equipment.*' => ['string', Rule::in(SafetyEquipment::values())],
            'life_jacket_quantity' => [
                Rule::requiredIf(fn () => in_array(
                    SafetyEquipment::LifeJackets->value,
                    $this->input('safety_equipment', []),
                    true,
                )),
                'nullable',
                'integer',
                'min:1',
                'max:200',
            ],
            'boat_picture' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'boat_pictures' => ['nullable', 'array', 'max:'.Boat::MAX_PICTURES],
            'boat_pictures.*' => ['image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'removed_picture_ids' => ['nullable', 'array'],
            'removed_picture_ids.*' => ['integer'],
            'admin_use_only' => ['sometimes', 'boolean'],
            'operator_first_name' => ['required', 'string', 'max:60', 'regex:'.Operator::NAME_REGEX],
            'operator_middle_name' => ['nullable', 'string', 'max:60', 'regex:'.Operator::NAME_REGEX],
            'operator_last_name' => ['required', 'string', 'max:60', 'regex:'.Operator::NAME_REGEX],
            'operator_age' => ['required', 'integer', 'min:'.Operator::MIN_AGE, 'max:'.Operator::MAX_AGE],
            'operator_gender' => ['required', Rule::in(Operator::GENDERS)],
            'operator_contact_number' => ['required', 'string', 'regex:'.Operator::MOBILE_REGEX],
            'operator_address' => ['required', 'string', 'min:8', 'max:255'],
            'operator_experience_years' => ['required', 'integer', 'min:0', 'max:60'],
            'operator_emergency_contact_name' => ['required', 'string', 'max:60', 'regex:'.Operator::NAME_REGEX],
            'operator_emergency_contact_number' => ['required', 'string', 'regex:'.Operator::MOBILE_REGEX],
            'operator_picture' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'boat name',
            'code' => 'boat code',
            'operator_full_name' => 'operator full name',
            'operator_first_name' => 'first name',
            'operator_middle_name' => 'middle name',
            'operator_last_name' => 'last name',
            'operator_age' => 'age',
            'operator_gender' => 'gender',
            'operator_contact_number' => 'contact number',
            'operator_address' => 'complete address',
            'operator_experience_years' => 'years of experience',
            'operator_emergency_contact_name' => 'emergency contact name',
            'operator_emergency_contact_number' => 'emergency contact number',
            'boat_pictures' => 'boat pictures',
            'boat_pictures.*' => 'boat picture',
            'safety_equipment' => 'safety equipment',
            'life_jacket_quantity' => 'life jacket quantity',
            'registration_status' => 'registration status',
            'status' => 'operational status',
            'service_availability' => 'service availability',
            'rental_destination_ids' => 'rental destinations',
            'rental_destination_ids.*' => 'rental destination',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'operator_first_name.required' => 'Enter the operator’s first name.',
            'operator_last_name.required' => 'Enter the operator’s last name.',
            'operator_first_name.regex' => 'First name must be at least 2 letters and cannot include numbers or special characters.',
            'operator_middle_name.regex' => 'Middle name must be at least 2 letters and cannot include numbers or special characters.',
            'operator_last_name.regex' => 'Last name must be at least 2 letters and cannot include numbers or special characters.',
            'operator_age.required' => 'Enter the operator’s age.',
            'operator_age.integer' => 'Age must be a whole number.',
            'operator_age.min' => 'Minors cannot be registered as operators. The skipper must be at least '.Operator::MIN_AGE.' years old.',
            'operator_age.max' => 'Enter a realistic age of '.Operator::MAX_AGE.' or below.',
            'operator_gender.required' => 'Select the operator’s gender.',
            'operator_contact_number.required' => 'Enter the operator’s contact number.',
            'operator_contact_number.regex' => 'Enter a valid Philippine mobile number (09XXXXXXXXX).',
            'operator_address.required' => 'Enter the operator’s complete address.',
            'operator_address.min' => 'Enter a complete residential address.',
            'operator_experience_years.required' => 'Enter the operator’s years of experience.',
            'operator_experience_years.integer' => 'Years of experience must be a whole number.',
            'operator_experience_years.min' => 'Years of experience cannot be negative.',
            'operator_emergency_contact_name.required' => 'Enter the emergency contact’s name.',
            'operator_emergency_contact_name.regex' => 'Emergency contact name must be at least 2 letters and cannot include numbers or special characters.',
            'operator_emergency_contact_number.required' => 'Enter the emergency contact’s mobile number.',
            'operator_emergency_contact_number.regex' => 'Enter a valid Philippine mobile number (09XXXXXXXXX).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function operatorAttributes(): array
    {
        $data = $this->validated();

        return [
            'first_name' => $data['operator_first_name'],
            'middle_name' => $data['operator_middle_name'] ?? null,
            'last_name' => $data['operator_last_name'],
            'full_name' => Operator::composeFullName(
                $data['operator_first_name'],
                $data['operator_middle_name'] ?? null,
                $data['operator_last_name'],
            ),
            'age' => $data['operator_age'],
            'gender' => $data['operator_gender'],
            'contact_number' => $data['operator_contact_number'],
            'address' => $data['operator_address'],
            'experience_years' => $data['operator_experience_years'] ?? null,
            'emergency_contact_name' => $data['operator_emergency_contact_name'] ?? null,
            'emergency_contact_number' => $data['operator_emergency_contact_number'] ?? null,
            'admin_use_only' => $this->boolean('admin_use_only'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $boat = $this->route('boat');
            $existing = $boat instanceof Boat ? $boat->pictures()->count() : 0;
            $removed = collect($this->input('removed_picture_ids', []))
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->count();
            $total = $existing - $removed + count($this->boatPictureFiles());

            if ($total > Boat::MAX_PICTURES) {
                $validator->errors()->add(
                    'boat_pictures',
                    'You can upload a maximum of '.Boat::MAX_PICTURES.' boat pictures.',
                );
            }

            $registration = BoatRegistrationStatus::tryFrom((string) $this->input('registration_status'));
            $operational = BoatStatus::tryFrom((string) $this->input('status'));

            if (
                $registration
                && $operational
                && ! $registration->allowsAvailability()
                && in_array($operational, [
                    BoatStatus::Available,
                    BoatStatus::OnDuty,
                    BoatStatus::OnTrip,
                ], true)
            ) {
                $validator->errors()->add(
                    'status',
                    'Only a registered boat can be available, on duty, or on trip.',
                );
            }
        });
    }

    /**
     * @return list<string>
     */
    public function selectedServices(): array
    {
        return array_values(array_intersect(
            BoatService::values(),
            $this->input('service_availability', []),
        ));
    }

    /**
     * @return list<int>
     */
    public function rentalDestinationIds(): array
    {
        if (! in_array(BoatService::PrivateBooking->value, $this->selectedServices(), true)) {
            return [];
        }

        return collect($this->input('rental_destination_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function legacyServiceAvailability(): array
    {
        return match ($this->input('service_type')) {
            BoatService::RegularTrip->value => [BoatService::RegularTrip->value],
            BoatService::PrivateBooking->value => [BoatService::PrivateBooking->value],
            'both' => BoatService::values(),
            default => [],
        };
    }

    /**
     * @return list<UploadedFile>
     */
    public function boatPictureFiles(): array
    {
        $files = $this->file('boat_pictures', []);

        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        if (! is_array($files)) {
            $files = [];
        }

        $single = $this->file('boat_picture');

        if ($single instanceof UploadedFile) {
            $files[] = $single;
        }

        return array_values(array_filter(
            $files,
            fn ($file) => $file instanceof UploadedFile,
        ));
    }
}
