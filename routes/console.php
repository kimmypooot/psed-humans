<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every weekday morning: officers hear which mechanisms are near or past the 20-day turnaround.
Schedule::command('mechanisms:remind-due')->weekdays()->at('07:30');
