<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\RentalCommission;
use App\Services\RentalCommissionService;
use App\Support\RentalCommissionPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->isMobileOperator()) {
            abort(403, 'Only boat operators can view commission settlements.');
        }

        $items = RentalCommission::query()
            ->withoutGlobalScope('organization')
            ->with(['booking.boat', 'operator', 'organization'])
            ->whereHas('operator', fn ($operator) => $operator->where('user_id', $user->id))
            ->latest('id')
            ->get()
            ->map(fn (RentalCommission $commission) => $this->transform($commission));

        return response()->json(['data' => $items]);
    }

    public function settle(Request $request, int $commission, RentalCommissionService $service): JsonResponse
    {
        $user = $request->user();

        if (! $user?->isMobileOperator()) {
            abort(403);
        }

        $data = $request->validate([
            'gcash_reference' => ['required', 'string', 'min:6', 'max:80'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $record = RentalCommission::query()
            ->withoutGlobalScope('organization')
            ->with('operator')
            ->findOrFail($commission);

        $updated = $service->submitSettlement(
            $record,
            $user,
            trim($data['gcash_reference']),
            $request->file('receipt'),
        );

        return response()->json(['data' => $this->transform($updated)]);
    }

    public function declareCash(Request $request, int $commission, RentalCommissionService $service): JsonResponse
    {
        $user = $request->user();

        if (! $user?->isMobileOperator()) {
            abort(403);
        }

        $record = RentalCommission::query()
            ->withoutGlobalScope('organization')
            ->with('operator')
            ->findOrFail($commission);

        $updated = $service->declareCashSettlement($record, $user);

        return response()->json(['data' => $this->transform($updated)]);
    }

    public function proof(Request $request, int $commission): StreamedResponse
    {
        $user = $request->user();
        $record = RentalCommission::query()
            ->withoutGlobalScope('organization')
            ->with('operator')
            ->findOrFail($commission);

        $allowed = $user->isMobileOperator()
            ? (int) $record->operator?->user_id === (int) $user->id
            : (int) $record->organization_id === (int) $user->organization_id;

        abort_unless($allowed, 403);

        $path = $record->settlement_proof_path;

        if (! filled($path)) {
            abort(404);
        }

        foreach (['public', 'local'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->response($path);
            }
        }

        abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(RentalCommission $commission): array
    {
        $organization = $commission->organization ?? Organization::query()->find($commission->organization_id);
        $host = request()->getSchemeAndHttpHost();
        $qr = $organization?->gcashQrUrl();

        return [
            ...RentalCommissionPresenter::forOperator($commission),
            'barangay_gcash_account_name' => $organization?->gcash_account_name,
            'barangay_gcash_qr_url' => $qr ? $host.$qr : null,
        ];
    }
}
