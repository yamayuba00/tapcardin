<?php

use Illuminate\Support\Facades\Schedule;

/* BSD §7.5 — jadwal (WIB). Dijalankan oleh `php artisan schedule:work` di Supervisor. */
Schedule::command('tapcard:aggregate-stats')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('tapcard:reminders')->everyMinute()->withoutOverlapping();
Schedule::command('tapcard:payments-reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tapcard:subscriptions')->dailyAt('01:00')->timezone('Asia/Jakarta')->withoutOverlapping();
Schedule::command('tapcard:aggregate-stats --days=2')->dailyAt('00:30')->timezone('Asia/Jakarta');
Schedule::command('tapcard:partitions')->dailyAt('02:00')->timezone('Asia/Jakarta');
Schedule::command('tapcard:prune')->dailyAt('03:30')->timezone('Asia/Jakarta');
