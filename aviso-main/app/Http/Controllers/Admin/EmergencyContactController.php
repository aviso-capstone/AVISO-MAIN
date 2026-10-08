<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEmergencyContactRequest;
use App\Models\EmergencyContact;
use App\Models\User;
use App\Services\EmergencyContactService;
use Illuminate\Http\JsonResponse;

class EmergencyContactController extends Controller
{
    public function __construct(
        protected EmergencyContactService $emergencyContactService,
    ) {}

    public function index(User $user): JsonResponse
    {
        return response()->json([
            'success'  => true,
            'contacts' => $this->emergencyContactService->getForRider($user),
        ]);
    }

    public function store(StoreEmergencyContactRequest $request, User $user): JsonResponse
    {
        $contact = $this->emergencyContactService->create($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Emergency contact added successfully.',
            'contact' => $contact,
        ], 201);
    }

    public function destroy(EmergencyContact $contact): JsonResponse
    {
        $this->emergencyContactService->delete($contact);

        return response()->json([
            'success' => true,
            'message' => 'Emergency contact deleted successfully.',
        ]);
    }
}
