# Verification

Verified 2026-10-04 on a disposable synthetic fixture, never a live PIM.

## Environment and automated gates

- UnoPIM v3.1.3, upstream commit `6a35666490ff0deb37bc317fd33045e557a573a4`, exact upstream lock.
- PHP 8.4.24 in the existing local `webkul/unopim:latest` image; MySQL 8.0; Node 24.19.0; Chrome 154 on macOS.
- Package PHPUnit: **55 tests, 121 assertions**.
- Native integration PHPUnit: **11 tests, 72 assertions**, real routing, admin ACL, CSRF, MySQL transactions and native audit storage. Per-test rollback; fixture setup instructions in [scripts/README.md](../scripts/README.md).
- Client Node tests: **8 passing**. Includes multiline control selection, CR/CRLF preservation boundary, session redirects, Blade-provided CSRF token, drafts, duplicates, order and errors.
- `composer validate --strict`: passes. Laravel auto-discovery metadata inspected. No clean consumer Composer installation was attempted: native fixture registers package source directly.
- Nonblocking tooling notices: Node's module-type warning; reviewer reported a PHPUnit deprecation. These are not represented as zero-warning runs.

## Controlled regression evidence

- Huge managed JSON numbers were previously coerced into editable strings; leading NUL names threw an uncontrolled PHP error and embedded NUL names passed. Four failing unit cases became green after minimal fixes. Byte/row boundary cases also pass.
- Client CR/CRLF and multiline tests failed before the textarea/read-only boundary fixes, then passed.
- Fixture owner demonstrated missing provider registration as a controlled RED (expected 200, actual 404), restored registration, then reran the full integration suite green.
- Actual native browser use discovered missing CSRF metadata and a Vue/module initialization race. The Blade view now supplies the native session token and pushes its module into the native script stack outside the Vue mount. Real browser saves succeeded after these fixes.

## Native browser evidence

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

## Limits

- Tested browser paths do not establish compatibility with other UnoPIM releases, browser engines, database engines, deployment modes, custom audit drivers, or custom navigation plugins.
- Additional data remains unscoped JSON: no native filtering/completeness/variant semantics are added.
- CR/CRLF sections are intentionally read-only in the browser; LF is editable. Unsupported types are never coerced.
- History uses section-level JSON snapshots, not a per-row diff. Synchronous database auditing on the same connection is required; save rolls back when history cannot be recorded.
- Source registration is verified, Composer consumer installation remains unverified. Repository/branch source installation is documented without implying a registry release.
