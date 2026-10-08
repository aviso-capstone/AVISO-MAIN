<?php

namespace App\Http\Requests\Rider;

use Illuminate\Foundation\Http\FormRequest;

class StartTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // The phone's start time, so a ride uploaded later still starts when it really did.
            'started_at' => ['nullable', 'date', 'before_or_equal:now', 'after:-3 days'],
        ];
    }
}
