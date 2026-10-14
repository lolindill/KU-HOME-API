<?php

// 🧭 Worktree bootstrap for PHPUnit
// When vendor is a junction to the main repository, ensure App\ and Tests\ namespaces
// resolve to this worktree directory and APP_BASE_PATH points here.
$loader = require __DIR__.'/../vendor/autoload.php';

$baseDir = dirname(__DIR__);
$loader->setPsr4('App\\', [$baseDir.'/app']);
$loader->setPsr4('Tests\\', [$baseDir.'/tests']);

spl_autoload_register(function ($class) use ($baseDir) {
    if (str_starts_with($class, 'App\\')) {
        $file = $baseDir.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (file_exists($file)) {
            require_once $file;

            return true;
        }
    }
    if (str_starts_with($class, 'Tests\\')) {
        $file = $baseDir.'/tests/'.str_replace('\\', '/', substr($class, 6)).'.php';
        if (file_exists($file)) {
            require_once $file;

            return true;
        }
    }
}, true, true);

$_ENV['APP_BASE_PATH'] = $baseDir;
$_SERVER['APP_BASE_PATH'] = $baseDir;
putenv("APP_BASE_PATH={$baseDir}");

return $loader;
