<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment('Run the queue worker: php artisan queue:work --tries=3 --timeout=900');
})->purpose('Display an inspiring quote (demo override)');