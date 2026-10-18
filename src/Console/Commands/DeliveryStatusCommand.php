<?php

namespace Rainwaves\ScaleMonitorClient\Console\Commands;

use Illuminate\Console\Command;
use Rainwaves\ScaleMonitorClient\Support\DeliveryStatus;

class DeliveryStatusCommand extends Command
{
    protected $signature = 'scale-monitor:delivery-status';

    protected $description = 'Read safe local telemetry delivery outcomes; does not send telemetry.';

    public function handle(DeliveryStatus $status): int
    {
        $this->line(json_encode($status->read(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
