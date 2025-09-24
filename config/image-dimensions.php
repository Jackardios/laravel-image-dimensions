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
    | Default: 131072 (128KB) - sufficient for most image headers
    | Min: 8192 (8KB), Max: 1048576 (1MB)
    |
    */
    'remote_read_bytes' => env('IMAGE_DIMENSIONS_REMOTE_READ_BYTES', 131072),

    /*
    |--------------------------------------------------------------------------
    | Temporary Directory
    |--------------------------------------------------------------------------
    |
    | The directory where temporary files will be created when processing
    | remote images. Defaults to the system's temp directory.
    | The directory must exist and be writable.
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
    | HTTP Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for HTTP requests when fetching images from URLs
    |
    */
    'http' => [
        'timeout' => env('IMAGE_DIMENSIONS_HTTP_TIMEOUT', 60),
        'connect_timeout' => env('IMAGE_DIMENSIONS_HTTP_CONNECT_TIMEOUT', 10),
        'verify_ssl' => env('IMAGE_DIMENSIONS_HTTP_VERIFY_SSL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | SVG Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration specific to SVG file handling
    |
    */
    'svg' => [
        // Maximum allowed size for SVG files (in bytes)
        'max_file_size' => env('IMAGE_DIMENSIONS_SVG_MAX_SIZE', 10485760), // 10MB
    ],
];