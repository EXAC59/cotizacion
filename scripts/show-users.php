<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = Illuminate\Support\Facades\DB::table('users')
    ->join('roles', 'users.role_id', '=', 'roles.id')
    ->select('users.name', 'users.email', 'roles.slug as role', 'users.active')
    ->orderBy('roles.id')
    ->get();

foreach ($rows as $row) {
    echo "{$row->role}\t{$row->email}\t{$row->name}\t".($row->active ? 'activo' : 'inactivo').PHP_EOL;
}
