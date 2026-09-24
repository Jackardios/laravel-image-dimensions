# Upgrade Guide

## From v1.x to v2.0

v2.0 is a breaking release. It fixes several correctness and security bugs, adds
new source methods, and updates the supported platform matrix. Most application
code that only *reads* the result keeps working, but review the items below.

### Platform requirements

- **PHP 8.2+** (was 8.1+).
- **Laravel 12.x or 13.x.** **Laravel 10 and 11 support is dropped.** Laravel 10
  would pull the package's PHP floor back to 8.1. Laravel 11 is past its
  security-support window: every 11.x release, up to v11.56.1 (the latest at the
  time of writing), is affected by CVE-2026-48019, fixed only in 12.60.0 and
  13.10.0, so a current Composer refuses to install it unless the advisory
  policy is switched off. Advertising support for a version users cannot install
  cleanly would be misleading. Applications on Laravel 10 or 11 should stay on
  `v1.x` or upgrade the framework first.
- **`ext-curl` is required.** Downloads go through cURL, which enforces
  `http.timeout` as a deadline for the whole request. Without it Guzzle would
  fall back to its stream handler, where the timeout limits each read instead.
  `ext-fileinfo` is no longer needed.
- `guzzlehttp/guzzle: ^7.15.2 || ^8.0.1` is a direct requirement. Older 7.x
  releases and 8.0.0 have published security advisories.
- `symfony/polyfill-intl-idn` converts internationalized host names when
  `ext-intl` is not installed.

Update your constraint:

```bash
composer require jackardios/laravel-image-dimensions:^2.0
```

### Return value: `Dimensions` instead of an array

Every `from*` method now returns an immutable
`Jackardios\ImageDimensions\Dimensions` object instead of an
`['width' => int, 'height' => int]` array.

**What keeps working (verified):**

- Array access — `$result['width']`, `$result['height']` — via `ArrayAccess`.
- JSON encoding — `json_encode($result)` still yields `{"width":..,"height":..}`
  via `JsonSerializable`.

**What breaks and must be changed:**

- `is_array($result)` returns `false`; strict `array` type hints/returns on your
  side will fail.
- `assertEquals(['width' => .., 'height' => ..], $result)` in tests **fails** —
  an array is never `==` to an object. Compare against `$result->toArray()`, or
  assert `$result->width` / `$result->height` directly.
- Direct array mutation (`$result['width'] = 100`) now throws `LogicException`
  (the object is immutable).

Convenience accessors on the new object: `->width`, `->height`, `->ratio()`,
`->isLandscape()`, `->isPortrait()`, `->isSquare()`, `->toArray()`,
`(string) $result` (`"800x600"`).

### The service is scoped, not a singleton

The service reads its configuration when it is built. It used to be built once
per process, so under Octane or in a queue worker, a configuration change made
at run time (`config()->set()`, per-tenant settings) never reached it. It is
now a scoped binding: rebuilt for each Octane request and each queued job,
and shared by the facade and every binding within one.

If you extend the class or replace the binding, bind yours with `scoped()` too.
Code that kept state on the instance across requests or jobs will find a new
instance.

### SSRF protection is on by default

`fromUrl()` now blocks hosts that resolve to private, loopback, link-local, or
reserved addresses (including `169.254.169.254`). A blocked URL throws
`UrlNotAllowedException` (a subclass of `UrlAccessException`).

To restore the previous unrestricted behaviour:

```php
// config/image-dimensions.php
'url' => [
    'allow_private_hosts' => true,
],
```

or set `IMAGE_DIMENSIONS_URL_ALLOW_PRIVATE_HOSTS=true`. For strict environments,
prefer `url.allowed_hosts` (an allowlist) over disabling the guard.

### URLs

- A URL is normalized before it is checked, cached and fetched: an
  internationalized host becomes punycode, spaces and other non-ASCII
  characters in the path or query are percent-encoded, the fragment and a
  default port are dropped. URLs that v1 rejected as invalid, such as
  `https://пример.рф/a.png`, `https://my_bucket.s3.amazonaws.com/a.png` or
  `https://example.com/a b.png`, are now fetched.
- Exception messages no longer quote a URL in full: user info becomes `***@`,
  query values become `***` (names are kept), and the fragment is left out. If
  you parse these messages, expect the redacted form. The previous exception
  may still quote the full URL.
