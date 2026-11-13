Run these checks against a disposable WordPress installation with the plugin active (PHP 8.2+). The PHP checks temporarily change plugin/timezone options and create a draft, then restore the options and remove the draft in `finally`.

```sh
WP_ROOT=/absolute/path/to/wordpress php tests/schedule.php
```

The editor checks use the same schedule fixtures with WordPress's actual date API in Chromium. Install Playwright in your development environment and its Chromium browser, then run:

```sh
WP_URL=http://localhost:8080 node tests/editor.cjs
```

Set `CHROMIUM_PATH` to use an existing Chromium executable. The browser runs in a different timezone from the site to detect accidental reliance on browser-local dates. Fixtures cover legacy schedules, all recurrence frequencies, intervals, visibility boundaries, overlapping and overnight windows, short months, leap years, fixed offsets, and daylight saving transitions. PHP integration checks also cover block rendering, placeholders, bypass settings, nested deletion, stale deletion jobs, and recurring Breeze cron events.
