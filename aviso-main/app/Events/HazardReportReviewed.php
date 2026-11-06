<?php

namespace App\Events;

use App\Models\HazardReport;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An admin confirmed or rejected a "no longer there" report. Every open admin
 * console drops the closed reports from its list and, when confirmed, removes
 * the resolved hazard pins from the Live Map right away.
 */
class HazardReportReviewed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  int[]  $closedReportIds  every report closed by this review
     * @param  int[]  $resolvedHazardIds  hazard logs resolved (empty when rejected)
     */
    public function __construct(
        public readonly HazardReport $report,
        public readonly array $closedReportIds,
        public readonly array $resolvedHazardIds,
    ) {
        //
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('riders.live')];
    }

    public function broadcastAs(): string
    {
        return 'hazard.report_reviewed';
    }

    public function broadcastWith(): array
    {
        return [
            'report_id'           => $this->report->id,
            'status'              => $this->report->status,
            'closed_report_ids'   => array_values($this->closedReportIds),
            'resolved_hazard_ids' => array_values($this->resolvedHazardIds),
        ];
    }
}
