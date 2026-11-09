# Integration checks

Run only on a disposable WordPress install with `WP_ENVIRONMENT_TYPE` set to `local` or `development`. The suites refuse other environment types. Activate Virtual Try-On Studio; the legacy WooCommerce suites additionally need WooCommerce. Enable GD, mbstring, fileinfo, and an available database driver in PHP. The tests create and delete their own product, attachment, jobs, and private images, and restore store settings after completion. Rate-limit counters may remain until maintenance removes them.

Use an administrator with ID 1 on this disposable install. Never point these scripts at a merchant's live site or database.

The independent raw-artwork workflow has a suite that excludes WooCommerce from the current process without changing the site's plugin activation state:

```sh
VTO_WP_ROOT=/absolute/path/to/wordpress php tests/raw-designs.php
```

It checks gallery settings and inactive designs, preservation of settings across separate forms, complete artwork as the second reference without cropping, bounded full-frame dimensions for large artwork, exactly two images, economical request fields, and single-generation execution. Every provider call is intercepted; no paid generation occurs.

```sh
VTO_WP_ROOT=/absolute/path/to/wordpress php tests/integration.php
```

The PHP suite checks WooCommerce product visibility and allowlisting, private storage, invalid image rejection, durable job creation, hashed bearer tokens, idempotent processing, snapshotted demo/live mode and image quality, safe legacy-quality migration, the automatic site-product detail reference and multipart image order, unchanged-output rejection, bounded retries, safe upstream errors, expiry and deletion, atomic rate limits, and settings sanitization. It intercepts the AI HTTP call; no paid AI request is sent.

Start the WordPress site, for example:

```sh
php -S 127.0.0.1:8088 -t /absolute/path/to/wordpress
```

Set `WP_HOME` and `WP_SITEURL` to this origin in the disposable site's `wp-config.php`. Then run:

```sh
VTO_WP_ROOT=/absolute/path/to/wordpress VTO_PHP=php python3 tests/http-smoke.py
```

The Python suite uses only the standard library and real HTTP requests. It checks multipart uploads, nonces, consent, origin validation, product allowlisting, malicious files, queued/completed status, private image streaming and download, expiry, administrator access, and logged-in and guest submissions. It runs the demo worker directly through PHP; no external image provider is contacted. Keep WP-Cron disabled on the test installation to make the queued-state assertions deterministic.

Optional `VTO_BASE_URL` overrides the site's home URL for HTTP requests. Optional `VTO_PHP` specifies the PHP binary used by the HTTP suite. `VTO_WP_LOAD` is an alternative to `VTO_WP_ROOT` for the PHP suite.

Validated in this workspace with PHP 8.4.27, WordPress 7.0, WooCommerce 11.2.1 and the official WordPress SQLite database integration. SQLite validation does not establish compatibility with every MySQL/MariaDB deployment, theme, caching layer, or reverse proxy. Run these checks again on a staging copy of the intended host before release.

Provider output parsing and result recovery have a separate real-WordPress suite:

```sh
VTO_WP_ROOT=/absolute/path/to/wordpress php tests/provider-results.php
```

This suite intercepts every outbound HTTP request. It exercises standard base64 and URL image results, private signed-reference storage, result-download failures, GET-only recovery without a second paid generation, separate recovery limits, safe redirects and URL validation, invalid image responses, redacted unknown-response diagnostics, and reference-file cleanup. It does not validate the live provider account or undocumented response formats.

The scoped generation-timeout guard has a separate suite:

```sh
VTO_WP_ROOT=/absolute/path/to/wordpress php tests/http-timeouts.php
```

It introduces a simulated third-party 30-second override, verifies the owned AvalAI POST restores its configured deadline, and checks that unrelated requests, model probes and requests without the private identity marker remain unchanged. It exercises the installed WordPress Requests bridge with all five reference arguments, iterable multipart headers, late transport options, per-request replay prevention, and cleanup of all three global hooks after success, errors and exceptions. Missing hook-manager capability and transports without cURL are checked separately so diagnostics do not overstate protection. With the cURL extension enabled, isolated namespace observers delegate option writes to native `curl_setopt` and record the values applied by the real WordPress cURL hook. A `curl_errno` observer simulates errors 23 and 61 to verify that the fixed Requests exception prevents reaching the replay branch on the owned hook instance. No `curl_exec` or live HTTP request occurs. This proves option application and hook behavior; it does not establish that a real hosting proxy allows a request to run longer than 30 seconds. Native and optional-constant checks are explicitly skipped when unavailable.

Run the PHP suites sequentially: they temporarily change and restore the same disposable store settings.

For the raw-design gallery, administrator media picker, partial settings save, local font, mobile layout and customer submission/result flow, start the local PHP server and run:

```sh
VTO_WP_ROOT=/absolute/path/to/wordpress VTO_PHP=php VTO_PLAYWRIGHT=/path/to/playwright-core node tests/design-gallery-browser.cjs
```

`VTO_CHROMIUM` selects an installed Chromium executable and `VTO_PHOTO_FIXTURE` selects a non-sensitive local photo fixture. This test temporarily disables WooCommerce on the disposable site, uses demo generation, creates and then removes its artwork/page/job fixtures, and restores settings, plugin state and the test login session. Browser HTTP to non-local destinations is blocked. Screenshots are saved under `artifacts/design-gallery`; they show the UI with synthetic design/photo fixtures, not a live provider try-on result.
