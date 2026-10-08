<?php

namespace App\Events;

use App\Models\EmergencyAlert;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmergencyAlertResolved implements ShouldBroadcastNow
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
        return 'emergency.resolved';
    }

    /**
     * Minimal payload — the client only needs to drop this alert from its list.
     */
    public function broadcastWith(): array
    {
        return [
            'id'          => $this->alert->id,
            'rider_code'  => $this->alert->rider_code,
            'status'      => $this->alert->status,
            'resolved_at' => $this->alert->resolved_at?->toISOString(),
        ];
    }
}
