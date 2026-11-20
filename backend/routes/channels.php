<?php

use Illuminate\Support\Facades\Broadcast;

// All device events for a user go to one private channel: Echo.private(`users.${user.id}`).
Broadcast::channel('users.{id}', fn ($user, int $id) => (int) $user->id === $id);
