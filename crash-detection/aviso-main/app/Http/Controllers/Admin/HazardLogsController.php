<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HazardLog;
use App\Services\BarangayLocatorService;
use App\Services\HazardLogService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HazardLogsController extends Controller
{
    public function __construct(
        private HazardLogService $hazardLogService,
        private BarangayLocatorService $barangayLocator,
    ) {
    }

    public function index(Request $request)
    {
        $filters = $request->only(['search', 'type', 'barangay', 'status', 'sort', 'per_page']);

        if ($request->input('export') === 'csv') {
            return $this->hazardLogService->toCsvResponse($filters);
        }

        if ($request->input('export') === 'pdf') {
            return $this->hazardLogService->toPdfResponse($filters);
        }

        $paginated = $this->hazardLogService->getPaginatedAdminLogs($filters);
        $stats     = $this->hazardLogService->getAdminStats($filters);

        return Inertia::render('main/HazardLogs', array_merge([
            'hazards' => $paginated,
            'filters' => $filters,
            'types'     => HazardLog::TYPES,
            'barangays' => $this->barangayLocator->all(),
        ], $stats));
    }
}
