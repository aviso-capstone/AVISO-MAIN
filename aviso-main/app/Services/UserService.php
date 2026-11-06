<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AdminNotifications;
use App\Notifications\RiderNotifications;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserService
{
    public function __construct(private NotificationService $notifications)
    {
        //
    }

    /**
     * Get paginated and filtered users for the Admin Panel.
     */
    public function getPaginatedUsers(array $filters): LengthAwarePaginator
    {
        $query = User::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // The page has two tabs, Riders and Admins; never both in one list.
        $query->where('role', self::tab($filters));

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column    = ltrim($sort, '-');

        $allowedSorts = ['first_name', 'username', 'email', 'role', 'created_at'];
        if (in_array($column, $allowedSorts)) {
            $query->orderBy($column, $direction);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        return $query->paginate(15)->withQueryString();
    }

    /** The Users page tab: "rider" (default) or "admin". */
    public static function tab(array $filters): string
    {
        return ($filters['role'] ?? null) === 'admin' ? 'admin' : 'rider';
    }

    /**
     * Count cards for the Users page (whole system, not the search).
     *
     * @return array{riders: int, admins: int, active_admins: int}
     */
    public function getUserStats(): array
    {
        $admins = User::where('role', 'admin');

        return [
            'riders'        => User::where('role', 'rider')->count(),
            'admins'        => (clone $admins)->count(),
            'active_admins' => (clone $admins)->where('is_active', true)->count(),
        ];
    }

    /** A new admin account, with the password the adding admin chose. */
    public function createAdmin(array $data, User $actor): User
    {
        $data['password']          = Hash::make($data['password']);
        $data['role']              = 'admin';
        $data['is_active']         = true;
        $data['email_verified_at'] = now();

        $admin = User::create($data);
        $this->notifications->notifyAdmins(AdminNotifications::adminAdded($admin, $actor));

        return $admin;
    }

    /**
     * Deactivate or reactivate an admin. A deactivated admin is signed out
     * everywhere and can't log in; their name stays on the SOS alerts,
     * hazards and reports they handled.
     *
     * @throws \DomainException with a message for the admin
     */
    public function setActive(User $admin, bool $active, User $actor): void
    {
        if (! in_array($admin->role, ['admin', 'rider'], true)) {
            throw new \DomainException('This account cannot be deactivated.');
        }

        $isAdmin = $admin->role === 'admin';

        if (! $active && $isAdmin) {
            if ($admin->is($actor)) {
                throw new \DomainException('You cannot deactivate your own account.');
            }

            $otherActiveAdmins = User::where('role', 'admin')
                ->where('is_active', true)
                ->whereKeyNot($admin->id)
                ->count();

            if ($otherActiveAdmins === 0) {
                throw new \DomainException('You cannot deactivate the last active admin.');
            }
        }

        if ($admin->isDeactivated() === ! $active) {
            return; // already in that state: nothing to tell anyone
        }

        $admin->update(['is_active' => $active]);

        if (! $isAdmin) {
            // The app is signed out on its next request (RequireRiderRole),
            // which also shows the rider why. Their records stay linked.
            $this->notifications->notifyAdmins(AdminNotifications::riderActiveChanged($admin, $active, $actor));

            return;
        }

        if (! $active) {
            $this->signOutOtherSessions($admin);
        }

        $this->notifications->notifyAdmins(AdminNotifications::adminActiveChanged($admin, $active, $actor));
    }

    /**
     * $actor is the admin editing someone else's account from the Users page
     * (they get notified); null when people edit their own account.
     */
    public function updateUser(User $user, array $data, ?User $actor = null): bool
    {
        $passwordChanged = !empty($data['password']);

        if ($passwordChanged) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        $data = $this->computeAddress($data);
        $updated = $user->update($data);

        if ($passwordChanged) {
            $this->signOutOtherSessions($user);
        }

        if ($actor && ! $actor->is($user)) {
            $this->notifyAccountEdited($user, $actor, $passwordChanged);
        }

        return $updated;
    }

    public function updateAvatar(User $user, string $path): void
    {
        $user->update(['avatar_path' => $path]);
    }

    public function updatePassword(User $user, string $newPassword): void
    {
        $user->update(['password' => Hash::make($newPassword)]);
        $this->signOutOtherSessions($user);
    }

    /**
     * After a password change, end every other login of this account — open
     * browser sessions and "remember me" cookies — so whoever knew the old
     * password is kicked out. The person making the change stays signed in.
     */
    private function signOutOtherSessions(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        if (config('session.driver') !== 'database') {
            return;
        }

        $currentSessionId = Auth::id() === $user->id ? session()->getId() : null;

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($currentSessionId, fn ($query) => $query->where('id', '!=', $currentSessionId))
            ->delete();
    }

    private function computeAddress(array $data): array
    {
        if (empty($data['barangay_id'])) {
            return $data;
        }
        $barangayName = DB::table('barangays')->where('code', $data['barangay_id'])->value('name');
        if (!$barangayName) {
            return $data;
        }
        $parts = [];
        if (!empty($data['street'])) {
            $parts[] = $data['street'];
        }
        $parts[]        = 'Brgy. ' . $barangayName;
        $parts[]        = 'Zamboanga City';
        $data['address'] = implode(', ', $parts);
        return $data;
    }

    /**
     * Delete a user.
     */
    private function notifyAccountEdited(User $user, User $actor, bool $passwordChanged): void
    {
        if ($user->role === 'rider') {
            $this->notifications->notifyUser($user, RiderNotifications::detailsUpdated());

            return;
        }

        $this->notifications->notifyUser($user, $passwordChanged
            ? AdminNotifications::myPasswordChanged($actor)
            : AdminNotifications::myDetailsChanged($actor));
    }
}
