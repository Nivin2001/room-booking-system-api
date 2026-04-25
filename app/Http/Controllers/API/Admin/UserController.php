<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

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

        $user = User::findOrFail($id);

        $user->update([
            'role' => $request->role
        ]);

        return response()->json([
            'message' => 'User role updated',
            'data' => $user
        ]);
    }

    // ❌ Delete user
    public function destroy($id)
    {
        $user = User::findOrFail($id);
        if ($user->role === 'admin') {
    return response()->json(['message' => 'Cannot delete admin'], 403);
}
        $user->delete();

        return response()->json([
            'message' => 'User deleted'
        ]);
    }

    public function notifications()
{
    return response()->json([
        'data' => auth()->user()->notifications
    ]);
}
}
