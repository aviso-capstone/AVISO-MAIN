<?php

namespace App\Events;

use App\Models\Trip;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RiderLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * We broadcast the full trip so the admin map has everything it needs:
     * rider_code (for the pin label), current_lat/lng (live position),
     * start_lat/lng (route origin), and status.
     */
    public function __construct(public readonly Trip $trip)
    {
        //
    }

    /**
     * The public channel all admin clients listen to.
     *
     * Using a public channel so the admin map can listen without
     * the rider needing to authenticate with the admin session.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('riders.live'),
        ];
    }

    /**
     * The event name the frontend will listen for.
     */
    public function broadcastAs(): string
    {
        return 'location.updated';
    }

    /**
     * Only broadcast the fields the map actually needs.
     * Keeps the WebSocket payload small and fast.
     */
    public function broadcastWith(): array
    {
        return [
            'id'          => $this->trip->id,
            'rider_code'  => $this->trip->rider_code,
            'current_lat' => (float) $this->trip->current_lat,
            'current_lng' => (float) $this->trip->current_lng,
            'start_lat'   => (float) $this->trip->start_lat,
            'start_lng'   => (float) $this->trip->start_lng,
            'status'      => $this->trip->status,
            'started_at'  => $this->trip->started_at?->toISOString(),
        ];
    }
}
