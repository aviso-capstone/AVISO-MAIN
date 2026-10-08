<?php

namespace App\Http\Requests\Rider;

use App\Models\Trip;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationRequest extends FormRequest
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
        ];
    }
}
