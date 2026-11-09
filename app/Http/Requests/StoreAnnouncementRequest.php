<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Enums\WeatherHoldReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:140'],
            'body' => ['required', 'string', 'max:1000'],
            'category' => ['required', 'string', Rule::in([
                'weather',
                'delay',
                'resume',
                'general',
                'emergency',
            ])],
            'reason' => ['exclude_unless:category,weather', 'nullable', Rule::enum(WeatherHoldReason::class)],
            'affected_date' => ['exclude_unless:category,weather', 'nullable', 'date'],
            'affected_routes' => ['exclude_unless:category,weather', 'nullable', 'array', 'min:1'],
            'affected_routes.*' => ['exclude_unless:category,weather', 'required', 'string', 'max:120'],
            'duration_type' => ['exclude_unless:category,weather', 'nullable', 'in:whole_day,time_range'],
            'starts_at' => ['exclude_unless:category,weather', 'required_if:duration_type,time_range', 'nullable', 'date_format:H:i'],
            'ends_at' => ['exclude_unless:category,weather', 'required_if:duration_type,time_range', 'nullable', 'date_format:H:i', 'after:starts_at'],
            'audience' => ['required', 'array', 'min:1'],
            'audience.*' => ['required', 'string', Rule::in([
                UserRole::Operator->value,
                UserRole::User->value,
                UserRole::Passenger->value,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => 'message',
            'audience' => 'recipients',
        ];
    }
}
