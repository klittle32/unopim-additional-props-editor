# UnoPIM Additional Props Editor

An independent Laravel package adding an **Additional Product Data** panel to UnoPIM's native product editor. It manages flexible specifications and ordered feature bullets without replacing native attributes or requiring raw JSON editing.

Implemented and tested against UnoPIM **v3.1.3** (`6a35666490ff0deb37bc317fd33045e557a573a4`), PHP **8.4.24**, MySQL **8.0**, and PostgreSQL **16**. This is source-distributed development software, not a published Packagist release. See [verification evidence and limitations](docs/VERIFICATION.md).

## Install from source

Back up your PIM and test in staging first. In your UnoPIM application, configure a Composer path repository pointing at a checkout of this package:

```sh
composer config repositories.additional-props path /absolute/path/to/unopim-additional-props-editor
composer require 'klittle32/unopim-additional-props-editor:@dev'
php artisan vendor:publish --tag=additional-props-assets
php artisan optimize:clear
```

Alternatively configure a Composer `vcs` repository using this repository's Git URL and require the appropriate development branch/version. No registry publication is assumed. Laravel package discovery registers `UnopimAdditionalPropsEditor\AdditionalPropsServiceProvider`; if your application's `dont-discover` configuration disables it, register that provider explicitly. No core template edits, database migrations, Node build, or extra service are required.

The declared dependencies target PHP `^8.4.1` and Illuminate `^13.0`. The pinned upstream lock cannot run on the tested host's PHP 8.5: use a compatible runtime, not `--ignore-platform-reqs`. Other UnoPIM, database, or PHP combinations are unverified.

**Packaging boundary:** A separate native consumer successfully installed the package through a Composer path repository, discovered its provider and routes, and published its assets. Existing pinned upstream dependencies were reused; an empty-vendor download and VCS/registry installation were not tested.

## Use

Open an existing product as an admin with native `catalog.products.edit` permission. The panel appears below the native editor:

- Add/edit/remove specification name–value pairs.
- Add/edit/remove feature bullets and move them up or down.
- Use **Save additional data** to save this panel independently of native fields.
- Unsaved additional edits trigger a browser departure warning. Conflicts retain your draft; copy anything needed, then explicitly choose **Reload and discard edits**.

Native product saving is separate and does not save this panel. Additional-only and hybrid products use the same controls. Opening the panel does not write data. No-op saves do not create history.

## Storage contract

```json
{
  "additional": {
    "attributes": { "Material": "Steel", "Overall length": "200 mm" },
    "features": ["Comfortable grip", "Corrosion-resistant finish"],
    "metadata": { "source_reference": "example-123" }
  }
}
```

Only `additional.attributes` and `additional.features` are managed; unrelated keys are not re-encoded. Specifications are a JSON object of strings and features an ordered string array. Names are unique and nonblank (at most 255 UTF-8 bytes, no NUL); values/features are at most 64 KiB each; each section allows at most 1,000 rows; the request limit is 1 MiB. Numeric-looking names and string whitespace are retained. Object key ordering is not meaningful; feature ordering is.

Missing sections and SQL/JSON null roots are empty forms. Incompatible roots or section shapes, including numeric values, are preserved and read-only. An unsupported section does not prevent editing a supported sibling. LF newlines are editable using multiline controls; sections containing CR or CRLF are read-only in the browser to avoid silent newline normalization. Content is rendered as text, never HTML.

## Persistence and history

Routes use native admin sessions, product edit permission, and CSRF protection. Saves lock the product row, compare a SHA-256 token covering the complete stored `additional` value, and update only changed managed paths using Laravel's query builder and database grammar (no package driver branches). MySQL and PostgreSQL execute the JSON path updates in the database; the complete document is never decoded and rewritten by PHP. Other additional keys and native product fields remain unchanged; a real save updates `updated_at`.

The package deliberately bypasses the native product-saving observer (which can normalize native measurements). It instead records attributed native `AuditCustom` history, with JSON snapshots under **Additional specifications** and **Additional features**. History and data changes share a transaction. Disabled, queued, missing, or separate-connection audit storage causes saving to fail and roll back rather than silently omit history. History is section-level JSON, not a per-row visual diff. Standard API clients still read the same `additional` object.

## Upgrade and removal

After updating source/dependencies, republish assets and clear application caches:

```sh
php artisan vendor:publish --tag=additional-props-assets --force
php artisan optimize:clear
```

Republishing replaces package assets, including any local modifications to those assets. No stored product data migration is performed. To remove, remove the Composer dependency (and any explicit provider registration), clear caches, and optionally delete only `public/vendor/additional-props-editor`. Existing `additional` data and recorded history remain in your database and accessible to existing API clients. Back up before upgrades or removal.

## Development and tests

```sh
composer install
composer test
node --test tests/js/editor.test.mjs
composer validate --strict
```

[Disposable native fixture instructions](scripts/README.md) cover the pinned upstream checkout, MySQL/PostgreSQL, real routing/auth/CSRF/history tests, and browser server. Never run fixture setup against a live PIM. The fixture uses synthetic generic products and a disposable account. Test artifacts and dependencies belong under ignored `.workbench/`.

## Boundaries

This is not an enrichment engine or schema builder. There is no AI generation, connector, automatic attribute creation, or promotion into native attributes. Additional JSON does not acquire native filtering, completeness rules, channel/locale scoping, or variant inheritance; use native attributes when those capabilities are needed. Concurrent writes that bypass locking/version checks outside this package remain the responsibility of those integrations.

## References and license

- [UnoPIM product REST API](https://devdocs.unopim.com/3.1/api/product.html)
- [Package development](https://devdocs.unopim.com/3.1/packages/)
- [View-render events](https://devdocs.unopim.com/3.1/advanced/render-event.html)
- [Access control](https://devdocs.unopim.com/3.1/packages/create-acl.html)

Independent community project, not an official UnoPIM extension. [MIT](LICENSE).
