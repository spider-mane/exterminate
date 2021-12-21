<?php

use Dotenv\Dotenv;
use WebTheory\Exterminate\Exterminator;

use function Env\env;

$root = dirname(__DIR__, 2);

require_once $root . '/vendor/autoload.php';

$env = Dotenv::createUnsafeImmutable($root);
$env->load();
$env->required(['HOST_OS', 'HOST_PATH', 'GUEST_PATH']);

Exterminator::init([

    'enable' => true,
    'editor' => 'vscode',
    'log' => $root . '/logs/basic.log',

    'ini' => [
        // 'error_reporting' => E_ERROR,
        // 'display_errors' => true,
        // 'log_errors' => false,
        // 'error_log' => $root . '/logs/ini.log'
    ],
    'xdebug' => [
        'cli_color' => 1,
        'var_display_max_children' => 256,
        'var_display_max_data' => 1024,
        'var_display_max_depth' => 10,
    ],
    'error_logger' => [
        'channel' => 'errors'
    ],
    'error_handler' => [
        'host_os' => env('HOST_OS'),
        'host_path' => env('HOST_PATH'),
        'guest_path' => env('GUEST_PATH'),
    ],
    'var_dumper' => [
        'root' => $root,
        'theme' => 'dark',
        // 'server_host' => '',
    ],
]);

// undefined_function();
$var = $undefinedVariable;
