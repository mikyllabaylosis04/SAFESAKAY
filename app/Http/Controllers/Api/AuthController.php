<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Api\Concerns\ReturnsMobileUser;
use App\Http\Controllers\Controller;
use App\Models\BoatBooking;
use App\Models\Organization;
use App\Models\User;
use App\Services\PasswordResetCodeService;
use App\Support\PassengerHelpContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    use ReturnsMobileUser;

    private const PH_MOBILE = '/^(\+?63|0)9\d{9}$/';

    public function register(Request $request): JsonResponse
    {
        $this->stripPhoneFormatting($request, ['contact_number']);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'regex:'.self::PH_MOBILE],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ], [
            'contact_number.regex' => 'Enter a valid mobile number, like 09171234567.',
        ]);

        $organization = isset($data['organization_id'])
            ? Organization::query()->tenants()->where('is_active', true)->find($data['organization_id'])
            : Organization::defaultServiceArea();

        if (isset($data['organization_id']) && ! $organization) {
            throw ValidationException::withMessages([
                'organization_id' => ['This organization is not available.'],
            ]);
        }

        $address = trim((string) ($data['address'] ?? ''));
        if ($address === '') {
            $address = $organization?->address ?: ($organization?->name ?? 'Barangay Lakandula');
        }

        $user = User::query()->create([
            'organization_id' => $organization?->id,
            'name' => $data['name'],
            'username' => User::uniqueUsernameFromEmail($data['email']),
            'email' => $data['email'],
            'address' => $address,
            'contact_number' => self::normalizePhone($data['contact_number'] ?? null),
            'password' => $data['password'],
            'role' => UserRole::Passenger,
            'is_active' => true,
        ]);

        $token = $user->createToken('mobile')->plainTextToken;

        return $this->authResponse($user, $token)->setStatusCode(201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $login = trim($data['username']);
        $email = Str::lower($login);

        $user = User::query()
            ->where(function ($query) use ($login, $email) {
                $query->where('email', $email)
                    ->orWhere('username', $login);
            })
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['The username or password is incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'username' => ['This account is inactive. Ask an administrator to restore access.'],
            ]);
        }

        if ($message = $user->operatorSuspensionDenialMessage()) {
            throw ValidationException::withMessages([
                'username' => [$message],
            ]);
        }

        $this->attachCustomerOrganization($user);

        $user->tokens()->where('name', 'mobile')->delete();
        $token = $user->createToken('mobile')->plainTextToken;

        return $this->authResponse($user->fresh('organization'), $token);
    }

    public function forgotPassword(Request $request, PasswordResetCodeService $codes): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $email = Str::lower($data['email']);
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['No SafeSakay account uses this email. Use the email on the account, not any Gmail.'],
            ]);
        }

        try {
            $codes->send($user);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Could not send the reset code to '.$user->email.'. Check the Gmail mail settings in .env.',
            ], 500);
        }

        return response()->json([
            'message' => 'We sent a reset code to '.$user->email.'. Open that inbox (and Spam), not the SafeSakay Gmail.',
        ]);
    }

    public function verifyResetCode(Request $request, PasswordResetCodeService $codes): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $codes->verify($data['email'], $data['code']);

        return response()->json([
            'message' => 'Code verified.',
            'verified' => true,
        ]);
    }

    public function resetPassword(Request $request, PasswordResetCodeService $codes): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        $user = $codes->verify($data['email'], $data['code']);

        $user->update(['password' => $data['password']]);
        $user->tokens()->delete();

        $codes->forget($data['email']);

        return response()->json([
            'message' => 'Password reset. You can now log in.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->mobileUser($request->user()),
        ]);
    }

    public function updateServiceArea(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
        ]);

        $organization = Organization::query()
            ->tenants()
            ->whereKey($data['organization_id'])
            ->where('is_active', true)
            ->whereNotNull('approved_at')
            ->first();

        if (! $organization) {
            throw ValidationException::withMessages([
                'organization_id' => ['This service area is not available.'],
            ]);
        }

        $user = $request->user();
        $user->update(['organization_id' => $organization->id]);

        return response()->json([
            'user' => $this->mobileUser($user->fresh('organization')),
        ]);
    }

    public function updateName(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $user = $request->user();
        $user->update(['name' => $data['name']]);

        return response()->json([
            'user' => $this->mobileUser($user->fresh('organization')),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $isOperator = $user->isMobileOperator();
        $this->stripPhoneFormatting($request, ['contact_number', 'emergency_contact_number']);

        $data = $request->validate([
            'name' => [$isOperator ? 'nullable' : 'required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'regex:'.self::PH_MOBILE],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_number' => ['nullable', 'string', 'regex:'.self::PH_MOBILE],
        ], [
            'contact_number.regex' => 'Enter a valid mobile number, like 09171234567.',
            'emergency_contact_number.regex' => 'Enter a valid mobile number, like 09171234567.',
        ]);

        $contact = [
            'contact_number' => self::normalizePhone($data['contact_number'] ?? null),
            'emergency_contact_name' => filled($data['emergency_contact_name'] ?? null) ? trim((string) $data['emergency_contact_name']) : null,
            'emergency_contact_number' => self::normalizePhone($data['emergency_contact_number'] ?? null),
        ];
        $address = filled($data['address'] ?? null) ? trim((string) $data['address']) : null;

        $user->update([
            'name' => $isOperator ? $user->name : trim((string) $data['name']),
            'email' => Str::lower(trim($data['email'])),
            'address' => $address ?? $user->address,
            ...$contact,
        ]);

        if ($isOperator) {
            $user->operatorProfiles()->each(function ($operator) use ($contact, $address) {
                $operator->update([
                    ...array_filter($contact, fn ($value) => $value !== null),
                    'emergency_contact_name' => $contact['emergency_contact_name'] ?? $operator->emergency_contact_name,
                    'emergency_contact_number' => $contact['emergency_contact_number'] ?? $operator->emergency_contact_number,
                    'address' => $address ?? $operator->address,
                ]);
            });
        }

        return response()->json([
            'message' => 'Profile updated.',
            'user' => $this->mobileUser($user->fresh('organization')),
        ]);
    }

    public function updatePhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $previous = $user->picture_path;
        $path = $request->file('photo')->store('profile-photos', 'public');

        $user->update(['picture_path' => $path]);

        if ($user->isMobileOperator()) {
            $user->operatorProfiles()->update(['picture_path' => $path]);
        }

        if (filled($previous) && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return response()->json([
            'message' => 'Profile photo updated.',
            'user' => $this->mobileUser($user->fresh('organization')),
        ]);
    }

    public function help(Request $request): JsonResponse
    {
        $user = auth('sanctum')->user();
        $organizationId = $user?->organization_id ?? $request->integer('organization_id');
        $organization = $organizationId ? Organization::query()->find($organizationId) : null;

        return response()->json([
            'data' => PassengerHelpContent::payload($organization),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $data['password']]);

        return response()->json([
            'message' => 'Password updated.',
            'user' => $this->mobileUser($user->fresh('organization')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * @param  list<string>  $fields
     */
    private function stripPhoneFormatting(Request $request, array $fields): void
    {
        foreach ($fields as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => preg_replace('/[\s\-().]+/', '', $request->input($field))]);
            }
        }
    }

    private static function normalizePhone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if ($digits === '') {
            return null;
        }

        return str_starts_with($digits, '63') ? '0'.substr($digits, 2) : $digits;
    }

    private function attachCustomerOrganization(User $user): void
    {
        if (! $user->isCustomer() || $user->organization_id) {
            return;
        }

        $organizationId = BoatBooking::query()
            ->withoutGlobalScope('organization')
            ->where('user_id', $user->id)
            ->whereNotNull('organization_id')
            ->latest('id')
            ->value('organization_id');

        $user->attachToOrganization(
            $organizationId
                ? (int) $organizationId
                : Organization::defaultServiceArea()?->id
        );
    }
}
