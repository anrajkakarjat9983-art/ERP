<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (Schema::hasTable('users') && DB::table('users')->count() > 0) {
    fwrite(STDOUT, "Database already has users - skipping demo seed.\n");
    exit(0);
}

fwrite(STDOUT, "Database is empty - seeding demo data.\n");
exit(Artisan::call('db:seed', ['--force' => true, '--no-interaction' => true]));
