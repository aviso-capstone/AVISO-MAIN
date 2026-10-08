<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = [
        'confidence_threshold',
        'items_per_page',
        'default_sort',
        'emergency_hazard_types',
    ];

    protected function casts(): array
    {
        return [
            'emergency_hazard_types' => 'array',
            'confidence_threshold'   => 'integer',
            'items_per_page'         => 'integer',
        ];
    }

    public static function instance(): self
    {
        return static::firstOrCreate([], [
            'confidence_threshold'   => 0,
            'items_per_page'         => 15,
            'default_sort'           => 'detected_at',
            'emergency_hazard_types' => ['Pothole', 'Road Excavation', 'Road Barrier'],
        ]);
    }
}
