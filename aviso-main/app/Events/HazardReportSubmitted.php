<?php

namespace App\Events;

use App\Models\HazardReport;
use App\Services\HazardReportService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A rider reported a saved hazard as no longer there (with a photo). */
class HazardReportSubmitted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly HazardReport $report)
    {
        //
    }

    public function broadcastOn(): array
    {
        // Admin-only channel (routes/channels.php): the payload names the rider.
        return [new PrivateChannel('riders.live')];
    }

    public function broadcastAs(): string
    {
        return 'hazard.report_submitted';
    }

    public function broadcastWith(): array
    {
        return HazardReportService::toPayload($this->report);
    }
}
