<?php

namespace App\Services;

use App\Models\HazardLog;
use Carbon\CarbonInterface;

/**
 * Which saves are the same real hazard. The database keeps one row per save,
 * so a pothole passed by five rides is five rows. Saves are the same hazard when:
 * - same type;
 * - within the type's radius (HazardLog::GROUP_RADIUS_M: pothole 10 m,
 *   excavation 25 m, barrier 30 m) of the group's first save;
 * - saved going the same direction (within SAME_DIRECTION_ANGLE; an unknown
 *   direction matches any);
 * - two saves from the same ride only when at least NEW_PASS_SECONDS apart:
 *   saves seconds apart are one pass over two hazards close together; a
 *   later save is the rider passing the same spot again (riding back).
 *
 * Used for Times Seen, the Hazard to Hazard Distance, resolving every save of
 * a hazard, and the one-pin-per-hazard maps. Callers pass saves of one status.
 */
class HazardGroupService
{
    public const SAME_DIRECTION_ANGLE = 45;

    /**
     * Two saves on the same ride this many seconds apart or more are separate
     * passes over the same spot. Closer in time they are one pass, so two
     * hazards (the phone saves a new hazard at most every few seconds).
     */
    public const NEW_PASS_SECONDS = 30;

    /** Grid cell for the neighbour search (~55 m), larger than any group radius. */
    private const CELL_DEG = 0.0005;

    /**
     * @param  iterable<HazardLog>  $logs
     * @return array<int, array{
     *     anchor: HazardLog,
     *     members: HazardLog[],
     *     member_ids: int[],
     *     latitude: float,
     *     longitude: float,
     *     times_seen: int,
     *     last_seen_at: CarbonInterface|null,
     * }>
     */
    public function group(iterable $logs): array
    {
        $sorted = collect($logs)->sort(function (HazardLog $a, HazardLog $b) {
            $byTime = ($a->detected_at?->getTimestamp() ?? 0) <=> ($b->detected_at?->getTimestamp() ?? 0);

            return $byTime !== 0 ? $byTime : $a->id <=> $b->id;
        })->values();

        $groups = [];
        $cells  = [];

        foreach ($sorted as $log) {
            $lat     = (float) $log->latitude;
            $lng     = (float) $log->longitude;
            $radius  = HazardLog::GROUP_RADIUS_M[$log->type] ?? 10;
            $heading = self::headingOf($log);
            $time    = $log->detected_at?->getTimestamp();
            $cx      = (int) floor($lat / self::CELL_DEG);
            $cy      = (int) floor($lng / self::CELL_DEG);

            $match     = null;
            $matchDist = INF;
            for ($dx = -1; $dx <= 1; $dx++) {
                for ($dy = -1; $dy <= 1; $dy++) {
                    foreach ($cells[$log->type][($cx + $dx) . ':' . ($cy + $dy)] ?? [] as $index) {
                        $group = $groups[$index];
                        if ($log->trip_id !== null && self::samePass($group['trips'][$log->trip_id] ?? [], $time)) {
                            continue;
                        }
                        if ($group['heading'] !== null && $heading !== null
                            && HazardPositionService::angleBetween($group['heading'], $heading) > self::SAME_DIRECTION_ANGLE) {
                            continue;
                        }
                        $distance = HazardPositionService::metersBetween($group['lat'], $group['lng'], $lat, $lng);
                        if ($distance <= $radius && $distance < $matchDist) {
                            $match     = $index;
                            $matchDist = $distance;
                        }
                    }
                }
            }

            if ($match === null) {
                $groups[] = [
                    'lat'     => $lat,
                    'lng'     => $lng,
                    'heading' => $heading,
                    // Save times per ride, for the same-pass check.
                    'trips'   => $log->trip_id !== null ? [$log->trip_id => [$time]] : [],
                    'members' => [$log],
                ];
                $cells[$log->type]["{$cx}:{$cy}"][] = count($groups) - 1;
                continue;
            }

            $groups[$match]['members'][] = $log;
            $groups[$match]['heading'] ??= $heading;
            if ($log->trip_id !== null) {
                $groups[$match]['trips'][$log->trip_id][] = $time;
            }
        }

        return array_map(fn (array $group) => self::summary($group['members']), $groups);
    }

    /**
     * Saves of the same hazard, keyed by the id of each save.
     *
     * @return array<int, array> the group summary of every save id
     */
    public function index(array $groups): array
    {
        $byId = [];
        foreach ($groups as $group) {
            foreach ($group['member_ids'] as $id) {
                $byId[$id] = $group;
            }
        }

        return $byId;
    }

    /**
     * Hazard to Hazard Distance: the nearest OTHER hazard of the same type, i.e.
     * skipping the saves of this same hazard (they are Times Seen, not a second
     * hazard). Measured to that hazard's averaged position.
     *
     * @param  array  $groups  groups of active hazards
     * @return array{code: string, meters: float}|null
     */
    public function nearestOtherHazard(HazardLog $log, array $groups): ?array
    {
        $lat  = (float) $log->latitude;
        $lng  = (float) $log->longitude;
        $best = null;

        foreach ($groups as $group) {
            if ($group['anchor']->type !== $log->type || in_array($log->id, $group['member_ids'], true)) {
                continue;
            }
            $meters = HazardPositionService::metersBetween($lat, $lng, $group['latitude'], $group['longitude']);
            if ($best === null || $meters < $best['meters']) {
                $best = ['code' => $group['anchor']->haz_code, 'meters' => $meters];
            }
        }

        return $best;
    }

    /** True when a save at $time is within NEW_PASS_SECONDS of one of these saves of its ride. */
    private static function samePass(array $rideTimes, ?int $time): bool
    {
        foreach ($rideTimes as $other) {
            if ($time === null || $other === null || abs($time - $other) < self::NEW_PASS_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /** Direction used for grouping: the placement heading, else the raw GPS heading. */
    private static function headingOf(HazardLog $log): ?float
    {
        $heading = $log->travel_heading ?? $log->heading;

        return $heading !== null ? (float) $heading : null;
    }

    /** @param HazardLog[] $members  oldest first */
    private static function summary(array $members): array
    {
        $count = count($members);
        $lat   = 0.0;
        $lng   = 0.0;
        $last  = null;

        foreach ($members as $member) {
            $lat += (float) $member->latitude;
            $lng += (float) $member->longitude;
            if ($member->detected_at && ($last === null || $member->detected_at->gt($last))) {
                $last = $member->detected_at;
            }
        }

        return [
            'anchor'       => $members[0],
            'members'      => $members,
            'member_ids'   => array_map(fn (HazardLog $m) => $m->id, $members),
            // The average of every save: GPS errors in different directions cancel out.
            'latitude'     => $lat / $count,
            'longitude'    => $lng / $count,
            // Saves of one ride in a group are separate passes, so each save is one time seen.
            'times_seen'   => $count,
            'last_seen_at' => $last,
        ];
    }
}
