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

function log_preview(string $data, int $limit = 500): string
{
    if ($data === '') {
        return '(empty)';
    }

    if (strpos($data, "\0") !== false || !mb_check_encoding($data, 'UTF-8')) {
        return sprintf('(binary data, %d bytes, not shown)', strlen($data));
    }

    if (strlen($data) <= $limit) {
        return $data;
    }

    return substr($data, 0, $limit) . sprintf(' ... (truncated, %d bytes total)', strlen($data));
}

function forward_webhook(string $targetUrl, array $row): array
{
    $headers = is_array($row['headers'] ?? null) ? $row['headers'] : [];
    $body = base64_decode((string) ($row['body'] ?? ''), true);
    if ($body === false) {
        $body = '';
    }

    $headerLines = [];
    foreach ($headers as $name => $value) {
        $lower = strtolower((string) $name);
        // Transfer-Encoding/Content-Length describe the transport of the
        // original request, not the replayed one (recomputed below).
        // Accept-Encoding is dropped so the target's response comes back
        // uncompressed and readable for the debug log below; PHP's stream
        // wrapper doesn't auto-decompress gzip/br responses like curl does.
        if (in_array($lower, ['transfer-encoding', 'content-length', 'accept-encoding'], true)) {
            continue;
        }
        $headerLines[] = $name . ': ' . $value;
    }
    $headerLines[] = 'Content-Length: ' . strlen($body);

    $method = $row['method'] ?? 'POST';

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    error_clear_last();
    $result = @file_get_contents($targetUrl, false, $context);
    $connectionError = $result === false ? (error_get_last()['message'] ?? 'unknown error') : null;

    // PHP 8.5+ deprecates the magic $http_response_header variable in favor
    // of this function; fall back to the variable (in this same function
    // scope, where the preceding file_get_contents() call populates it) on
    // older versions.
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);

    $status = null;
    if (isset($responseHeaders[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $m)) {
        $status = (int) $m[1];
    }

    return [
        'success' => $result !== false && $status !== null && $status >= 200 && $status < 300,
        'method' => $method,
        'request_headers' => $headerLines,
        'request_body' => $body,
        'status' => $status,
        'response_body' => $result === false ? null : $result,
        'connection_error' => $connectionError,
    ];
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
                $result = forward_webhook($targetUrl, $row);

                if ($result['success']) {
                    fwrite(STDOUT, sprintf(
                        "[%s] forwarded #%d from %s -> %s (status %d)\n",
                        date('c'),
                        $id,
                        $source,
                        $targetUrl,
                        $result['status']
                    ));
                } else {
                    fwrite(STDOUT, sprintf(
                        "[%s] FAILED #%d from %s -> %s\n" .
                        "  request:  %s %s\n" .
                        "  headers:  %s\n" .
                        "  body:     %s\n" .
                        "  response: %s\n",
                        date('c'),
                        $id,
                        $source,
                        $targetUrl,
                        $result['method'],
                        $targetUrl,
                        implode(' | ', $result['request_headers']),
                        log_preview($result['request_body']),
                        $result['status'] !== null
                            ? sprintf('HTTP %d - %s', $result['status'], log_preview((string) $result['response_body']))
                            : sprintf('no response (%s)', $result['connection_error'])
                    ));
                }
            }

            set_last_id($trackingDb, $source, $id);
        }
    }

    sleep((int) ($config['poll_interval_seconds'] ?? 5));
}
