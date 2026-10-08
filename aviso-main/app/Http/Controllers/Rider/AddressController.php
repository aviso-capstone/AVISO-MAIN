<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Services\AddressService;
use Illuminate\Http\JsonResponse;

class AddressController extends Controller
{
    public function __construct(
        protected AddressService $addressService,
    ) {}

    public function provinces(): JsonResponse
    {
        return response()->json($this->addressService->provinces());
    }

    public function cities(string $province): JsonResponse
    {
        return response()->json($this->addressService->cities($province));
    }

    public function barangays(string $city): JsonResponse
    {
        return response()->json($this->addressService->barangays($city));
    }
}
