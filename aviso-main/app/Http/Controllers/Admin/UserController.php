<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetUserActiveRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
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

    public function index(\Illuminate\Http\Request $request): Response
    {
        $filters = $request->only(['search', 'role', 'sort']);
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
        $this->userService->createAdmin($request->validated());

        return redirect()->route('users.index', ['role' => 'admin'])->with('success', 'Admin account created.');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->userService->updateUser($user, $request->validated());

        return back()->with('success', 'User updated successfully.');
    }

    /** Deactivate or reactivate an admin account. */
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

    /** Riders only: admins are deactivated, so their name stays on what they handled. */
    public function destroy(User $user): RedirectResponse
    {
        if ($user->role !== 'rider') {
            return back()->with('error', 'Admin accounts cannot be deleted. Deactivate the admin instead.');
        }

        $this->userService->deleteUser($user);

        return back()->with('success', 'Rider deleted successfully.');
    }
}
