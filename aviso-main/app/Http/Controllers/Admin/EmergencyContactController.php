<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEmergencyContactRequest;
use App\Models\EmergencyContact;
use App\Models\User;
use App\Notifications\RiderNotifications;
use App\Services\EmergencyContactService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;

class EmergencyContactController extends Controller
{
    public function __construct(
        protected EmergencyContactService $emergencyContactService,
        protected NotificationService $notifications,
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
        $this->notifications->notifyUser($user, RiderNotifications::contactAdded($contact->name));

        return response()->json([
            'success' => true,
            'message' => 'Emergency contact added successfully.',
            'contact' => $contact,
        ], 201);
    }

    public function destroy(EmergencyContact $contact): JsonResponse
    {
        $rider = $contact->user;
        $name  = $contact->name;

        $this->emergencyContactService->delete($contact);
        $this->notifications->notifyUser($rider, RiderNotifications::contactRemoved($name));

        return response()->json([
            'success' => true,
            'message' => 'Emergency contact deleted successfully.',
        ]);
    }
}
