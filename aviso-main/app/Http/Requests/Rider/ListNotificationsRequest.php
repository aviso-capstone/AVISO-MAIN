<?php

namespace App\Http\Requests\Rider;

use App\Http\Requests\Admin\ListNotificationsRequest as AdminListNotificationsRequest;
use App\Notifications\AvisoNotification;

/** Filters of the rider app's Notifications screen (rider categories). */
class ListNotificationsRequest extends AdminListNotificationsRequest
{
    protected function categories(): array
    {
        return AvisoNotification::RIDER_CATEGORIES;
    }
}
