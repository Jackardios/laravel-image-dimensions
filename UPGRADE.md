# Upgrade Guide

## From v1.x to v2.0

v2.0 is a breaking release. It fixes several correctness and security bugs, adds
new source methods, and updates the supported platform matrix. Most application
code that only *reads* the result keeps working, but review the items below.

### Platform requirements

- **PHP 8.2+** (was 8.1+).
- **Laravel 12.x or 13.x.** **Laravel 10 and 11 support is dropped.** Laravel 10
  would pull the package's PHP floor back to 8.1. Laravel 11 is past its
  security-support window: every 11.x release, up to and including the final
  v11.55.0, carries unpatched security advisories, so a current Composer refuses
  to install it unless the advisory policy is switched off. Advertising support
  for a version users cannot install cleanly would be misleading. Applications
  on Laravel 10 or 11 should stay on `v1.x` or upgrade the framework first.
- `guzzlehttp/guzzle: ^7.8` is now a hard requirement (it is no longer pulled in
  transitively by `illuminate/http`).

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

### Cache semantics

- `cache_ttl: 0` (or any value ≤ 0) now means **caching disabled** for these
  lookups (previously it silently erased the entry immediately). Use a positive
  number of seconds, or `null` to cache forever.
- Cache keys are namespaced under `image_dimensions:v2:`. This invalidates all
  v1 entries — including any incorrect dimensions produced by the old `viewBox`
  miscalculation — on the first request after upgrading. No manual flush needed.

### New download limit

Remote sources are now capped at `max_download_bytes` (default 32MB). A larger
source throws `FileTooLargeException`. Set it to `0` to disable the limit
(not recommended). A `Content-Length` that already exceeds the cap is rejected
before any body is read.

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
