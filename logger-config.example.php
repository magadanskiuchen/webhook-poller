<?php

return [
    // Where the SQLite database file lives on disk.
    'db_path' => __DIR__ . '/logger.sqlite',

    // Bodies larger than this are never stored (see logger.php for why:
    // Content-Length is checked before reading, so oversized bodies are
    // never even read off the wire).
    'body_cap_bytes' => 2 * 1024 * 1024, // 2 MB
];
