<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('amcl:daily-servicing')->dailyAt('06:00')->withoutOverlapping()->onOneServer();
Schedule::command('amcl:month-end')->monthlyOn(28, '23:00')->withoutOverlapping()->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('queue:prune-failed --hours=720')->daily();
