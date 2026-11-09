<?php

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePlatformOrganizationRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('organization_address')) {
            return;
        }

        $parts = array_filter([
            $this->input('address_barangay'),
            $this->input('address_city'),
            $this->input('address_province'),
            $this->input('address_region'),
        ], fn ($value) => is_string($value) && trim($value) !== '');

        if ($parts === []) {
            return;
        }

        $this->merge([
            'organization_address' => implode(', ', $parts),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'max:160'],
            'organization_address' => ['required', 'string', 'max:255'],
            'address_region' => ['nullable', 'string', 'max:120'],
            'address_province' => ['nullable', 'string', 'max:120'],
            'address_city' => ['nullable', 'string', 'max:120'],
            'address_barangay' => ['nullable', 'string', 'max:120'],
            'contact_number' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => $this->passwordRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organization_name' => 'barangay / organization name',
            'organization_address' => 'address',
            'contact_number' => 'contact number',
            'name' => 'admin name',
            'email' => 'admin email',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $taken = Organization::locationAlreadyRegistered(
                    (string) $this->input('organization_name', ''),
                    (string) $this->input('organization_address', ''),
                    [
                        'region' => $this->input('address_region'),
                        'province' => $this->input('address_province'),
                        'city' => $this->input('address_city'),
                        'barangay' => $this->input('address_barangay'),
                    ],
                );

                if (! $taken) {
                    return;
                }

                $message = 'This barangay address is already registered.';
                $validator->errors()->add('organization_address', $message);
                $validator->errors()->add('organization_name', $message);
            },
        ];
    }
}
