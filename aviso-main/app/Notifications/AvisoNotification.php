<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\AvisoDatabaseChannel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * One item on a Notifications page (admin website or rider app).
 *
 * Built by AdminNotifications / RiderNotifications, sent by NotificationService.
 * Saved in the `notifications` table; admins also get it live on their private
 * channel (App.Models.User.{id}). The admin who did the action gets their copy
 * already read and is not pinged about their own action.
 */
class AvisoNotification extends Notification
{
    // ── Admin categories ─────────────────────────────────────────────────
    public const EMERGENCY      = 'emergency';
    public const HAZARD_REPORTS = 'hazard_reports';
    public const ACCOUNTS       = 'accounts';
    public const MY_ACCOUNT     = 'my_account';

    // ── Rider categories ─────────────────────────────────────────────────
    public const MY_SOS     = 'my_sos';
    public const MY_REPORTS = 'my_reports';
    public const MY_HAZARDS = 'my_hazards';
    public const ACCOUNT    = 'account';

    public const ADMIN_CATEGORIES = [self::EMERGENCY, self::HAZARD_REPORTS, self::ACCOUNTS, self::MY_ACCOUNT];
    public const RIDER_CATEGORIES = [self::MY_SOS, self::MY_REPORTS, self::MY_HAZARDS, self::ACCOUNT];

    /**
     * @param string      $category   one of the constants above
     * @param string      $event      what happened, e.g. "sos.dispatched" (for tests and the icon)
     * @param string|null $contextKey what it is about, e.g. "sos:12"
     * @param string|null $link       admin: a website path; rider: an app screen key
     * @param int|null    $actorId    the admin who did it (their copy is saved as read)
     */
    public function __construct(
        public readonly string $category,
        public readonly string $event,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $contextKey = null,
        public readonly ?string $link = null,
        public readonly ?int $actorId = null,
    ) {
        //
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = [AvisoDatabaseChannel::class];

        // Live only for admins (riders poll), and never to the admin who did it.
        if ($notifiable instanceof User && $notifiable->role === 'admin' && ! $this->isOwnAction($notifiable)) {
            $channels[] = 'broadcast';
        }

        return $channels;
    }

    public function isOwnAction(object $notifiable): bool
    {
        return $this->actorId !== null && $notifiable instanceof User && $notifiable->id === $this->actorId;
    }

    /** Stored in `data`. */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'title' => $this->title,
            'body'  => $this->body,
            'link'  => $this->link,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            ...$this->toArray($notifiable),
            'category'    => $this->category,
            'context_key' => $this->contextKey,
            'created_at'  => now()->toISOString(),
        ]);
    }

    /** The `type` field of the live message (Echo `.notification()` receives every type). */
    public function broadcastType(): string
    {
        return 'aviso.notification';
    }
}
