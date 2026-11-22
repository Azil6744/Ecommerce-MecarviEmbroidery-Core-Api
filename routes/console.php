<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('filemanager:purge-trash', function () {
    $n = app(\App\Http\Controllers\Api\Admin\AdminFileManagerController::class)->purgeExpired();
    $this->info("Permanently removed {$n} expired trash item(s).");
})->purpose('Permanently delete File Manager trash older than 30 days');

Artisan::command('vouchers:send-expiry-reminders', function () {
    $n = \App\Services\VoucherService::sendExpiryReminders();
    $this->info("Processed {$n} voucher expiry reminder(s).");
})->purpose('Send voucher expiration reminder emails per Voucher Settings');


Artisan::command('mecarvi-cash:sweep', function () {
    $n = \App\Services\MecarviCashService::sweep();
    $this->info("Applied $n scheduled/inactivity wallet disable(s).");
})->purpose('Apply scheduled and inactivity-based Mecarvi Cash wallet disables');

