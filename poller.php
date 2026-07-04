<?php

declare(strict_types=1);

// Plain PHP, dependency-free poller. Run with `php poller.php` and leave it
// running in a terminal for the duration of a dev session; Ctrl+C to stop.
//
// For each configured source: polls the Logger for entries after the last
// seen ID, replays each one (method + headers + raw body, verbatim) to the
// mapped local URL, and advances its cursor — fire-and-forget, no retries.

function get_last_id(PDO $db, string $source): int
{
    $stmt = $db->prepare('SELECT last_id FROM cursors WHERE source = :source');
    $stmt->execute([':source' => $source]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        $insert = $db->prepare('INSERT INTO cursors (source, last_id) VALUES (:source, 0)');
        $insert->execute([':source' => $source]);
        return 0;
    }

    return (int) $row['last_id'];
}

function set_last_id(PDO $db, string $source, int $lastId): void
{
    $stmt = $db->prepare('UPDATE cursors SET last_id = :last_id WHERE source = :source');
    $stmt->execute([':last_id' => $lastId, ':source' => $source]);
}

function fetch_new_webhooks(string $loggerUrl, string $source, int $sinceId): array
{
    $url = $loggerUrl . '?action=query&source=' . urlencode($source) . '&since_id=' . $sinceId;

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return [];
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : [];
}

function forward_webhook(string $targetUrl, array $row): bool
{
    $headers = is_array($row['headers'] ?? null) ? $row['headers'] : [];
    $body = base64_decode((string) ($row['body'] ?? ''), true);
    if ($body === false) {
        $body = '';
    }

    $headerLines = [];
    foreach ($headers as $name => $value) {
        $lower = strtolower((string) $name);
        // These describe the transport of the original request, not the
        // replayed one; recomputed below to match the (unchanged) body.
        if ($lower === 'transfer-encoding' || $lower === 'content-length') {
            continue;
        }
        $headerLines[] = $name . ': ' . $value;
    }
    $headerLines[] = 'Content-Length: ' . strlen($body);

    $context = stream_context_create([
        'http' => [
            'method' => $row['method'] ?? 'POST',
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $result = @file_get_contents($targetUrl, false, $context);
    if ($result === false) {
        return false;
    }

    $statusLine = $http_response_header[0] ?? '';
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $m)) {
        $status = (int) $m[1];
        return $status >= 200 && $status < 300;
    }

    return true;
}

$config = require __DIR__ . '/poller-config.php';

$trackingDb = new PDO('sqlite:' . $config['tracking_db_path']);
$trackingDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$trackingDb->exec(
    'CREATE TABLE IF NOT EXISTS cursors (
        source TEXT PRIMARY KEY,
        last_id INTEGER NOT NULL DEFAULT 0
    )'
);

fwrite(STDOUT, sprintf("[%s] poller started, watching %d source(s)\n", date('c'), count($config['sources'])));

while (true) {
    foreach ($config['sources'] as $source => $targetUrl) {
        $lastId = get_last_id($trackingDb, $source);
        $rows = fetch_new_webhooks($config['logger_url'], $source, $lastId);

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            if (!empty($row['truncated'])) {
                fwrite(STDOUT, sprintf("[%s] skip #%d from %s (body too big)\n", date('c'), $id, $source));
            } else {
                $ok = forward_webhook($targetUrl, $row);
                fwrite(STDOUT, sprintf(
                    "[%s] %s #%d from %s -> %s\n",
                    date('c'),
                    $ok ? 'forwarded' : 'FAILED',
                    $id,
                    $source,
                    $targetUrl
                ));
            }

            set_last_id($trackingDb, $source, $id);
        }
    }

    sleep((int) ($config['poll_interval_seconds'] ?? 5));
}
