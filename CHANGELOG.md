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

### Removed

- **BREAKING:** dropped Laravel 10 support (PHP floor raised to 8.2).
- Regex-based SVG "sanitisation" — it offered no real protection (this package
  does not render SVGs) and broke valid documents.

### Security

- `fromUrl()` blocks private, loopback, link-local, and reserved IPv4/IPv6
  ranges by default (including the `169.254.169.254` metadata endpoint, CGNAT,
  and IETF test networks) and re-validates every redirect hop. DNS-rebinding
  remains a documented residual risk; use `url.allowed_hosts` for strict pinning.
- Remote downloads are bounded by `max_download_bytes`, mitigating memory/
  bandwidth exhaustion from oversized or non-image responses.
