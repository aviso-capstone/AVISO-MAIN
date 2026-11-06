<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rider\ListNotificationsRequest;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The rider app's Notifications screen. Every query is scoped to the rider. */
class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications)
    {
        //
    }

    public function index(ListNotificationsRequest $request): JsonResponse
    {
        $page = $this->notifications->list($request->user(), $request->filters());

        return response()->json([
            'notifications' => $page->items(),
            'current_page'  => $page->currentPage(),
            'last_page'     => $page->lastPage(),
            'unread'        => $this->notifications->unreadCount($request->user()),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->notifications->unreadCount($request->user())]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $this->notifications->markRead($request->user(), $id);

        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->notifications->markAllRead($request->user());

        return response()->json(['success' => true]);
    }
}
