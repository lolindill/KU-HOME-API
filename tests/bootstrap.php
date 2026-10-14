<?php

// 🧭 Worktree bootstrap for PHPUnit
// When vendor is a junction to the main repository, ensure App\ and Tests\ namespaces
// resolve to this worktree directory and APP_BASE_PATH points here.
$loader = require __DIR__ . '/../vendor/autoload.php';

$baseDir = dirname(__DIR__);
$loader->setPsr4('App\\', [$baseDir . '/app']);
$loader->setPsr4('Tests\\', [$baseDir . '/tests']);

$_ENV['APP_BASE_PATH'] = $baseDir;
$_SERVER['APP_BASE_PATH'] = $baseDir;
putenv("APP_BASE_PATH={$baseDir}");

return $loader;
