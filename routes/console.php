<?php

use App\Services\CreditLedgerService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(CreditLedgerService::class)->releaseExpiredReservations())
    ->everyFiveMinutes()
    ->name('credits:release-expired');
