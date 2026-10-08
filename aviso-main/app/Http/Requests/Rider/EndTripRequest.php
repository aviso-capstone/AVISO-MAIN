<?php

namespace App\Http\Requests\Rider;

use App\Models\Trip;
use Illuminate\Foundation\Http\FormRequest;

class EndTripRequest extends FormRequest
{
    /** Only the rider who owns the trip may change it. */
    public function authorize(): bool
    {
        $trip = $this->route('trip');

        return $trip instanceof Trip && (int) $trip->user_id === (int) $this->user()?->id;
    }

    public function rules(): array
    {
        return [
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // Sent by the phone so a ride ended offline and uploaded later keeps its real end time and route.
            'ended_at'           => ['nullable', 'date', 'before_or_equal:now'],
            'route_points'       => ['nullable', 'array', 'max:10000'],
            'route_points.*.lat' => ['required_with:route_points', 'numeric', 'between:-90,90'],
            'route_points.*.lng' => ['required_with:route_points', 'numeric', 'between:-180,180'],
        ];
    }
}
