<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePasswordRequest;
use App\Http\Requests\Admin\UpdateSettingsProfileRequest;
use App\Http\Requests\Admin\UpdateSystemSettingsRequest;
use App\Services\SystemSettingService;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __construct(
        protected SystemSettingService $settingService,
        protected UserService $userService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('main/Settings', [
            'systemSettings' => $this->settingService->get(),
        ]);
    }

    public function updateProfile(UpdateSettingsProfileRequest $request): RedirectResponse
    {
        $this->userService->updateUser($request->user(), $request->validated());

        return back()->with('success', 'Profile updated successfully.');
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $this->userService->updatePassword($request->user(), $request->validated()['password']);

        return back()->with('success', 'Password changed successfully.');
    }

    public function updateSystem(UpdateSystemSettingsRequest $request): RedirectResponse
    {
        $this->settingService->update($request->validated());

        return back()->with('success', 'System settings saved.');
    }
}
