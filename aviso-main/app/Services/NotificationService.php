<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AvisoNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Sends and lists the Notifications page items (admin website and rider app).
 *
 * Sending is best-effort: a failure is logged and never breaks the action that
 * caused it (an SOS, a resolve, a sign-up). That also covers the minutes after
 * a deploy when the web server runs before the notifications table exists.
 */
class NotificationService
{
    public const PER_PAGE = 20;

    /** Date filters are Philippine days. */
    private const DAY_TIMEZONE = 'Asia/Manila';

    /** Every active admin. The admin who did it gets their copy already read. */
    public function notifyAdmins(AvisoNotification $notification): void
    {
        $this->safely(function () use ($notification) {
            $admins = User::where('role', 'admin')->where('is_active', true)->get();
            Notification::send($admins, $notification);
        });
    }

    public function notifyUser(?User $user, AvisoNotification $notification): void
    {
        if ($user === null || $user->isDeactivated()) {
            return;
        }

        $this->safely(fn () => $user->notify($notification));
    }

    /**
     * Newest first, filtered by the page's chips.
     *
     * @param array{category?: ?string, unread?: bool, from?: ?string, to?: ?string} $filters
     */
    public function list(User $user, array $filters): LengthAwarePaginator
    {
        return $user->notifications()
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($filters['unread'] ?? false, fn ($q) => $q->whereNull('read_at'))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from, self::DAY_TIMEZONE)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to, self::DAY_TIMEZONE)->endOfDay()->utc()))
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (DatabaseNotification $n) => self::toPayload($n));
    }

    /** 0 when anything goes wrong, so a missing table never breaks a page. */
    public function unreadCount(?User $user): int
    {
        if ($user === null) {
            return 0;
        }

        try {
            return $user->unreadNotifications()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Only the user's own notification; anything else is a 404. */
    public function markRead(User $user, string $id): void
    {
        $user->notifications()->whereKey($id)->firstOrFail()->markAsRead();
    }

    public function markAllRead(User $user): void
    {
        $user->unreadNotifications()->update(['read_at' => now()]);
    }

    /** The shape both Notifications pages read. */
    public static function toPayload(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        return [
            'id'          => $notification->id,
            'category'    => $notification->category,
            'context_key' => $notification->context_key,
            'event'       => $data['event'] ?? null,
            'title'       => $data['title'] ?? '',
            'body'        => $data['body'] ?? '',
            'link'        => $data['link'] ?? null,
            'read_at'     => $notification->read_at?->toISOString(),
            'created_at'  => $notification->created_at?->toISOString(),
        ];
    }

    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning('[NotificationService] Notification not sent', ['error' => $e->getMessage()]);
        }
    }
}
