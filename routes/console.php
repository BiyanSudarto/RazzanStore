<?php

use Illuminate\\Support\\Facades\\Artisan;

Artisan::command('razzanstore:about', function (): void {
    $this->info('RazzanStore application foundation is ready.');
})->purpose('Display RazzanStore application information');
