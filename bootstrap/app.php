<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\RequireJsonAccept;
use Composer\Autoload\ClassLoader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$baseDir = dirname(__DIR__);
$_ENV['APP_BASE_PATH'] = $baseDir;
$_SERVER['APP_BASE_PATH'] = $baseDir;

if (class_exists(ClassLoader::class)) {
    foreach (ClassLoader::getRegisteredLoaders() as $loader) {
        $loader->setPsr4('App\\', [$baseDir.'/app']);
        $loader->setPsr4('Database\\Factories\\', [$baseDir.'/database/factories']);
        $loader->setPsr4('Database\\Seeders\\', [$baseDir.'/database/seeders']);
        $loader->setPsr4('Tests\\', [$baseDir.'/tests']);

        try {
            $ref = new ReflectionProperty($loader, 'classMap');
            $ref->setAccessible(true);
            $map = $ref->getValue($loader);
            foreach ($map as $class => $path) {
                if (str_starts_with($class, 'App\\') || str_starts_with($class, 'Database\\') || str_starts_with($class, 'Tests\\')) {
                    unset($map[$class]);
                }
            }
            $ref->setValue($loader, $map);
        } catch (Throwable) {
            // best-effort
        }
    }
}

return Application::configure(basePath: $baseDir)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => CheckRole::class, // 👈 เติมบรรทัดนี้ลงไปค่ะ!
        ]);

        // 🎯 Global middleware บน API group: บังคับ Accept: application/json ทุก /api/* request
        //    (prepend ให้ทำงานก่อน throttle/auth:sanctum — see RequireJsonAccept)
        $middleware->api(prepend: [
            RequireJsonAccept::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
