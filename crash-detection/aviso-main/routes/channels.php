<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Live rider tracking for the admin console: SOS alerts and location updates.
//
// Private, not public. The payloads carry rider PII — full name, contact number
// and home address — so a browser must prove it holds an authenticated admin
// session before Reverb will let it subscribe. Returning true here would let
// anyone who can reach the WebSocket port read every alert.
Broadcast::channel('riders.live', function (User $user): bool {
    return $user->role === 'admin';
});
