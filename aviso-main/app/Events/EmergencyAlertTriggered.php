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
            'trigger_type' => $this->alert->trigger_type,
            'hazard_before' => $this->alert->hazardJustBefore(),
            'rider_name'   => $fullName,
            'username'     => $user?->username    ?? $this->alert->rider_code,
            'contact'      => $user?->contact_number ?? '—',
            'address'      => $user?->address    ?? '—',
            ...self::dispatchFields($this->alert),
        ];
    }

    /**
     * Dispatch-tracking fields, shared by every alert payload (triggered,
     * updated, seeded list, history deep link) so they all have one shape.
     */
    public static function dispatchFields(EmergencyAlert $alert): array
    {
        $handler = $alert->handler;

        return [
            'dispatched_at'          => $alert->dispatched_at?->toISOString(),
            'responder_agency'       => $alert->responder_agency,
            'responder_agency_other' => $alert->responder_agency_other,
            'agency_label'           => $alert->agencyLabel(),
            'responder_first_name'   => $alert->responder_first_name,
            'responder_last_name'    => $alert->responder_last_name,
            'eta_minutes'            => $alert->eta_minutes,
            'on_scene_at'            => $alert->on_scene_at?->toISOString(),
            'outcome'                => $alert->outcome,
            'outcome_label'          => $alert->outcomeLabel(),
            'resolution_note'        => $alert->resolution_note,
            'resolved_at'            => $alert->resolved_at?->toISOString(),
            'handled_by_name'        => $handler ? trim("{$handler->first_name} {$handler->last_name}") : null,
        ];
    }
}
