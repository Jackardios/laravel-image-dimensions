<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Remote Read Bytes
    |--------------------------------------------------------------------------
    |
    | The number of bytes to read from remote sources (URLs, cloud storage)
    | before attempting to determine image dimensions. This optimization
    | helps avoid downloading entire large images when possible.
    |
    */
    'remote_read_bytes' => env('IMAGE_DIMENSIONS_REMOTE_READ_BYTES', 65536),

    /*
    |--------------------------------------------------------------------------
    | Temporary Directory
    |--------------------------------------------------------------------------
    |
    | The directory where temporary files will be created when processing
    | remote images. Defaults to the system's temp directory.
    |
    */
    'temp_dir' => env('IMAGE_DIMENSIONS_TEMP_DIR', sys_get_temp_dir()),

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Enable caching of image dimensions to improve performance for
    | frequently accessed images.
    |
    */
    'enable_cache' => env('IMAGE_DIMENSIONS_ENABLE_CACHE', true),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (Time To Live)
    |--------------------------------------------------------------------------
    |
    | The number of seconds to cache image dimensions. Set to 0 to cache
    | indefinitely (not recommended for remote images).
    |
    */
    'cache_ttl' => env('IMAGE_DIMENSIONS_CACHE_TTL', 3600), // 1 hour

    /*
    |--------------------------------------------------------------------------
    | Supported Formats
    |--------------------------------------------------------------------------
    |
    | List of supported image formats. Leave empty to support all formats
    | that PHP can handle. Common formats include:
    | jpg, jpeg, png, gif, bmp, webp, svg, ico, avif
    |
    */
    'supported_formats' => [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'bmp',
        'webp',
        'svg',
        'ico',
        'avif'
    ],
];