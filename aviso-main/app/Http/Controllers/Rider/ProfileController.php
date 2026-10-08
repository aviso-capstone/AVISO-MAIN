<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rider\UpdatePersonalInfoRequest;
use App\Http\Requests\Rider\UpdateProfileRequest;
use App\Http\Requests\Rider\UploadAvatarRequest;
use App\Services\SecureFileUploader;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function __construct(
        protected UserService $userService,
        protected SecureFileUploader $fileUploader,
    ) {}

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = Auth::user();
        $this->userService->updateUser($user, $request->validated());
        $user->refresh();

        return response()->json([
            'username'    => $user->username,
            'street'      => $user->street,
            'barangay_id' => $user->barangay_id,
            'city_id'     => $user->city_id,
            'province_id' => $user->province_id,
            'region_id'   => $user->region_id,
            'address'     => $user->address,
        ]);
    }

    public function updatePersonalInfo(UpdatePersonalInfoRequest $request): JsonResponse
    {
        $user = Auth::user();
        $this->userService->updateUser($user, $request->validated());
        $user->refresh();

        return response()->json([
            'first_name'     => $user->first_name,
            'last_name'      => $user->last_name,
            'email'          => $user->email,
            'contact_number' => $user->contact_number,
        ]);
    }

    public function uploadAvatar(UploadAvatarRequest $request): JsonResponse
    {
        $user = Auth::user();
        $path = $this->fileUploader->upload($request->file('avatar'), 'rider_avatars');
        $this->userService->updateAvatar($user, $path);

        return response()->json(['avatar_url' => $user->avatar_url]);
    }
}
