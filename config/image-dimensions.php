<?php

return [
    /*
     * The maximum number of bytes to read from a remote image when determining its dimensions.
     * This helps to quickly determine dimensions without downloading the entire file.
     */
    'remote_read_bytes' => 65536,

    /*
     * Path to a temporary directory where remote image data will be stored.
     * Ensure this directory is writable by the web server.
     */
    'temp_dir' => sys_get_temp_dir(),
];


