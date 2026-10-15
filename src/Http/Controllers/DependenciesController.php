<?php

namespace Rainwaves\ScaleMonitorClient\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Rainwaves\ScaleMonitorClient\Support\DependencyInventory;

/**
 * Signed pull Scale Monitor uses to check this deployment's locked packages
 * against published advisories. Registered automatically at
 * GET /api/monitoring/v1/dependencies behind `scale-monitor.signature`
 * (disable with SCALE_MONITOR_DEPENDENCIES_ROUTE=false).
 */
class DependenciesController
{
    public function __invoke(DependencyInventory $inventory): JsonResponse
    {
        return response()->json($inventory->build());
    }
}
