<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rider\StoreEmergencyContactRequest;
use App\Http\Requests\Rider\UpdateEmergencyContactRequest;
use App\Models\EmergencyContact;
use App\Services\EmergencyContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class EmergencyContactController extends Controller
{
    public function __construct(private EmergencyContactService $emergencyContactService)
    {
    }

    public function index(): JsonResponse
    {
        $contacts = $this->emergencyContactService->getForRider(Auth::user());

        return response()->json(['success' => true, 'contacts' => $contacts]);
    }

    public function store(StoreEmergencyContactRequest $request): JsonResponse
    {
        $contact = $this->emergencyContactService->create(Auth::user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Emergency contact added successfully.',
            'contact' => $contact,
        ], 201);
    }

    public function update(UpdateEmergencyContactRequest $request, EmergencyContact $contact): JsonResponse
    {
        abort_if($contact->user_id !== Auth::id(), 403, 'Forbidden.');

        $contact = $this->emergencyContactService->update($contact, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Emergency contact updated.',
            'contact' => $contact,
        ]);
    }

    public function destroy(EmergencyContact $contact): JsonResponse
    {
        abort_if($contact->user_id !== Auth::id(), 403, 'Forbidden.');

        $this->emergencyContactService->delete($contact);

        return response()->json(['success' => true, 'message' => 'Emergency contact deleted.']);
    }
}
