<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    //

        // 📋 Get all users
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => User::latest()->get()
        ]);
    }

    // 🔄 Change role
    public function changeRole(Request $request, $id)
    {
        $request->validate([
            'role' => 'required|in:admin,staff,customer'
        ]);

        abort_unless($request->user()?->role === 'admin', 403);

        $conflict = false;
        $user = DB::transaction(function () use ($id, $request, &$conflict): User {
            $user = User::query()->lockForUpdate()->findOrFail($id);

            if ($user->role === 'staff'
                && $request->role !== 'staff'
                && $user->assignedSpaces()->exists()) {
                $conflict = true;

                return $user;
            }

            $user->update(['role' => $request->role]);

            return $user;
        }, 3);

        if ($conflict) {
            return response()->json([
                'message' => 'Reassign this staff member’s spaces before changing their role.',
            ], 409);
        }

        return response()->json([
            'message' => 'User role updated',
            'data' => $user
        ]);
    }

    // ❌ Delete user
    public function destroy($id)
    {
        abort_unless(request()->user()?->role === 'admin', 403);

        $result = DB::transaction(function () use ($id): array {
            $user = User::query()->lockForUpdate()->findOrFail($id);
            if ($user->role === 'admin') {
                return ['status' => 403, 'message' => 'Cannot delete admin'];
            }

            if ($user->assignedSpaces()->exists()) {
                return ['status' => 409, 'message' => 'Reassign this staff member’s spaces before deleting this account.'];
            }

            if ($user->createdSpaces()->exists()) {
                return ['status' => 409, 'message' => 'This account is recorded as the creator of existing spaces and cannot be deleted.'];
            }

            $user->delete();

            return ['status' => 200, 'message' => null];
        }, 3);

        if ($result['status'] !== 200) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        return response()->json([
            'message' => 'User deleted'
        ]);
    }

    public function notifications()
    {
        return response()->json([
            'data' => auth()->user()->notifications,
        ]);
    }

    public function markNotificationAsRead(Request $request, string $notificationId)
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $notificationId)
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json([
            'message' => 'Notification marked as read.',
        ]);
    }

    public function markAllNotificationsAsRead(Request $request)
    {
        $request->user()
            ->unreadNotifications
            ->markAsRead();

        return response()->json([
            'message' => 'All notifications marked as read.',
        ]);
    }
}
