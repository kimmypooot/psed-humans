<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/** The bell and the notifications page: what is waiting for the signed-in person, and marking it read. Each person sees only their own. */
class NotificationController extends Controller
{
    private const CATEGORIES = ['action', 'progress', 'deadline'];

    /** The bell: the latest few and the unread count, as JSON. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()->latest()->limit(8)->get()->map(self::item(...))->all(),
        ]);
    }

    /** The full list: filter by unread and by what kind of notice it is; newest first. */
    public function page(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->validate([
            'filter' => ['nullable', 'in:unread'],
            'category' => ['nullable', 'in:'.implode(',', self::CATEGORIES)],
        ]);

        $items = $user->notifications()
            ->when(($filters['filter'] ?? null) === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('data', 'like', '%"category":"'.$c.'"%'))
            ->latest()->paginate(20)->withQueryString()
            ->through(self::item(...));

        return Inertia::render('Notifications/Index', [
            'items' => $items,
            'filters' => $filters,
            'unread' => $user->unreadNotifications()->count(),
            'total' => $user->notifications()->count(),
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['unread' => 0]);
    }

    /** Clears the ones already read; the unread stay. */
    public function clearRead(Request $request): JsonResponse
    {
        $request->user()->notifications()->whereNotNull('read_at')->delete();

        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    /** @return array<string, mixed> */
    private static function item(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id,
            'kind' => $n->data['kind'] ?? 'forwarded',
            'category' => $n->data['category'] ?? 'action',
            'tone' => $n->data['tone'] ?? 'info',
            'title' => $n->data['title'] ?? 'Notification',
            'body' => $n->data['body'] ?? '',
            'tracking_code' => $n->data['tracking_code'] ?? null,
            'url' => $n->data['url'] ?? null,
            'read' => $n->read_at !== null,
            'at' => $n->created_at->toIso8601String(),
        ];
    }
}
