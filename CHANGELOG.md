# Changelog

All notable changes to `jackardios/laravel-image-dimensions` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

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
- `Contracts\ImageDimensions` interface; the concrete class, the contract and
  the `image-dimensions` alias resolve to one instance.
- SSRF protection for `fromUrl()` via a new `UrlGuard`, configurable through the
  `url.*` config block (`allow_private_hosts`, `allowed_hosts`, `max_redirects`).
- `max_download_bytes` config option capping how much of a remote source is read.
- New exceptions `FileTooLargeException` (extends `InvalidImageException`) and
  `UrlNotAllowedException` (extends `UrlAccessException`).
- HEIF/HEIC on every PHP version, read from the file's own metadata and
  cropped to its clean aperture. `getimagesize()` cannot read HEIF before
  PHP 8.5 and, from 8.5, reports the coded size (64x64 for a 33x17 photo).
- Internationalized host names (as punycode, UTS #46), underscores in host
  names, and spaces or other non-ASCII characters in a URL's path or query.
- Laravel 13 and PHP 8.5 support; Guzzle 8 alongside Guzzle 7.
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
- **BREAKING:** the service is a scoped binding, not a singleton: it is built
  afresh for each Octane request and each queued job, so configuration changed
  at run time takes effect.
- **BREAKING:** `ext-curl` is required. Downloads go through cURL, where
  `http.timeout` is a deadline for the whole request; `connect_timeout` bounds
  the connection. Guzzle's stream handler applied the timeout to each read,
  so a server sending a byte at a time kept a worker busy indefinitely.
- **BREAKING:** the format is detected from the contents alone. Markup is
  always parsed as SVG and never passed to `getimagesize()`; a PNG named `.svg`
  is read as a PNG.
- **BREAKING:** WBMP is no longer accepted. It has no signature, so almost any
  bytes starting with two zero bytes passed for one, and a download of such
  bytes stopped early with a made-up size.
- **BREAKING:** an SVG with an explicit zero `width` or `height` is an error
  instead of falling back to the `viewBox`.
- **BREAKING:** `ImageDimensionsService`'s protected methods changed; among
  them `resolveFromStream()` no longer takes a `TemporaryFile`.
- A download is stopped as soon as its first `remote_read_bytes` give the
  dimensions (a PNG with a 50 MB tail costs its first ~180 KB). The download
  cap applies to the bytes received; a `Content-Length` over it only fails the
  request once the header has proved insufficient. Bodies are requested
  without compression, so a gzip response cannot inflate past the cap.
- `fromContents()`, `fromStream()` and non-local storage files are analysed
  from memory; only a raster image whose header was not enough is read on into
  a temporary file. An SVG is read no further than its own cap.
- A URL is normalized once, and the guard, the cache key and the request all
  use that form: spellings of one URL share a cache entry, and the guard judges
  the host that is actually requested.
- Settings are read as environment variables spell them: an empty or
  unrecognised value gives the default, booleans accept `yes`/`no` and
  `on`/`off`, timeouts keep their fractions, and `url.allowed_hosts` may be a
  comma-separated string. `temp_dir` defaults to `null`, resolved at run time,
  so `config:cache` no longer bakes in the build machine's temp directory.
- A local file is cached by its path, size, modification and change times and
  inode, not just path and modification time. Storage keys JSON-encode the disk
  and the path, so `a:b` + `c` and `a` + `b:c` no longer share an entry. A
  non-local disk costs one remote call per cache hit instead of two.
- A cache entry that is not a pair of positive integers is a cache miss. A
  failing cache store is thrown as it is, by `tryFrom*()` as well.
- `fromStream()` reads a seekable stream from its start and restores its
  position afterwards, also when measuring fails.
- SVG parsing was extracted into `Support\SvgDimensionsExtractor` and rewritten.
- Dependencies: `guzzlehttp/guzzle` `^7.15.2 || ^8.0.1`, `guzzlehttp/psr7`,
  `symfony/polyfill-intl-idn` and `symfony/polyfill-intl-normalizer` are
  declared; `ext-fileinfo` is no longer required; `ext-intl` is suggested.

### Fixed

- **SVG `viewBox` dimensions were miscalculated** by subtracting the `min-x` /
  `min-y` origin from width/height, silently corrupting results for any viewBox
  with a non-zero origin.
- An SVG with only one side and a `viewBox` takes the other side from the
  `viewBox` aspect ratio (`width="200"` with `viewBox="0 0 100 50"` is 200x100).
- Float noise no longer rounds a dimension up by one pixel.
- Namespaced SVG roots (`<svg:svg>`) and documents with an internal DTD subset
  are parsed correctly; CSS absolute units (`px/pt/pc/cm/mm/in`) are supported.
- Out-of-range SVG lengths (`1e400`, `1e30`, oversized integers) no longer
  overflow the float→int cast into garbage dimensions.
- `from*()` throws only the package's exceptions for a bad input or a failing
  source, so `tryFrom*()` returns `null` for them. Raw `TypeError`,
  `ValueError` (a path with a NUL byte), `InvalidArgumentException` and
  Flysystem exceptions (a path leaving the disk's root, a failing existence
  check) used to escape both.
- The `svg.max_file_size` guard now aborts immediately instead of being caught
  by the "keep reading" retry handler, which used to download all the way to
  `max_download_bytes` before failing with the wrong message.
- `fromContents()` and `fromStream()` now honour `max_download_bytes`; they
  previously buffered unbounded input to the temp partition.
- `fromUploadedFile()` no longer caches by path+mtime. PHP recycles upload temp
  names and `filemtime` is second-granular, so two uploads could collide and
  return the first one's dimensions.
- `fromLocal()` on a directory now throws `FileNotFoundException` instead of
  reaching `getimagesize()`.
- Storage failures are wrapped in `StorageAccessException` with the driver's
  exception as `previous`; a driver that cannot report `lastModified()` gives
  an un-timestamped cache key instead of an error.
- The URL scheme is matched case-insensitively (`HTTPS://…` was rejected).
- Temporary files are created in `temp_dir`, exclusively, with the full
  `imgdim_` prefix and owner-only permissions. `tempnam()` silently fell back
  to the system temp directory, and on Windows `is_writable()` rejected
  writable folders with the read-only attribute. A temporary file is removed
  when the lookup ends, or at shutdown after a fatal error.
- A short or zero-length `fwrite` (full disk, exceeded quota) raises
  `TemporaryFileException` instead of silently truncating the temp file.
- A response registered once with `Http::fake()` can be served more than once,
  and `Http::preventStrayRequests()` failures are no longer reported as
  `UrlAccessException`.
- With Guzzle 8, which wraps exceptions thrown from transfer callbacks, the
  early stop and the size caps no longer surface as "Could not open URL".

### Removed

- **BREAKING:** dropped Laravel 10 support (PHP floor raised to 8.2) and Laravel
  11 support. Every 11.x release, up to v11.56.1 (the latest at the time of
  writing), is affected by CVE-2026-48019, fixed only in 12.60.0 and 13.10.0, so
  a current Composer will not install it under the default advisory policy.
  Supported range is now Laravel 12.x–13.x; the PHP floor stays at 8.2 (Laravel
  12's own minimum).
- Regex-based SVG "sanitisation" — it offered no real protection (this package
  does not render SVGs) and broke valid documents. `sanitizeSvgContent()` and
  `parseSvgDimension()`, deprecated in 1.1, are gone.

### Security

- `fromUrl()` blocks private, loopback, link-local, and reserved IPv4/IPv6
  ranges by default (including the `169.254.169.254` metadata endpoint, CGNAT,
  IETF test networks and all of `::/8`) and re-validates every redirect hop.
  Addresses are compared against explicit lists, so the verdict no longer
  depends on the PHP version. IPv4-mapped, 6to4 (`2002::/16`) and NAT64
  (`64:ff9b::/96`) addresses are judged by the IPv4 address they carry.
- Host names are resolved afresh for each fetch, and only when fetching: a
  cache hit makes no DNS query. DNS rebinding remains a documented residual
  risk; use `url.allowed_hosts` for strict pinning.
- Exception messages no longer quote credentials, query values (such as a
  presigned URL's signature) or the address a blocked host resolves to, nor
  the temporary path of an upload or a download.
- Entity references in an SVG's `width`, `height` or `viewBox` are rejected:
  libxml expands them without limit and in quadratic time (~50 KB pinned a CPU
  for 20 s).
- Remote downloads are bounded by `max_download_bytes`, mitigating memory/
  bandwidth exhaustion from oversized or non-image responses.
- Guzzle releases with published advisories (below 7.15.2, and 8.0.0) are
  excluded.

## [1.1.0] - Unreleased

Maintained on the `1.x` branch; see its changelog.

## [1.0.0] - 2025-09-26

Initial release.
