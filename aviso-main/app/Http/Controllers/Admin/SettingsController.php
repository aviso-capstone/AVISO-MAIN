<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePasswordRequest;
use App\Http\Requests\Admin\UpdateSettingsProfileRequest;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __construct(
        protected UserService $userService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('main/Settings');
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
}
