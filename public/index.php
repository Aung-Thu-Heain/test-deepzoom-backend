<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is running in the console.
if (PHP_SAPI === 'cli') {
    return (require __DIR__.'/../bootstrap/app.php')->handleCommand(new Symfony\Component\Console\Input\ArgvInput);
}

require __DIR__.'/../vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$request = Request::capture();

$app->handleRequest($request);

// $response->send();