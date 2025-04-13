<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;

$projectRoot = dirname(dirname(__DIR__) . '/../');
chdir($projectRoot);

$request = Request::createFromGlobals();
$app = new \App\Application();

$response = $app->handle($request);
$response->send();