<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()->notifications()->latest()->get();

        return response()->json([
            'success' => true,
            'message' => 'Notifications retrieved successfully',
            'data' => $notifications,
        ]);
    }

    public function markAsRead(Notification $notification, Request $request): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
                'data' => null,
            ], 404);
        }

        $notification->is_read = true;
        $notification->read_at = now();
        $notification->save();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
            'data' => $notification,
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $updatedCount = $request->user()->notifications()
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Notifications marked as read',
            'data' => [
                'updated_count' => $updatedCount,
            ],
        ]);
    }

    /**
     * Phase 9.1: removes one notification from the caller's own inbox --
     * a real, permanent row delete. Safe by construction: no other table
     * in the schema has a foreign key onto `notifications` (the only
     * relationship is `User hasMany Notification`), so this can never
     * cascade into or otherwise affect an Application, Invitation,
     * Assessment, Interview, Offer, or EducationVerification row. This is
     * inbox cleanup only, never a reversal of the business event the
     * notification was about.
     */
    public function destroy(Notification $notification, Request $request): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
                'data' => null,
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted',
            'data' => null,
        ]);
    }

    /**
     * Phase 9.1: removes every currently-read notification from the
     * caller's own inbox -- unread notifications are never touched, so a
     * student can never lose an update they haven't seen yet. Same
     * delete-safety guarantee as {@see destroy()}.
     */
    public function clearRead(Request $request): JsonResponse
    {
        $deletedCount = $request->user()->notifications()
            ->where('is_read', true)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Read notifications cleared',
            'data' => [
                'deleted_count' => $deletedCount,
            ],
        ]);
    }
}
