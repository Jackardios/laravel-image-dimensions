# Changelog

All notable changes to `jackardios/laravel-image-dimensions` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - Unreleased

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
  `fromUrl()` failed with a missing class when the app had no Guzzle.

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

## [1.0.0] - 2025-09-26

Initial release.
