<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// 🧭 Worktree autoloader (ensure App\ classes resolve to this worktree when vendor is symlinked)
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__.'/../app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
}, true, true);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class, // 👈 เติมบรรทัดนี้ลงไปค่ะ!
        ]);

        // 🎯 Global middleware บน API group: บังคับ Accept: application/json ทุก /api/* request
        //    (prepend ให้ทำงานก่อน throttle/auth:sanctum — see RequireJsonAccept)
        $middleware->api(prepend: [
            \App\Http\Middleware\RequireJsonAccept::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
