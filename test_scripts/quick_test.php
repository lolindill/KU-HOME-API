<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;

User::where('email', 'admin@test.com')->update(['role' => 'admin']);

$users = User::all();
foreach ($users as $u) {
    echo $u->id . ' | ' . $u->email . ' | role=' . $u->role . ' | ver=' . ($u->ver ? '1' : '0') . "\n";
}
