<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Collection;
use Yajra\Address\Entities\Barangay;
use Yajra\Address\Entities\City;
use Yajra\Address\Entities\Province;

class AddressService
{
    public function provinces(): Collection
    {
        return Province::orderBy('name')->get(['province_id', 'name', 'region_id']);
    }

    public function cities(string $provinceId): Collection
    {
        return City::where('province_id', $provinceId)->orderBy('name')->get(['city_id', 'name']);
    }

    public function barangays(string $cityId): Collection
    {
        return Barangay::where('city_id', $cityId)->orderBy('name')->get(['code', 'name']);
    }
}
