<?php

namespace App\Http\Requests\Rider;

use Illuminate\Foundation\Http\FormRequest;

/** "Hazard no longer there" report: a photo of the spot is required as proof. */
class StoreHazardReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo'     => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'latitude'  => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'note'      => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'Please take a photo of the spot.',
            'photo.max'      => 'The photo is too large (2 MB maximum).',
        ];
    }
}
