<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $boatId = $this->input('boat_id');

        if ($boatId === '' || $boatId === 'none') {
            $this->merge(['boat_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->route('user');
        $userId = $user instanceof User ? $user->id : null;
        $isUpdate = $userId !== null;

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'role' => ['required', Rule::enum(UserRole::class)->only(UserRole::assignable())],
            'boat_id' => [
                'nullable',
                'integer',
                Rule::exists('boats', 'id')->where(
                    fn ($query) => $query->where('organization_id', $this->user()?->organization_id),
                ),
            ],
            'is_active' => ['required', 'boolean'],
            'password' => $isUpdate
                ? ['nullable', 'string', Password::default(), 'confirmed']
                : ['required', 'string', Password::default(), 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'is_active' => 'status',
            'boat_id' => 'boat',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'boat_id.exists' => 'Select a registered boat from this organization.',
        ];
    }
}
