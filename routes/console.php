<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:reminders')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5);
Schedule::command('bookings:expire')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5);

Schedule::command('refunds:reconcile --limit=100')
    ->everyMinute()
    ->withoutOverlapping(2);

// Schedule::command('inspire')->daily();
