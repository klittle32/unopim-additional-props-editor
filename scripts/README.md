# Disposable native integration fixture

Run from this package root on Docker Desktop. Never point these scripts at a live PIM.
Prerequisites: existing local images `webkul/unopim:latest` (tested PHP 8.4.24)
and `mysql/mysql-server:8.0` / `postgres:16-alpine`; official UnoPim **v3.1.3**, commit
`6a35666490ff0deb37bc317fd33045e557a573a4`, checked out at `.workbench/unopim`
with its exact committed Composer lock dependencies already installed (including dev dependencies).
Do not use host PHP 8.5 or ignore Composer platform requirements. No image pull/build is needed.

## Setup (once)

Check `docker ps -a` and that ports 13316/15436 are free first. Do not replace an existing container with either owned name. Existing fixtures can be reused without reseeding.

```sh
docker run -d --name additional-props-portable-mysql \
  -p 127.0.0.1:13316:3306 \
  -e MYSQL_ROOT_PASSWORD=fixture-root-only \
  -e MYSQL_DATABASE=additional_props_fixture \
  -e MYSQL_USER=fixture -e MYSQL_PASSWORD=fixture-only \
  mysql/mysql-server:8.0 --log-bin-trust-function-creators=1

# Wait until this succeeds before seeding:
docker exec additional-props-portable-mysql mysqladmin ping -h 127.0.0.1 -ufixture -pfixture-only

fixture_php() {
  docker run --rm --entrypoint php -v "$PWD:/workspace" -w /workspace \
    webkul/unopim:latest "$@"
}
fixture_php scripts/fixture.php configure
fixture_php scripts/fixture.php seed
fixture_php -r 'require "tests/integration-bootstrap.php"; additionalPropsFixtureApp(); Illuminate\Support\Facades\Artisan::call("vendor:publish", ["--tag" => "additional-props-assets", "--force" => true]); echo Illuminate\Support\Facades\Artisan::output();'
```

For the PostgreSQL fixture (same application and contract, separate database):

```sh
docker run -d --name additional-props-portable-pgsql \
  -p 127.0.0.1:15436:5432 \
  -e POSTGRES_DB=additional_props_fixture \
  -e POSTGRES_USER=fixture -e POSTGRES_PASSWORD=fixture-only postgres:16-alpine
docker exec additional-props-portable-pgsql pg_isready -U fixture -d additional_props_fixture
fixture_pg_php() {
  docker run --rm --entrypoint php -e DB_CONNECTION=pgsql -e DB_PORT=15436 \
    -v "$PWD:/workspace" -w /workspace webkul/unopim:latest "$@"
}
fixture_pg_php scripts/fixture.php seed
```

Configuration refuses to overwrite `.env`; seeding refuses an existing products table.
`configure pgsql` can instead create a PostgreSQL-default `.env` in a new fixture.
The bootstrap restricts access to MySQL port `13316` or PostgreSQL port `15436`
at `host.docker.internal` or `127.0.0.1`, database `additional_props_fixture`,
user `fixture`, password `fixture-only`; database URLs are rejected.
Native migrations and native installer seeders create synthetic data only.
The local application key and every credential here are disposable, not production secrets.

## Tests

```sh
fixture_php .workbench/unopim/vendor/bin/phpunit -c phpunit.integration.xml
fixture_pg_php .workbench/unopim/vendor/bin/phpunit -c phpunit.integration.xml
```

Tests use real Laravel routing/authentication, CSRF, database engines, and audit history,
with per-test transactions rolled back. Both engines: **14 tests, 103 assertions**.
PostgreSQL portability RED: before changing storage, the new
`--filter test_both_sections_keep_numeric_names_object_shape_and_feature_order`
failed with **1 test, 2 assertions, 1 failure** (expected 200, actual 503).
After implementing grammar-compiled bound JSON path updates, both full suites pass.

Earlier provider-registration regression evidence (before portability):
Controlled RED was demonstrated by temporarily commenting out only the package
`$app->register(...)` call in `tests/integration-bootstrap.php` and running:

```sh
fixture_php .workbench/unopim/vendor/bin/phpunit -c phpunit.integration.xml \
  --filter test_read_is_authenticated_and_does_not_mutate
```

Result: **1 test, 1 assertion, 1 failure**, expected 200 but received **404**.
Restoring registration returned the full suite to GREEN. Do not leave registration disabled.

## Consumer installation smoke test

An independent `.workbench/consumer` copy of the pinned native application and its
installed dependencies was tested, without the integration bootstrap or manual provider registration:

```sh
consumer_composer() {
  docker run --rm --entrypoint composer -v "$PWD:/workspace" \
    -w /workspace/.workbench/consumer webkul/unopim:latest "$@"
}
consumer_php() {
  docker run --rm --entrypoint php -v "$PWD:/workspace" \
    -w /workspace/.workbench/consumer webkul/unopim:latest "$@"
}
consumer_composer config repositories.additional-props path /workspace
consumer_composer require 'klittle32/unopim-additional-props-editor:@dev' --no-interaction
consumer_php artisan route:list --path=additional-data
consumer_php artisan vendor:publish --tag=additional-props-assets --force
```

Result: one package installed, zero upstream dependency updates/removals; automatic
provider discovery succeeded, both routes appeared, assets published. This reuses
pinned dependencies, not a fresh empty-vendor download. Composer reported existing
upstream PSR-4 test-class warnings and three advisories in `league/commonmark` and
`phpseclib/phpseclib`; no dependencies or tracked locks were changed to hide them.

## Browser fixture

```sh
docker run -d --name additional-props-fixture-app --entrypoint php \
  -v "$PWD:/workspace" -w /workspace -p 127.0.0.1:41983:8000 \
  webkul/unopim:latest -d opcache.enable=0 -d opcache.enable_cli=0 \
  -S 0.0.0.0:8000 -t .workbench/unopim/public scripts/serve-fixture.php
```

Open http://127.0.0.1:41983/admin/login and log in with
`editor@example.test` / `Fixture-only-123!`.
On a fresh database, product edit URLs `/admin/catalog/products/edit/1` through `/4`
are respectively `fixture-additional`, `fixture-hybrid`, `fixture-empty`, and
`fixture-incompatible`. The seed command prints exact URLs.
Login, dashboard, and all four edit pages were verified over authenticated HTTP (200).
Native browser interaction results and retained proof are documented in
[verification](../docs/VERIFICATION.md).

After asset changes, repeat the publish command. If using an older server command
without `-d opcache.enable=0`, restart **only** `additional-props-fixture-app`
after PHP/Blade edits to avoid stale opcode cache. The built-in web server uses
`cli-server`, so disabling only `opcache.enable_cli` is insufficient. No upstream source edits are needed.

## Cleanup

Only after browser/test work is finished:

```sh
docker rm -f additional-props-fixture-app additional-props-portable-mysql additional-props-portable-pgsql
```

Do not prune Docker, remove unrelated containers/volumes, or run migration resets
against other databases. Keep `.workbench` ignored; it contains runtime dependencies,
fixture configuration, logs, sessions, and published assets, not package source.