- A blocked address is no longer named in the message: it told the caller
  what an internal host name resolves to.

### Corrected exception types

- A **non-image fetched over a URL** now throws `InvalidImageException` (it
  previously surfaced as `UrlAccessException`). If you relied on catching
  `UrlAccessException` for "not an image", switch to `InvalidImageException`.
- Two new exceptions were added; both extend existing types, so existing
  `catch` blocks still match:
  - `FileTooLargeException extends InvalidImageException`
  - `UrlNotAllowedException extends UrlAccessException`
- SVG parsing failures no longer leak a raw `TypeError`; they throw
  `InvalidImageException`.

### Configuration values

Values from environment variables are no longer cast blindly:

- An empty value gives the default. `IMAGE_DIMENSIONS_MAX_DOWNLOAD_BYTES=`
  used to remove the download limit, and `IMAGE_DIMENSIONS_CACHE_TTL=` turned
  caching off.
- `off` and `no` are false. `IMAGE_DIMENSIONS_URL_ALLOW_PRIVATE_HOSTS=off`
  used to allow private hosts.
- Timeouts keep their fractions; `0.5` used to become `0`, which means no
  timeout.
- `url.allowed_hosts` may also be a comma-separated string.
- `temp_dir` defaults to `null`, resolved when a lookup runs. The v1 config
  file called `sys_get_temp_dir()`, so `php artisan config:cache` baked in the
  temp directory of the machine that built the cache. If you published the
  config, replace that line with `'temp_dir' => env('IMAGE_DIMENSIONS_TEMP_DIR'),`.

### Formats and measuring

- The format is recognised from the contents only. A PNG named `.svg` is read
  as a PNG; markup is always parsed as SVG.
- HEIF/HEIC is supported on every PHP version, cropped to its clean aperture.
- WBMP is no longer accepted: without a signature, almost any bytes starting
  with two zero bytes passed for one.
- SVG: an explicit `width="0"` or `height="0"` is an error instead of falling
  back to the `viewBox`; when only one side is given, the other follows the
  `viewBox` aspect ratio.
- `fromStream()` reads a seekable stream from its start and restores its
  position afterwards. It used to read from wherever the stream was, and leave
  it at the end.

### Subclasses of the service

Protected methods changed with the new download and stream handling;
`resolveFromStream()`, for one, no longer takes a `TemporaryFile`. Code that
extends `ImageDimensionsService` and overrides its internals needs a review.

### Cache semantics

- `cache_ttl: 0` (or any value ≤ 0) now means **caching disabled** for these
  lookups (previously it silently erased the entry immediately). Use a positive
  number of seconds, or `null` to cache forever.
- Cache keys are namespaced under `image_dimensions:v2:`. This invalidates all
  v1 entries — including any incorrect dimensions produced by the old `viewBox`
  miscalculation — on the first request after upgrading. No manual flush needed.
- A failing cache store is thrown as it is, by `tryFrom*()` as well: it is
  neither a bad input nor a failing source.

### New download limit

Remote sources are now capped at `max_download_bytes` (default 32MB). A larger
source throws `FileTooLargeException`. Set it to `0` to disable the limit
(not recommended). A download stops as soon as its first `remote_read_bytes`
give the dimensions, so a large image with a small header is not rejected: a
`Content-Length` over the cap only fails the request once the header has
proved insufficient.

### SVG "sanitisation" removed

v1 ran regex-based "XSS sanitisation" over SVG markup. It provided no real
protection (this package never renders SVGs) while breaking valid documents. It
has been removed. If you serve user-supplied SVGs to browsers, sanitise them
with a dedicated tool — that was never this package's responsibility.

### New capabilities (no action required)

- New source methods: `fromContents()`, `fromStream()`, `fromUploadedFile()`.
- Non-throwing variants for every method: `tryFromLocal()`, `tryFromUrl()`,
  `tryFromStorage()`, `tryFromContents()`, `tryFromStream()`,
  `tryFromUploadedFile()` — each returns `?Dimensions`.
- The service is bound to its contract
  `Jackardios\ImageDimensions\Contracts\ImageDimensions`, so you can type-hint
  the interface for dependency injection.
- Guzzle 8 is supported alongside Guzzle 7.
