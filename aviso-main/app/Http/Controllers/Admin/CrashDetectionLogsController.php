<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CrashLogFilterRequest;
use App\Models\RiderEvent;
use App\Services\BarangayLocatorService;
use App\Services\RiderEventService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Crash-detection log for admins: every IoT classification (normal riding,
 * hard braking, road bump, crash) with its sensor values, per-category
 * statistics for threshold tuning, and CSV export.
 */
class CrashDetectionLogsController extends Controller
{
    public function __construct(
        private RiderEventService $riderEventService,
        private BarangayLocatorService $barangayLocator,
    ) {}

    public function index(CrashLogFilterRequest $request)
    {
        $filters = $request->safe()->only(['type', 'search', 'barangay', 'from', 'to', 'per_page']);

        if ($request->validated('export') === 'csv') {

            return $this->riderEventService->csvResponse($filters);
        }

        return Inertia::render('main/CrashLogs', [
            'events' => $this->riderEventService->paginate($filters),
            'stats' => $this->riderEventService->statsByType($filters),
            'filters' => $filters,
            'types' => collect(RiderEvent::TYPE_LABELS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'barangays' => $this->barangayLocator->all(),
        ]);
    }
}
