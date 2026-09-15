# Changelog

All notable changes to `jackardios/laravel-image-dimensions` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-07-25

A correctness- and security-focused rewrite. See [UPGRADE.md](UPGRADE.md) for
migration steps.

### Added

- `Dimensions` value object returned by every source method — immutable, with
  `->width`, `->height`, `->ratio()`, `->isLandscape()/isPortrait()/isSquare()`,
  `->toArray()`, `__toString()`, and array/JSON compatibility.
- New source methods: `fromContents()`, `fromStream()`, `fromUploadedFile()`.
- Non-throwing variants for every method: `tryFromLocal()`, `tryFromUrl()`,
  `tryFromStorage()`, `tryFromContents()`, `tryFromStream()`,
  `tryFromUploadedFile()`.
- `Contracts\ImageDimensions` interface; the service is bound to the concrete
  class, the contract, and the `image-dimensions` alias as a single singleton.
- SSRF protection for `fromUrl()` via a new `UrlGuard`, configurable through the
  `url.*` config block (`allow_private_hosts`, `allowed_hosts`, `max_redirects`).
- `max_download_bytes` config option capping how much of a remote source is read.
- New exceptions `FileTooLargeException` (extends `InvalidImageException`) and
  `UrlNotAllowedException` (extends `UrlAccessException`).
- Laravel 13 support; PHP 8.5 support.
- Tooling: PHPStan (level 8) via Larastan, and Laravel Pint. New composer
  scripts: `analyse`, `format`, `lint`, `check`.

### Changed

- **BREAKING:** source methods return a `Dimensions` object instead of an
  `['width' => int, 'height' => int]` array (array and JSON access preserved).
- **BREAKING:** SSRF protection is enabled by default; URLs resolving to
  private/reserved hosts are rejected. Set `url.allow_private_hosts` to `true`
  to opt out.
- **BREAKING:** a non-image fetched over a URL now throws `InvalidImageException`
  instead of `UrlAccessException`.
- **BREAKING:** `cache_ttl` of `0` (or less) now means "do not cache"; `null`
  means "cache forever". Cache keys are namespaced under `image_dimensions:v2:`,
  invalidating all v1 entries.
- Remote fetching now streams from a single request and continues reading the
  same connection when the header is insufficient — no second HTTP request and
  no full in-memory buffering.
- SVG parsing was extracted into `Support\SvgDimensionsExtractor` and rewritten.

### Fixed

- **SVG `viewBox` dimensions were miscalculated** by subtracting the `min-x` /
  `min-y` origin from width/height, silently corrupting results for any viewBox
  with a non-zero origin.
- SVG parsing could leak a raw `TypeError` past the exception contract; it now
  throws `InvalidImageException`.
- SVGs fetched over a URL (written to an extension-less temp file) are now
  detected via a content sniff and the original name hint, instead of failing.
- Storage/Flysystem exceptions no longer leak out of `fromStorage()`; they are
  wrapped in `StorageAccessException`, and an unavailable `lastModified()`
  degrades to an un-timestamped cache key instead of throwing.
- `fromLocal()` on a directory now throws `FileNotFoundException` instead of
  reaching `getimagesize()`.
- Namespaced SVG roots (`<svg:svg>`) and documents with an internal DTD subset
  are parsed correctly; CSS absolute units (`px/pt/pc/cm/mm/in`) are supported.
- Temporary files are always cleaned up (RAII), including on error paths.
- Out-of-range SVG lengths (`1e400`, `1e30`, oversized integers) no longer
  overflow the float→int cast into garbage dimensions, and no longer escape the
  exception contract as a raw `InvalidArgumentException` — which previously
  slipped past `tryFrom*()` too.
- The `svg.max_file_size` guard now aborts immediately instead of being caught
  by the "keep reading" retry handler, which used to download all the way to
  `max_download_bytes` before failing with the wrong message.
- `fromContents()` and `fromStream()` now honour `max_download_bytes`; they
  previously buffered unbounded input to the temp partition.
- `fromUploadedFile()` no longer caches by path+mtime. PHP recycles upload temp
  names and `filemtime` is second-granular, so two uploads could collide and
  return the first one's dimensions.
- A duplicated or whitespace-padded `Content-Length` header (`"N, N"`) is parsed
  correctly instead of silently skipping the pre-download size check.
- Error messages no longer disclose the internal temp-file path when a source
  has no usable name (e.g. a URL with no path, `fromContents()`).
- Storage failures chain the driver's original exception as `previous`.
- A short or zero-length `fwrite` (full disk, exceeded quota) raises
  `TemporaryFileException` instead of silently truncating the temp file.

### Removed

- **BREAKING:** dropped Laravel 10 support (PHP floor raised to 8.2) and
  Laravel 11 support. Every 11.x release, including the final v11.55.0, carries
  unpatched security advisories, so a current Composer will not install it under
  the default advisory policy. Supported range is now Laravel 12.x–13.x; the PHP
  floor stays at 8.2 (Laravel 12's own minimum).
- Regex-based SVG "sanitisation" — it offered no real protection (this package
  does not render SVGs) and broke valid documents.

### Security

- `fromUrl()` blocks private, loopback, link-local, and reserved IPv4/IPv6
  ranges by default (including the `169.254.169.254` metadata endpoint, CGNAT,
  and IETF test networks) and re-validates every redirect hop. DNS-rebinding
  remains a documented residual risk; use `url.allowed_hosts` for strict pinning.
- The SSRF guard also rejects IPv6 addresses that tunnel a private or loopback
  IPv4 destination — 6to4 (`2002::/16`) and NAT64 (`64:ff9b::/96`) — plus the
  documentation (`2001:db8::/32`), discard (`100::/64`), and Teredo
  (`2001::/32`) prefixes. Addresses tunnelling a *public* IPv4 stay reachable.
- Remote downloads are bounded by `max_download_bytes`, mitigating memory/
  bandwidth exhaustion from oversized or non-image responses.
