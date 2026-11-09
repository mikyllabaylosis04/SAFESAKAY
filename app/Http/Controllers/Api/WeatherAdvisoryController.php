<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RentalBlackoutService;
use App\Services\WeatherSuspensionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeatherAdvisoryController extends Controller
{
    public function index(Request $request, WeatherSuspensionService $weather, RentalBlackoutService $blackouts): JsonResponse
    {
        $organizationId = $request->user()?->organization_id;

        if (! $organizationId) {
            return response()->json(['data' => []]);
        }

        $marks = [];

        foreach ($weather->calendarMarks((int) $organizationId) as $mark) {
            $marks[$mark['affected_date']] = $mark;
        }

        foreach ($blackouts->calendarMarks((int) $organizationId) as $mark) {
            $date = $mark['affected_date'];
            if (($marks[$date]['tone'] ?? null) !== 'red') {
                $marks[$date] = $mark;
            }
        }

        ksort($marks);

        return response()->json([
            'data' => array_values($marks),
        ]);
    }
}
