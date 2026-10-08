<?php

namespace App\Events;

use App\Models\EmergencyAlert;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmergencyAlertTriggered implements ShouldBroadcastNow
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
        return 'emergency.triggered';
    }

    public function broadcastWith(): array
    {
        $user = $this->alert->user;

        $fullName = $user
            ? trim(
                $user->first_name
                . ($user->middle_name ? ' ' . $user->middle_name : '')
                . ' ' . $user->last_name
            )
            : $this->alert->rider_code;

        return [
            'id'           => $this->alert->id,
            'rider_code'   => $this->alert->rider_code,
            'latitude'     => (float) $this->alert->latitude,
            'longitude'    => (float) $this->alert->longitude,
            'triggered_at' => $this->alert->triggered_at?->toISOString(),
            'status'       => $this->alert->status,
            'rider_name'   => $fullName,
            'username'     => $user?->username    ?? $this->alert->rider_code,
            'contact'      => $user?->contact_number ?? '—',
            'address'      => $user?->address    ?? '—',
        ];
    }
}
