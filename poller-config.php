<?php

return [
    // Base URL of the deployed logger.php on the low-cost server.
    'logger_url' => 'https://your-low-cost-host.example.com/logger.php',

    // How often to poll the Logger for new entries, per source.
    'poll_interval_seconds' => 5,

    // Where the poller keeps its own cursor-tracking SQLite file.
    'tracking_db_path' => __DIR__ . '/poller-tracking.sqlite',

    // Maps each secret `source` name to the local dev URL it gets replayed to.
    'sources' => [
        // 'stripe-9f3ka2' => 'http://localhost:8000/webhooks/stripe',
        // 'github-9f3ka2' => 'http://localhost:8000/webhooks/github',
    ],
];
