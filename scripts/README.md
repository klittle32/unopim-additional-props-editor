# Disposable native integration fixture

Run from this package root on Docker Desktop. Never point these scripts at a live PIM.
Prerequisites: existing local images `webkul/unopim:latest` (tested PHP 8.4.24)
and `mysql/mysql-server:8.0`; official UnoPim **v3.1.3**, commit
`6a35666490ff0deb37bc317fd33045e557a573a4`, checked out at `.workbench/unopim`
with its exact committed Composer lock dependencies already installed (including dev dependencies).
Do not use host PHP 8.5 or ignore Composer platform requirements. No image pull/build is needed.

## Setup (once)

Check `docker ps -a` first. Do not replace an existing container with either owned name.

```sh
docker run -d --name additional-props-fixture-db \
  -p 127.0.0.1:13316:3306 \
  -e MYSQL_ROOT_PASSWORD=fixture-root-only \
  -e MYSQL_DATABASE=additional_props_fixture \
  -e MYSQL_USER=fixture -e MYSQL_PASSWORD=fixture-only \
  mysql/mysql-server:8.0 --log-bin-trust-function-creators=1

# Wait until this succeeds before seeding:
docker exec additional-props-fixture-db mysqladmin ping -h 127.0.0.1 -ufixture -pfixture-only

fixture_php() {
  docker run --rm --entrypoint php -v "$PWD:/workspace" -w /workspace \
    webkul/unopim:latest "$@"
}
fixture_php scripts/fixture.php configure
fixture_php scripts/fixture.php seed
fixture_php -r 'require "tests/integration-bootstrap.php"; additionalPropsFixtureApp(); Illuminate\Support\Facades\Artisan::call("vendor:publish", ["--tag" => "additional-props-assets", "--force" => true]); echo Illuminate\Support\Facades\Artisan::output();'
```

Configuration refuses to overwrite `.env`; seeding refuses an existing products table.
The bootstrap restricts access to MySQL at `host.docker.internal` or `127.0.0.1`,
port `13316`, database `additional_props_fixture`, user `fixture`, password `fixture-only`.
Native migrations and native installer seeders create synthetic data only.
The local application key and every credential here are disposable, not production secrets.

## Tests

```sh
fixture_php .workbench/unopim/vendor/bin/phpunit -c phpunit.integration.xml
```

Tests use real Laravel routing/authentication, CSRF, MySQL, and audit history,
with per-test transactions rolled back. Baseline: **11 tests, 72 assertions**.
Controlled RED was demonstrated by temporarily commenting out only the package
`$app->register(...)` call in `tests/integration-bootstrap.php` and running:

```sh
fixture_php .workbench/unopim/vendor/bin/phpunit -c phpunit.integration.xml \
  --filter test_read_is_authenticated_and_does_not_mutate
```

Result: **1 test, 1 assertion, 1 failure**, expected 200 but received **404**.
Restoring registration returned the full suite to GREEN. Do not leave registration disabled.

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
docker rm -f additional-props-fixture-app additional-props-fixture-db
```

Do not prune Docker, remove unrelated containers/volumes, or run migration resets
against other databases. Keep `.workbench` ignored; it contains runtime dependencies,
fixture configuration, logs, sessions, and published assets, not package source.
