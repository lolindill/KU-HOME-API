App\Models\User::where('email','admin@test.com')->update(['role'=>'admin']);
$users = App\Models\User::all()->map(fn($u) => ['id' => $u->id, 'email' => $u->email, 'role' => $u->role, 'ver' => $u->ver]);
echo $users->toJson(JSON_PRETTY_PRINT);
