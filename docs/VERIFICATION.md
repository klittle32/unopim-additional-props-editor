# Verification

Automated mutation tests below ran on disposable synthetic fixtures on
2026-10-04. A later non-mutating deployment acceptance is recorded separately.

## Environment and automated gates

- UnoPIM v3.1.3, upstream commit `6a35666490ff0deb37bc317fd33045e557a573a4`, exact upstream lock.
- PHP 8.4.24 in the existing local `webkul/unopim:latest` image; MySQL 8.0; Node 24.19.0; Chrome 154 on macOS.
- Package PHPUnit: **55 tests, 121 assertions**.
- Native integration PHPUnit: **14 tests, 103 assertions on each of MySQL 8.0 and PostgreSQL 16**, real routing, admin ACL, CSRF, transactions and native audit storage. Per-test rollback; exact fixture/test commands in [scripts/README.md](../scripts/README.md). Coverage includes SQL/JSON null roots, both sections in one save, numeric-looking keys, empty objects/arrays, unknown huge integers, native-field preservation, conflicts, no-op saves, audit exceptions/vetoes and disabled/queued history.
- Client Node tests: **8 passing**. Includes multiline control selection, CR/CRLF preservation boundary, session redirects, Blade-provided CSRF token, drafts, duplicates, order and errors.
- `composer validate --strict`: passes. Independent native consumer path-repository installation succeeded (one new package, zero dependency updates/removals), with automatic discovery, both routes, and asset publication. It reused installed pinned upstream dependencies rather than downloading into empty vendor. The integration fixture separately registers source directly.
- Consumer Composer audit reports three existing upstream advisories affecting `league/commonmark` (two) and `phpseclib/phpseclib` (one); upstream test-class PSR-4 warnings also remain. Dependency remediation is outside this portability change.
- Nonblocking tooling notices: Node's module-type warning; reviewer reported a PHPUnit deprecation. These are not represented as zero-warning runs.

## Controlled regression evidence

- Before the storage change, the PostgreSQL numeric-name/both-section test failed: **1 test, 2 assertions, 1 failure**, expected 200 but received the MySQL-only 503. Laravel grammar-compiled updates replaced handwritten SQL and the driver guard; both engine suites are now green. No full-document PHP round-trip or package driver branches were added.

- Huge managed JSON numbers were previously coerced into editable strings; leading NUL names threw an uncontrolled PHP error and embedded NUL names passed. Four failing unit cases became green after minimal fixes. Byte/row boundary cases also pass.
- Client CR/CRLF and multiline tests failed before the textarea/read-only boundary fixes, then passed.
- Fixture owner demonstrated missing provider registration as a controlled RED (expected 200, actual 404), restored registration, then reran the full integration suite green.
- Actual native browser use discovered missing CSRF metadata and a Vue/module initialization race. The Blade view now supplies the native session token and pushes its module into the native script stack outside the Vue mount. Real browser saves succeeded after these fixes.

## Native browser evidence

The following browser mutation evidence predates the portability change (MySQL
only). PostgreSQL browser acceptance was limited to loading the deployed editor
and authenticated no-op requests, as described below.

Visible disposable Chrome, real native product pages and native history UI; no imitation fixture UI. CDP mouse/keyboard input exercised:

- Separate additional-data form after native Vue hydration.
- Add/edit/remove specifications and features, reorder bullets, save, reload, and fresh MySQL assertions.
- LF display, direct multiline editing and editing another row without changing LF content.
- Duplicate names rejected without overwriting data; HTML retained as literal text.
- Additional-only and hybrid edits leave native product columns unchanged except `updated_at`; unrelated additional subtree preserved.
- SQL NULL starts empty; incompatible stored sections visibly blocked and intact.
- Native History preview shows section snapshots attributed to the synthetic admin **Example**.
- Conflict response retains local edits, disables resaving, and requires explicit confirmed reload.
- Real native SPA departure triggers `beforeunload`; cancelling preserves the draft. Clean History/General navigation remounts the editor.
- CRLF limitation is visible; saving the editable sibling preserves the original CRLF bytes (fresh database HEX assertion).
- Clearing disposable browser session cookies causes the real native login redirect; the panel retains its unsaved draft and displays an actionable session-ended message.

Local ignored proof: `.workbench/browser-proof/` contains replay scripts, JSON results (9 editing/preservation checks plus 7 recovery/history checks), screenshots in light/dark themes, and CDP viewport recordings (`native-edit.mp4`, 4.2 seconds; `recovery.mp4`, 3.2 seconds). Both recordings were probed and their representative contact sheets visually inspected; screenshots of history, multiline data, conflict and session recovery were also inspected. The recordings are fast automated test captures, not narrated demos. These are viewport recordings, not full-desktop capture; no OS permission prompts, new global tooling, deployment, or public artifact publication were used. Fixture scripts and retained recordings contain synthetic data only.

## Cleanup

After verification, the owned disposable Chrome instance and only
`additional-props-fixture-app` / `additional-props-fixture-db` were stopped/removed.
Source, ignored recordings, screenshots and fixture dependencies were retained.
No commits, pushes, deployment, Docker pruning, or live-PIM changes were performed.
The subsequent portability fixtures, `additional-props-portable-mysql` and
`additional-props-portable-pgsql`, were also removed after independent reruns.
Ignored dependencies remain in `.workbench/unopim`, with the independent
installation smoke fixture in `.workbench/consumer`.

## Deployment acceptance after portability

- Independent review found no blocking findings; independent reruns passed both
  database integration suites, the PHP unit suite, and client tests.
- Installed on UnoPIM 3.1.3 with PHP 8.4.26 and PostgreSQL 16 using a Composer
  path package. Existing dependency lock entries compared unchanged.
- Authenticated native browser checks verified editable specifications and
  feature controls on additional-only and hybrid products. Authenticated no-op
  PATCH requests returned 200; changed saves were not tested on live records.
- Catalog and migration-history fingerprints matched before and after
  deployment. The pre-install backup passed an isolated restore check.
- The owned verification browser and temporary credential copy were removed.

## Limits

- Tested browser paths do not establish compatibility with other UnoPIM releases, browser engines, database engines, deployment modes, custom audit drivers, or custom navigation plugins.
- Additional data remains unscoped JSON: no native filtering/completeness/variant semantics are added.
- CR/CRLF sections are intentionally read-only in the browser; LF is editable. Unsupported types are never coerced.
- History uses section-level JSON snapshots, not a per-row diff. Synchronous database auditing on the same connection is required; save rolls back when history cannot be recorded.
- Source registration, Composer path installation/discovery, and deployed browser loading/no-op requests are verified. Empty-vendor and VCS/registry installation, and changed saves through a deployed PostgreSQL browser, remain unverified. Source installation documentation does not imply a registry release.
