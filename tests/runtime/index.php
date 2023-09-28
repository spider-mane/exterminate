<?php

use Dotenv\Dotenv;
use WebTheory\Exterminate\Exterminator;

use function Env\env;

$root = dirname(__DIR__, 2);

require_once $root . '/vendor/autoload.php';

$env = Dotenv::createUnsafeImmutable($root);
$env->load();
$env->required(['HOST_OS', 'HOST_PATH', 'GUEST_PATH']);

Exterminator::debug([

    'enable' => true,
    'display' => true,
    'editor' => 'vscode',
    'log' => $root . '/logs/basic.log',
    'root' => $root,

    'system' => [
        'host_os' => env('HOST_OS'),
        'host_path' => env('HOST_PATH'),
        'guest_path' => env('GUEST_PATH'),
    ],

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
        // 'file_link_format' => '',
    ],

    'error_logger' => [
        'channel' => 'errors',
    ],

    'error_handler' => [
        // 'host_os' => env('HOST_OS'),
        // 'host_path' => env('HOST_PATH'),
        // 'guest_path' => env('GUEST_PATH'),
    ],
    // 'error_handler' => true,

    'var_dumper' => [
        'root' => $root,
        'theme' => 'dark',
        // 'server_host' => '',
    ],
]);

// dd(new DateTime());
// undefined_function();
$var = $undefinedVariable; //@phpstan-ignore-line
