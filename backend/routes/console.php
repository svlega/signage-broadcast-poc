<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every 15 minutes, not on a shorter cycle: this pulls a company
// announcement doc, not a live data feed — someone editing it expects
// the ticker to catch up "soon," not sub-minute, and this cadence stays
// comfortably inside Google's per-user Docs API quota even for a large
// signage estate. withoutOverlapping guards against a slow Google API
// response from one run still being in flight when the next tick fires.
Schedule::command('signage:sync-google-announcement')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
