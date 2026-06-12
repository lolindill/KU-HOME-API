<?php
use App\Models\User;
User::where('email','admin@test.com')->update(['role'=>'admin']);
$users = User::all();
foreach($users as $u) {
    echo $u->id . ' | ' . $u->email . ' | role=' . $u->role . ' | ver=' . ($u->ver ? 'true' : 'false') . "\n";
}
