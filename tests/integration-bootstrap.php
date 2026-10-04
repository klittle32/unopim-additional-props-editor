<?php

$upstream = dirname(__DIR__).'/.workbench/unopim';
if (! is_file($upstream.'/vendor/autoload.php')) {
    throw new RuntimeException('Install the pinned upstream composer.lock first.');
}
$loader = require $upstream.'/vendor/autoload.php';
$loader->addPsr4('UnopimAdditionalPropsEditor\\', dirname(__DIR__).'/src');

function additionalPropsFixtureApp(): Illuminate\Foundation\Application
{
    $app = require dirname(__DIR__).'/.workbench/unopim/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('database.default') !== 'mysql'
        || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'host.docker.internal'], true)
        || config('database.connections.mysql.username') !== 'fixture'
        || config('database.connections.mysql.password') !== 'fixture-only'
        || (string) config('database.connections.mysql.port') !== '13316'
        || config('database.connections.mysql.database') !== 'additional_props_fixture') {
        throw new RuntimeException('Refusing to use anything except the disposable loopback fixture database.');
    }
    if (class_exists(UnopimAdditionalPropsEditor\AdditionalPropsServiceProvider::class)) {
        $app->register(UnopimAdditionalPropsEditor\AdditionalPropsServiceProvider::class);
        // This isolated harness registers after kernel boot, unlike an installed package.
        $app['router']->getRoutes()->refreshNameLookups();
        $app['router']->getRoutes()->refreshActionLookups();
    }
    return $app;
}
