<?php

// Run using the loopback-only Docker command in scripts/README.md.
$public = dirname(__DIR__).'/.workbench/unopim/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($public.$path) && str_starts_with(realpath($public.$path), realpath($public).DIRECTORY_SEPARATOR)) {
    return false;
}
require dirname(__DIR__).'/tests/integration-bootstrap.php';
$app = additionalPropsFixtureApp();
$app->handleRequest(Illuminate\Http\Request::capture());
