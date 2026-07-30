<?php

declare(strict_types=1);

// GTA-BLOG-001 — endpoint pubblicazione blog per aiagents.gtaviani.com.
// Adattato da awc_website/api/blog.php, stesso contratto payload — vedi
// briefs/gtaviani_awaoffer/aiagents-blog-api-contract.md nel repo pm-websites.

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/blog-template.php';

function gta_blog_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gta_blog_respond(405, ['error' => 'Metodo non consentito, usa POST']);
}

$configPath = __DIR__ . '/blog-config.php';
if (!file_exists($configPath)) {
    gta_blog_respond(500, ['error' => 'Configurazione mancante: copiare blog-config.sample.php in blog-config.php sul server']);
}
$config = require $configPath;

// Alcuni setup Apache/PHP-FPM non popolano HTTP_AUTHORIZATION di default:
// se l'endpoint risponde sempre 401 nonostante il token corretto, serve la
// regola di riscrittura già aggiunta in .htaccess (root del sito) — vedi
// commento lì.
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authHeader === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
}
$providedToken = '';
if (preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
    $providedToken = $m[1];
}
if ($providedToken === '' || !hash_equals((string) $config['auth_token'], $providedToken)) {
    gta_blog_respond(401, ['error' => 'Token mancante o non valido']);
}

$rawBody = file_get_contents('php://input');
$data = json_decode((string) $rawBody, true);
if (!is_array($data)) {
    gta_blog_respond(400, ['error' => 'JSON non valido']);
}

$action = $data['action'] ?? 'publish';
if (!in_array($action, ['publish', 'delete'], true)) {
    gta_blog_respond(400, ['error' => 'action deve essere "publish" o "delete"']);
}

$pdo = gta_blog_db($config);

if ($action === 'delete') {
    $slug = is_string($data['slug'] ?? null) ? gta_blog_slugify($data['slug']) : '';
    if ($slug === '') {
        gta_blog_respond(400, ['error' => 'slug obbligatorio per action delete']);
    }

    $deleted = gta_blog_delete($pdo, $slug);
    if (!$deleted) {
        gta_blog_respond(404, ['error' => 'Articolo non trovato: ' . $slug]);
    }

    $articlePath = $config['site_root'] . '/blog/' . $slug . '.html';
    if (file_exists($articlePath)) {
        unlink($articlePath);
    }

    gta_blog_regenerate($pdo, $config);
    gta_blog_respond(200, ['ok' => true, 'slug' => $slug, 'deleted' => true]);
}

// action === publish
foreach (['title', 'intro', 'body_html'] as $field) {
    if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '') {
        gta_blog_respond(400, ['error' => 'Campo obbligatorio mancante o non valido: ' . $field]);
    }
}

$slugSource = (is_string($data['slug'] ?? null) && trim($data['slug']) !== '') ? $data['slug'] : $data['title'];
$slug = gta_blog_slugify($slugSource);
if ($slug === '') {
    gta_blog_respond(400, ['error' => 'Impossibile derivare uno slug valido da titolo/slug forniti']);
}

$keywordsRaw = $data['keywords'] ?? '';
if (is_array($keywordsRaw)) {
    $keywords = implode(', ', array_map('strval', $keywordsRaw));
} else {
    $keywords = is_string($keywordsRaw) ? $keywordsRaw : '';
}

$primaryImage = is_string($data['primary_image'] ?? null) ? $data['primary_image'] : '';
$metaDescription = (is_string($data['meta_description'] ?? null) && trim($data['meta_description']) !== '')
    ? $data['meta_description']
    : $data['intro'];
$ogImage = (is_string($data['og_image'] ?? null) && trim($data['og_image']) !== '') ? $data['og_image'] : $primaryImage;
$published = !isset($data['published']) || $data['published'] === true || $data['published'] === 1 || $data['published'] === '1';

$articleData = [
    'slug' => $slug,
    'title' => $data['title'],
    'intro' => $data['intro'],
    'body_html' => $data['body_html'],
    'primary_image' => $primaryImage,
    'meta_description' => $metaDescription,
    'og_image' => $ogImage,
    'keywords' => $keywords,
    'published' => $published,
];

$saved = gta_blog_upsert($pdo, $articleData);

gta_blog_regenerate($pdo, $config, $saved);

$url = rtrim((string) $config['site_url'], '/') . '/blog/' . $slug . '.html';
gta_blog_respond(200, [
    'ok' => true,
    'slug' => $slug,
    'url' => $url,
    'published' => (bool) $saved['published'],
]);
