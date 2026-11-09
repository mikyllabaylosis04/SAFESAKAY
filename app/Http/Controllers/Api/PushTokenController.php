<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PushTokenController extends Controller
{
    private const TOKEN_RULES = ['required', 'string', 'max:191', 'regex:/^Expo(nent)?PushToken\[[A-Za-z0-9_\-]+\]$/'];

    /**
     * POST /api/push-tokens — register this device for the signed-in user.
     * A token moves to whoever signed in last on that phone.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => self::TOKEN_RULES,
            'platform' => ['nullable', 'string', Rule::in(['android', 'ios'])],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        PushToken::query()->updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'] ?? null,
                'device_name' => $data['device_name'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /api/push-tokens — stop pushes to this device (called on logout).
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => self::TOKEN_RULES,
        ]);

        $deleted = $request->user()->pushTokens()->where('token', $data['token'])->delete();

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }
}
