<?php

declare(strict_types=1);

// Single-file, dependency-free webhook logger.
//
// Ingest:  POST (or any method) to logger.php?source=<secret-source-name>
// Query:   GET  logger.php?action=query&source=<secret-source-name>&since_id=<id>
//
// The `source` value doubles as the shared secret for both ingest and
// query — anyone who doesn't know it can neither inject fake webhooks
// under that name nor read its logs.

function collect_request_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if ($headers !== false) {
            return $headers;
        }
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = str_replace('_', '-', substr($key, 5));
            $headers[ucwords(strtolower($name), '-')] = $value;
        } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
            $name = str_replace('_', '-', $key);
            $headers[ucwords(strtolower($name), '-')] = $value;
        }
    }

    return $headers;
}

function handle_ingest(PDO $db, array $config): void
{
    $source = isset($_GET['source']) ? (string) $_GET['source'] : 'default';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $headers = collect_request_headers();

    $cap = (int) ($config['body_cap_bytes'] ?? (2 * 1024 * 1024));
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;

    $truncated = false;
    $body = '';

    if ($contentLength !== null && $contentLength > $cap) {
        // Declared size already exceeds the cap: never read the body off the wire.
        $truncated = true;
    } else {
        $body = (string) file_get_contents('php://input');
        if (strlen($body) > $cap) {
            // Content-Length was missing/understated; caught after the fact.
            $truncated = true;
        }
    }

    $stmt = $db->prepare(
        'INSERT INTO webhooks (source, method, path, headers, body, truncated, created_at)
         VALUES (:source, :method, :path, :headers, :body, :truncated, :created_at)'
    );
    $stmt->bindValue(':source', $source, PDO::PARAM_STR);
    $stmt->bindValue(':method', $method, PDO::PARAM_STR);
    $stmt->bindValue(':path', $path, PDO::PARAM_STR);
    $stmt->bindValue(':headers', json_encode($headers), PDO::PARAM_STR);
    $stmt->bindValue(':body', $truncated ? 'Body too big' : $body, PDO::PARAM_LOB);
    $stmt->bindValue(':truncated', $truncated ? 1 : 0, PDO::PARAM_INT);
    $stmt->bindValue(':created_at', time(), PDO::PARAM_INT);
    $stmt->execute();

    http_response_code(200);
    header('Content-Type: text/plain');
    echo 'OK';
}

function handle_query(PDO $db): void
{
    $source = isset($_GET['source']) ? (string) $_GET['source'] : '';
    $sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;

    header('Content-Type: application/json');

    if ($source === '') {
        http_response_code(400);
        echo json_encode(['error' => 'source is required']);
        return;
    }

    $stmt = $db->prepare(
        'SELECT id, method, path, headers, body, truncated, created_at
         FROM webhooks
         WHERE source = :source AND id > :since_id
         ORDER BY id ASC
         LIMIT 500'
    );
    $stmt->bindValue(':source', $source, PDO::PARAM_STR);
    $stmt->bindValue(':since_id', $sinceId, PDO::PARAM_INT);
    $stmt->execute();

    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            'id' => (int) $row['id'],
            'method' => $row['method'],
            'path' => $row['path'],
            'headers' => json_decode((string) $row['headers'], true) ?: [],
            'body' => $row['truncated'] ? null : base64_encode((string) $row['body']),
            'truncated' => (bool) $row['truncated'],
            'created_at' => (int) $row['created_at'],
        ];
    }

    http_response_code(200);
    echo json_encode($rows);
}

$config = require __DIR__ . '/logger-config.php';

$db = new PDO('sqlite:' . $config['db_path']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE IF NOT EXISTS webhooks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source TEXT NOT NULL,
        method TEXT NOT NULL,
        path TEXT NOT NULL,
        headers TEXT NOT NULL,
        body BLOB,
        truncated INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL
    )'
);
$db->exec('CREATE INDEX IF NOT EXISTS idx_webhooks_source_id ON webhooks (source, id)');

if (($_GET['action'] ?? '') === 'query') {
    handle_query($db);
} else {
    handle_ingest($db, $config);
}
