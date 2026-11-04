<?php

namespace App\Events;

use App\Models\EmergencyAlert;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An unresolved alert moved a step (help dispatched, responders on scene), so
 * every connected admin view updates its pin, alarm and timeline at once and
 * nobody calls the same agency twice.
 */
class EmergencyAlertUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly EmergencyAlert $alert)
    {
        //
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('riders.live'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'emergency.updated';
    }

    /** Same shape as `emergency.triggered`, so the client can replace the entry. */
    public function broadcastWith(): array
    {
        return (new EmergencyAlertTriggered($this->alert))->broadcastWith();
    }
}
