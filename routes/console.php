<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('runtime:health --quiet --no-interaction')
    ->everyFiveMinutes()
    ->name('runtime.health')
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('backup:scheduled-run daily')
    ->dailyAt((string) config('backup.automatic.time', '02:00'))
    ->name('backup.scheduled.daily')
    ->timezone((string) config('backup.automatic.timezone', 'UTC'))
    ->withoutOverlapping(180)
    ->onOneServer();

Schedule::command('backup:scheduled-run weekly')
    ->weeklyOn((int) config('backup.automatic.weekly_day', 1), (string) config('backup.automatic.time', '02:00'))
    ->name('backup.scheduled.weekly')
    ->timezone((string) config('backup.automatic.timezone', 'UTC'))
    ->withoutOverlapping(180)
    ->onOneServer();

Schedule::command('backup:scheduled-run monthly')
    ->monthlyOn((int) config('backup.automatic.monthly_day', 1), (string) config('backup.automatic.time', '02:00'))
    ->name('backup.scheduled.monthly')
    ->timezone((string) config('backup.automatic.timezone', 'UTC'))
    ->withoutOverlapping(180)
    ->onOneServer();

if ((bool) config('services.woocommerce.product_sync_enabled', false)) {
    Schedule::command('products:sync-woocommerce --incremental')
        ->hourly()
        ->name('products.woocommerce.inbound.hourly')
        ->withoutOverlapping(55)
        ->onOneServer();
}

if ((bool) config('services.woocommerce.order_sync_enabled', false)) {
    Schedule::command('orders:sync-woocommerce --no-interaction')
        ->hourly()
        ->name('orders.woocommerce.inbound.hourly')
        ->withoutOverlapping(55)
        ->onOneServer();
}

if ((bool) config('erp_accounting.continuous_delta.enabled', false)) {
    Schedule::command('finance:materialize-continuous-delta --apply --limit=10000 --no-interaction')
        ->hourlyAt(10)
        ->name('finance.accounting.continuous-delta.hourly')
        ->withoutOverlapping(50)
        ->onOneServer();
}

if ((bool) config('inventory_auto_allocation.scheduler_enabled', false)) {
    Schedule::command('inventory:auto-allocate --limit='.(int) config('inventory_auto_allocation.catch_up_limit', 100).' --apply')
        ->hourly()
        ->name('inventory.auto-allocation.catch-up.hourly')
        ->withoutOverlapping(55)
        ->onOneServer();
}

if ((bool) config('services.google_search_console.sync_enabled', false)) {
    $timezone = (string) config('services.google_search_console.sync_timezone', 'Asia/Shanghai');
    $lagDays = max(0, (int) config('services.google_search_console.sync_data_lag_days', 2));
    $lookbackDays = max(1, (int) config('services.google_search_console.sync_lookback_days', 7));
    $dateTo = CarbonImmutable::now($timezone)->subDays($lagDays)->toDateString();
    $dateFrom = CarbonImmutable::parse($dateTo, $timezone)->subDays($lookbackDays - 1)->toDateString();
    $project = (string) config('services.google_search_console.sync_project', 'yby-irrigation');
    $site = (string) config('services.google_search_console.sync_site', 'ybyirrigation-com');
    $property = (string) config('services.google_search_console.sync_property', 'sc-domain:ybyirrigation.com');

    Schedule::command(sprintf(
        'traffic:seo-gsc:sync --project=%s --site=%s --property=%s --from=%s --to=%s --apply --no-interaction',
        escapeshellarg($project),
        escapeshellarg($site),
        escapeshellarg($property),
        $dateFrom,
        $dateTo,
    ))
        ->dailyAt((string) config('services.google_search_console.sync_time', '04:20'))
        ->timezone($timezone)
        ->name('traffic.gsc.seo.daily')
        ->withoutOverlapping(180)
        ->onOneServer();
}
