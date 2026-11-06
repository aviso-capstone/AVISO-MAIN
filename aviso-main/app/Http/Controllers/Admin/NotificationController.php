<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListNotificationsRequest;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The admin Notifications page. Every query is scoped to the logged-in admin. */
class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications)
    {
        //
    }

    public function index(ListNotificationsRequest $request): Response
    {
        $filters = $request->filters();

        return Inertia::render('main/NotificationsPage', [
            'notifications' => $this->notifications->list($request->user(), $filters),
            'filters'       => $filters,
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->notifications->unreadCount($request->user())]);
    }

    public function markRead(Request $request, string $id): RedirectResponse
    {
        $this->notifications->markRead($request->user(), $id);

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $this->notifications->markAllRead($request->user());

        return back()->with('success', 'All notifications marked as read.');
    }
}
