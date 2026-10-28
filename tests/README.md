# Scheduled Content Block 2.0.0 validation

Validated on WordPress **7.1.4**, PHP **8.2.31**, MariaDB **11.4**, and system Chromium using Playwright. The latest stable version was checked against the official `WordPress/WordPress` GitHub tags before testing. This suite exercises WordPress itself; it does not mock the block parser or date API.

## Integration checks

Use an isolated WordPress 7.1.4 installation with the plugin active, a `developer` administrator account, and revisions enabled. The plugin directory must be available to PHP. From its container:

```sh
php /var/www/html/wp-content/plugins/scheduled-content-block/tests/integration.php
```

Set `SCBLK_WP_ROOT` if WordPress is installed outside `/var/www/html`. The test restores changed options and deletes its test posts and custom role afterward. Run it only on a disposable development database.

The suite covers registered/custom roles and empty selections, bypass behavior, site timezone parsing, visible/hidden/invalid schedules, escaped placeholders, deletion of first/middle/last children, multiple nesting levels, unchanged siblings, idempotency, cron registration and execution, pre-deletion revisions, backslash preservation, script dependencies, and absence of frontend preview markup.

## Editor and frontend checks

Install Playwright in your development tooling and provide Chromium. The plugin itself still has no package dependencies or build step. Serve the isolated site on a loopback URL with the plugin source readable by the web server.

Prepare a temporary test user and post, redirecting credentials into a private file. Example from the host, replacing `docker compose exec` with the command for your local stack:

```sh
umask 077
docker compose exec -T -e SCBLK_TEST_SITE=1 wordpress \
  php /var/www/html/wp-content/plugins/scheduled-content-block/tests/browser-fixture.php \
  > /tmp/scblk-browser-config.json
SCBLK_BROWSER_CONFIG=/tmp/scblk-browser-config.json \
  node tests/editor.cjs
```

Use `SCBLK_CHROMIUM_PATH` if Chromium is not at `/usr/bin/chromium`. The JSON contains temporary local credentials and must not be committed. The fixture requires an explicitly marked local test site; it sets the site timezone to America/Chicago and bypass roles to administrators, creates an administrator named `scblk_browser_test`, and creates a draft post. The browser suite publishes that fixture only within the isolated local test installation to verify a real anonymous frontend response.

The browser runs in Asia/Tokyo to expose accidental browser-timezone conversions. It tests summer/winter times, DST gaps and repeated hours, a fixed +05:30 offset, all schedule statuses, date clearing and persistence, unchanged invalid ranges, editor-only preview at chosen/current times, preserved block content, anonymous frontend output, role form saves, clearing every bypass role, and browser exceptions.

After testing, delete the fixture post and test user, remove the private JSON, and discard the isolated test database. The fixture ID is in the private JSON. Do not run the browser fixture or suite against a live website.

## Release result

- 39 PHP integration checks passed.
- 27 Chromium editor/frontend checks passed.
- PHP and JavaScript syntax checks and `git diff --check` passed.
- Breeze integration and full frontend theme appearance were outside this change's scope; the preview simulates the selected container's visibility within the editor.
