<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserService
{
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

        if (!empty($filters['role']) && $filters['role'] !== 'all') {
            $query->where('role', $filters['role']);
        }

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

    /**
     * Get summary stats for the Users page.
     */
    public function getUserStats(array $filters = []): array
    {
        $baseQuery = User::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $baseQuery->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['role']) && $filters['role'] !== 'all') {
            $baseQuery->where('role', $filters['role']);
        }

        return [
            'total'  => (clone $baseQuery)->count(),
            'admins' => (clone $baseQuery)->where('role', 'admin')->count(),
            'riders' => (clone $baseQuery)->where('role', 'rider')->count(),
        ];
    }

    public function createUser(array $data): User
    {
        $data['password']          = Hash::make($data['password']);
        $data['email_verified_at'] = now();
        $data = $this->computeAddress($data);
        return User::create($data);
    }

    public function updateUser(User $user, array $data): bool
    {
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        $data = $this->computeAddress($data);
        return $user->update($data);
    }

    public function updateAvatar(User $user, string $path): void
    {
        $user->update(['avatar_path' => $path]);
    }

    public function updatePassword(User $user, string $newPassword): void
    {
        $user->update(['password' => Hash::make($newPassword)]);
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
    public function deleteUser(User $user): ?bool
    {
        return $user->delete();
    }
}
