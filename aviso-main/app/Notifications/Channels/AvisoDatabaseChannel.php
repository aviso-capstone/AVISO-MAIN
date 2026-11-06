<?php

namespace App\Notifications\Channels;

use App\Notifications\AvisoNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Laravel's database channel, plus the `category` and `context_key` columns
 * the Notifications page filters and groups by. The actor's own copy is
 * saved as already read.
 */
class AvisoDatabaseChannel extends DatabaseChannel
{
    protected function buildPayload($notifiable, Notification $notification)
    {
        $payload = parent::buildPayload($notifiable, $notification);

        if ($notification instanceof AvisoNotification) {
            $payload['category']    = $notification->category;
            $payload['context_key'] = $notification->contextKey;
            $payload['read_at']     = $notification->isOwnAction($notifiable) ? now() : null;
        }

        return $payload;
    }
}
