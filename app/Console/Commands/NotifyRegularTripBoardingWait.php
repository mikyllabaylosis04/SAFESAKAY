<?php

namespace App\Console\Commands;

use App\Services\RegularTripRotationService;
use Illuminate\Console\Command;

class NotifyRegularTripBoardingWait extends Command
{
    protected $signature = 'regular-trips:notify-boarding-wait';

    protected $description = 'Notify operators when the boarding waiting limit is reached without forcing departure';

    public function handle(RegularTripRotationService $rotation): int
    {
        $notified = $rotation->notifyBoardingWaitIfDue();

        $this->info("Notified {$notified} boarding wait".($notified === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
