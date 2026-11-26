<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Device;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DevicePolicy
{
    /** Devices are never shared between accounts in v1: the owner may do everything. */
    public function manage(User $user, Device $device): Response
    {
        // 404 instead of 403 so other users' device IDs aren't confirmed to exist.
        return $user->id === $device->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
