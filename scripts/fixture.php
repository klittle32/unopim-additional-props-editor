<?php

// Disposable fixture only. Never accepts an external database URL or credentials.
$root = dirname(__DIR__);
$upstream = $root.'/.workbench/unopim';
if (($argv[1] ?? '') === 'configure') {
    if (file_exists($upstream.'/.env')) {
        throw new RuntimeException('Fixture .env already exists; inspect it instead of overwriting.');
    }
    $driver = $argv[2] ?? 'mysql';
    $ports = ['mysql' => '13316', 'pgsql' => '15436'];
    if (! isset($ports[$driver])) {
        throw new RuntimeException('Fixture driver must be mysql or pgsql.');
    }
    $environment = <<<'ENV'
APP_NAME="Additional Props Fixture"
APP_ENV=local
APP_KEY=base64:YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=
APP_DEBUG=true
APP_URL=http://127.0.0.1:41983
APP_ADMIN_URL=admin
APP_LOCALE=en_US
APP_TIMEZONE=UTC
DB_CONNECTION=mysql
DB_HOST=host.docker.internal
DB_PORT=13316
DB_DATABASE=additional_props_fixture
DB_USERNAME=fixture
DB_PASSWORD=fixture-only
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
MAIL_MAILER=log
ELASTICSEARCH_ENABLED=false
ENV;
    file_put_contents($upstream.'/.env', str_replace(
        ['DB_CONNECTION=mysql', 'DB_PORT=13316'],
        ['DB_CONNECTION='.$driver, 'DB_PORT='.$ports[$driver]],
        $environment,
    ));
    exit;
}
require $root.'/tests/integration-bootstrap.php';
$app = additionalPropsFixtureApp();
if (($argv[1] ?? '') !== 'seed') {
    throw new RuntimeException('Usage: php scripts/fixture.php configure|seed');
}
// No migrate:fresh: refuse to erase an existing database.
if (Illuminate\Support\Facades\Schema::hasTable('products')) {
    throw new RuntimeException('Already seeded; remove/recreate only the owned fixture container to reset.');
}
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
echo Illuminate\Support\Facades\Artisan::output();
(new Webkul\Installer\Database\Seeders\DatabaseSeeder)->run([
    'admin_email' => 'editor@example.test',
    'admin_password' => 'Fixture-only-123!',
    'default_locale' => 'en_US',
    'default_currency' => 'USD',
]);
$examples = [
    'fixture-additional' => ['attributes' => ['Material' => 'Cotton', ' Care ' => ' Hand wash '], 'features' => ['Soft', 'Washable']],
    'fixture-hybrid' => ['attributes' => ['Imported colour' => 'Blue'], 'features' => ['Imported feature'], 'untouched' => ['vendor' => 'Synthetic']],
    'fixture-empty' => null,
    'fixture-incompatible' => ['attributes' => ['Nested' => ['unsupported']], 'features' => [17]],
];
foreach ($examples as $sku => $additional) {
    $product = Webkul\Product\Models\Product::factory()->withInitialValues()->create([
        'sku' => $sku,
        'additional' => $additional,
        'values' => ['common' => ['sku' => $sku], 'channel_locale_specific' => ['default' => ['en_US' => ['name' => $sku, 'description' => 'Synthetic native description']]]],
    ]);
    echo $sku.': http://127.0.0.1:41983/admin/catalog/products/edit/'.$product->id.PHP_EOL;
}
file_put_contents($upstream.'/storage/installed', 'disposable additional-props fixture');
