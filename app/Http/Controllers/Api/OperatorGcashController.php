<?php

namespace App\Http\Controllers\Api;

use App\Enums\OperatorGcashStatus;
use App\Http\Controllers\Controller;
use App\Models\Operator;
use App\Models\OperatorGcashAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperatorGcashController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $operator = $this->operatorFor($request->user());

        return response()->json(['data' => $this->payload($operator)]);
    }

    public function update(Request $request): JsonResponse
    {
        $operator = $this->operatorFor($request->user());

        $data = $request->validate([
            'account_name' => ['required', 'string', 'min:2', 'max:120'],
            'mobile_number' => ['required', 'string', 'regex:'.Operator::MOBILE_REGEX],
            'qr' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'status' => ['prohibited'],
        ], [
            'mobile_number.regex' => 'Enter an 11-digit GCash mobile number starting with 09.',
            'status.prohibited' => 'Operators cannot approve their own GCash account.',
        ]);

        $payload = DB::transaction(function () use ($request, $operator, $data) {
            $operator = Operator::query()->whereKey($operator->id)->lockForUpdate()->firstOrFail();
            $pending = $operator->pendingGcashAccount();
            $approved = $operator->approvedGcashAccount();
            $qrPath = $this->resolveQrPath($request, $operator, $pending, $approved);

            if ($pending) {
                if ($request->hasFile('qr') && $pending->qr_path && $pending->qr_path !== $approved?->qr_path) {
                    Storage::disk('public')->delete($pending->qr_path);
                }

                $pending->update([
                    'organization_id' => $request->user()?->organization_id,
                    'account_name' => trim($data['account_name']),
                    'mobile_number' => Operator::sanitizeMobile($data['mobile_number']),
                    'qr_path' => $qrPath,
                    'status' => OperatorGcashStatus::PendingApproval,
                    'submitted_at' => now(),
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                    'rejection_reason' => null,
                ]);
            } else {
                OperatorGcashAccount::query()->create([
                    'operator_id' => $operator->id,
                    'organization_id' => $request->user()?->organization_id,
                    'account_name' => trim($data['account_name']),
                    'mobile_number' => Operator::sanitizeMobile($data['mobile_number']),
                    'qr_path' => $qrPath,
                    'status' => OperatorGcashStatus::PendingApproval,
                    'submitted_at' => now(),
                ]);
            }

            $operator->unsetRelation('gcashAccounts');

            return $this->payload($operator->fresh());
        });

        return response()->json([
            'message' => 'GCash details submitted for approval.',
            'data' => $payload,
        ]);
    }

    public function qr(Request $request): StreamedResponse
    {
        $operator = $this->operatorFor($request->user());
        $accountId = (int) $request->query('account');
        $account = $operator->gcashAccounts()->findOrFail($accountId);

        if (! Storage::disk('public')->exists($account->qr_path)) {
            abort(404);
        }

        return Storage::disk('public')->response($account->qr_path);
    }

    private function operatorFor(?User $user): Operator
    {
        if (! $user?->isMobileOperator()) {
            abort(403, 'Only boat operators can manage GCash payment settings.');
        }

        $operator = $user->operatorProfiles()->first();

        if (! $operator instanceof Operator) {
            abort(403, 'No boat operator profile is linked to this account.');
        }

        return $operator;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Operator $operator): array
    {
        $operator->load('gcashAccounts');
        $pending = $operator->pendingGcashAccount();
        $approved = $operator->approvedGcashAccount();
        $rejected = $pending || $approved ? null : $operator->latestRejectedGcashAccount();
        $form = $pending ?? $approved ?? $rejected;

        $status = match (true) {
            $pending !== null => OperatorGcashStatus::PendingApproval,
            $approved !== null => OperatorGcashStatus::Approved,
            $rejected !== null => OperatorGcashStatus::Rejected,
            default => null,
        };

        return [
            'status' => $status?->value ?? 'not_registered',
            'status_label' => $status?->label() ?? 'Not Registered',
            'can_approve' => false,
            'form' => $form?->toRecord(),
            'pending' => $pending?->toRecord(),
            'approved' => $approved?->toRecord(),
            'rejected' => $rejected?->toRecord(),
        ];
    }

    private function resolveQrPath(
        Request $request,
        Operator $operator,
        ?OperatorGcashAccount $pending,
        ?OperatorGcashAccount $approved,
    ): string {
        $directory = 'operator-gcash/'.$operator->id;

        if ($request->hasFile('qr')) {
            return $request->file('qr')->store($directory, 'public');
        }

        if ($pending?->qr_path) {
            return $pending->qr_path;
        }

        if ($approved?->qr_path) {
            return $approved->duplicateQrTo($directory);
        }

        throw ValidationException::withMessages([
            'qr' => ['Upload a GCash QR code.'],
        ]);
    }
}
