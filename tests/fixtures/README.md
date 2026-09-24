# Test fixtures

HEIF images encoded with libheif 1.21.1 (`heif-enc`) from blank PNGs made by
`imagecreatetruecolor($width, $height)` and `imagepng()`:

| File              | Size      | Command                                              |
|-------------------|-----------|------------------------------------------------------|
| `p33x17.heic`     | 33x17     | `heif-enc -q 50 p33x17.png -o p33x17.heic`           |
| `p4032x3024.heic` | 4032x3024 | `heif-enc -q 50 p4032x3024.png -o p4032x3024.heic`   |
| `grid1000.heic`   | 1000x700  | `heif-enc --cut-tiles 512 -q 50 p1000x700.png -o grid1000.heic` |
| `grid.heic`       | 4032x3024 | `heif-enc --cut-tiles 512 -q 50 p4032x3024.png -o grid.heic`    |

HEVC codes whole blocks, so `p33x17.heic` is a 64x64 image cropped by a clean
aperture (`clap`) box; PHP 8.5's `getimagesize()` reports 64x64. The grids are
made of 512x512 tiles. `heif-info` confirms each size.
