<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetUserActiveRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UserFilterRequest;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(private UserService $userService)
    {
    }

    public function index(UserFilterRequest $request): Response
    {
        $filters = $request->safe()->only(['search', 'role', 'sort']);
        $filters['role'] = UserService::tab($filters);

        return Inertia::render('main/Users', [
            'users'   => $this->userService->getPaginatedUsers($filters),
            'stats'   => $this->userService->getUserStats(),
            'filters' => $filters,
        ]);
    }

    /** Adds an admin. Riders create their own account in the app. */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->userService->createAdmin($request->validated(), $request->user());

        return redirect()->route('users.index', ['role' => 'admin'])->with('success', 'Admin account created.');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->userService->updateUser($user, $request->validated(), $request->user());

        return back()->with('success', 'User updated successfully.');
    }

    /**
     * Deactivate or reactivate an admin or rider. Accounts are never deleted,
     * so hazards, trips, crash logs and SOS history keep their owner.
     */
    public function setActive(SetUserActiveRequest $request, User $user): RedirectResponse
    {
        $active = $request->boolean('is_active');

        try {
            $this->userService->setActive($user, $active, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $name = trim("{$user->first_name} {$user->last_name}");

        return back()->with('success', $active ? "{$name} was reactivated." : "{$name} was deactivated.");
    }
}
