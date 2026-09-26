<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('hub:check-monitors')->everyMinute()->withoutOverlapping(30);
Schedule::command('hub:run-content-workflow')->everyMinute()->withoutOverlapping(15);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
