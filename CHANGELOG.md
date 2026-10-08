# Changelog

All notable changes to `jackardios/laravel-image-dimensions` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-10-08

### Security

- A compressed Flash file (`CWS`) of about 100 KB made `getimagesize()` allocate
  100 MB and exhaust the memory limit: a fatal error that no `catch` handles,
  from `fromLocal()`, `fromStorage()` and `fromUrl()` alike. Flash files, `CWS`
  and `FWS`, are now rejected with `InvalidImageException` before
  `getimagesize()` is called. They are not images; `FWS` used to be measured
  (as 550x400, say). Dimensions of Flash files that are already in the cache
  are still returned until they expire (`cache_ttl`).
- A file larger than 8 MB that `getimagesize()` does not recognise by a
  signature is rejected with `InvalidImageException` instead of being handed
  to it. `getimagesize()` reads such a file as XBM, line by line, so a large
  file without a line break was read into memory whole, twice its size being
  allocated: the same fatal error, from `fromLocal()` and, for a file that
  its first `remote_read_bytes` did not measure and that was therefore
  downloaded whole, from `fromStorage()` and `fromUrl()`. A file that starts
  with an `ftyp` box of a format PHP does not measure (MP4, HEIC before
  PHP 8.5) counts as unrecognised.

  Not changed: a file of up to 8 MB without a signature is read as before,
  WBMP and XBM images included. That still allocates up to 16 MB for a file
  without a line break, and any text with two `#define` lines is still an
  XBM "image" of the size they name. 2.x rejects both formats.

### Changed

- A width or height above 2147483647 throws `InvalidImageException`, as in
  2.x. A PNG header can name 4294967295x4294967295, and `getimagesize()`
  reported the same for XBM text with a larger or a negative number. No real
  image has such a side.

### Fixed

- The default `temp_dir` is resolved when the service is created instead of in
  the config file, where `config:cache` kept the temp directory of the machine
  that built the cache. An empty `IMAGE_DIMENSIONS_TEMP_DIR` means the system
  temp directory too.

  A config file published before 1.1.1 does not get this fix: it still has
  `'temp_dir' => env('IMAGE_DIMENSIONS_TEMP_DIR', sys_get_temp_dir())`.
  Change that line to `'temp_dir' => env('IMAGE_DIMENSIONS_TEMP_DIR'),` by
  hand. Code that reads `config('image-dimensions.temp_dir')` itself gets
  `null` when the variable is not set.

### Documentation

- README: `fromUrl()` has no SSRF protection and no download limit in 1.x;
  the time a large file can take to measure.

## [1.1.0] - 2026-09-30

No breaking changes: the public API and return values are unchanged, apart
from the SVG fixes below.

### Added

- Laravel 13 support; PHP 8.4 and 8.5 support.

### Fixed

- Every SVG lookup raised an `E_DEPRECATED` from `libxml_disable_entity_loader()`
  (deprecated since PHP 8.0). The call is gone and entities are never
  substituted.
- SVG lengths with absolute units (`2in`, `2.54cm`, `25.4mm`, `72pt`, `6pc`) were
  rejected; they are now converted to pixels at 96 DPI.
- SVG `viewBox` sizes were offset by `min-x`/`min-y` (`10 20 400 300` gave
  390x280 instead of 400x300).
- An SVG with only a `width` (or `height`) and a `viewBox` took the other side
  from the `viewBox` unscaled; it now follows the `viewBox` aspect ratio.
- `<svg:svg>` (namespace-prefixed) roots and documents with an internal DTD
  subset, as exported by Adobe Illustrator, failed to parse.
- An SVG that libmagic reports as `text/xml` (for example behind a long
  comment) failed on PHP ≤ 8.4 and, on PHP 8.5, got unit-less dimensions from
  `getimagesize()`. Content that starts with `<` is now always parsed as SVG.
- Float noise could round a dimension up by one pixel.
- Out-of-range lengths such as `1e400` are ignored instead of wrapping around.
- `guzzlehttp/guzzle` and the `illuminate/http`, `illuminate/cache` and
  `illuminate/contracts` components the package uses are now declared as
  dependencies. On Laravel 10, whose `laravel/framework` only suggests Guzzle,
  `fromUrl()` failed with a missing class when the app had no Guzzle. Guzzle 7
  and 8 are both accepted (`^7.5 || ^8.0.1`; 8.0.0 has a published advisory),
  so a Laravel 13 app that already has Guzzle 8 does not have to downgrade it.

### Security

- Entity references in an SVG's `width`, `height` or `viewBox` are rejected.
  libxml expands them on attribute access in quadratic time without any limit,
  and PHP 8.5's own `getimagesize()` SVG reader has the same problem (100 KB
  took 23 s), so markup is never passed to it.

### Changed

- Cache keys now include a `v1.1` segment, so dimensions cached by 1.0 (with
  the SVG bugs above) are recomputed after upgrading.

### Deprecated

- `ImageDimensionsService::sanitizeSvgContent()` and `parseSvgDimension()` are
  no longer called. They remain for subclasses and will be removed in 2.0.
  Overriding them has no effect any more; override `getSvgDimensions()` to
  change how an SVG is measured.

## [1.0.0] - 2025-09-26

Initial release.
