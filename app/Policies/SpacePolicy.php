<?php

namespace App\Policies;

use App\Models\Space;
use App\Models\User;

class SpacePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Space $space): bool
    {
        return $user->role === 'admin'
            || ($user->role === 'staff' && (int) $space->staff_id === (int) $user->id);
    }

    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function update(User $user, Space $space): bool
    {
        return $user->role === 'admin';
    }

    public function delete(User $user, Space $space): bool
    {
        return $user->role === 'admin';
    }

    public function restore(User $user, Space $space): bool
    {
        return $user->role === 'admin';
    }

    public function forceDelete(User $user, Space $space): bool
    {
        return $user->role === 'admin';
    }
}
