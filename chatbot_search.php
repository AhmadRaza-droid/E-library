<?php
define('SKIP_CSRF', true); // Public, read-only catalogue search.
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok' => false, 'books' => []]);
    exit;
}

// Keep the public assistant lightweight and discourage automated scraping.
$clientKey = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
if (rate_limit_add('chat_catalogue:' . $clientKey, 60) > 30) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'books' => [], 'message' => 'Please wait a moment and try again.']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q, 'UTF-8') > 180) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'books' => []]);
    exit;
}

$parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
$stopWords = ['a','an','the','and','or','is','are','am','me','my','you','your','what','which','who','can','please','tell','about','for','with','find','show','some','give','book','books','author','writer','mujhe','mujhay','koi','kisi','batao','batain','bata','hai','hain','kya','wala','wali','chahiye','par','aur','kay','mein','mere','mera','kiye','ki','ka','ke','ko','se','say','karo','chahye','kitab','kitaben','kitaab','suggest','recommend','recommendation','dikhao','do','search','looking','want','need','available','library','related'];
$terms = [];
foreach ($parts ?: [] as $part) {
    if (mb_strlen($part, 'UTF-8') > 1 && !in_array($part, $stopWords, true) && !in_array($part, $terms, true)) {
        $terms[] = $part;
    }
    if (count($terms) >= 6) break;
}
if (!$terms) {
    $terms = [$q];
}

$where = [];
$params = [];
foreach ($terms as $term) {
    $like = '%' . like_escape($term) . '%';
    $where[] = '(title LIKE ? OR author LIKE ? OR category LIKE ? OR description LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
$phraseLike = '%' . like_escape($q) . '%';
$sql = "SELECT id, title, author, category
        FROM books
        WHERE " . implode(' OR ', $where) . "
        ORDER BY CASE
            WHEN title LIKE ? THEN 0
            WHEN author LIKE ? THEN 1
            WHEN category LIKE ? THEN 2
            ELSE 3
        END, title ASC
        LIMIT 5";
array_push($params, $phraseLike, $phraseLike, $phraseLike);
$types = str_repeat('s', count($params));
$rows = db_all($conn, $sql, $types, $params);

echo json_encode(
    ['ok' => true, 'books' => $rows],
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
