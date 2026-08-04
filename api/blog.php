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
if (!in_array($action, ['publish', 'delete', 'list', 'get', 'restore'], true)) {
    gta_blog_respond(400, ['error' => 'action deve essere "publish", "delete", "list", "get" o "restore"']);
}

$pdo = gta_blog_db($config);

// GTA-BLOG-014: nessun cron sull'hosting — ogni richiesta autenticata è
// l'occasione per materializzare eventuali articoli schedulati che hanno
// superato la data (vedi gta_blog_publish_due in blog-template.php).
gta_blog_publish_due($pdo, $config);

// GTA-BLOG-002 — action:list: tutti gli articoli (pubblicati e bozze), campi
// sintetici per un agente editor che deve vedere il colpo d'occhio prima di
// decidere cosa aprire con action:get.
if ($action === 'list') {
    $articles = gta_blog_fetch_all($pdo);
    $items = array_map(static function (array $a): array {
        return [
            'slug' => $a['slug'],
            'title' => $a['title'],
            'intro' => $a['intro'],
            'published' => (bool) $a['published'],
            'scheduled_publish_at' => $a['scheduled_publish_at'] ?? null,
            'created_at' => $a['created_at'],
            'updated_at' => $a['updated_at'],
        ];
    }, $articles);
    gta_blog_respond(200, ['ok' => true, 'articles' => $items]);
}

// GTA-BLOG-002 — action:get: record completo di un articolo (tutti i campi
// che action:publish accetta), per un agente che deve editarlo/rileggerlo.
if ($action === 'get') {
    $slug = is_string($data['slug'] ?? null) ? gta_blog_slugify($data['slug']) : '';
    if ($slug === '') {
        gta_blog_respond(400, ['error' => 'slug obbligatorio per action get']);
    }

    $article = gta_blog_fetch_one($pdo, $slug);
    if ($article === null) {
        gta_blog_respond(404, ['error' => 'Articolo non trovato: ' . $slug]);
    }

    gta_blog_respond(200, [
        'ok' => true,
        'article' => [
            'slug' => $article['slug'],
            'title' => $article['title'],
            'intro' => $article['intro'],
            'body_html' => $article['body_html'],
            'primary_image' => $article['primary_image'],
            'meta_description' => $article['meta_description'],
            'og_image' => $article['og_image'],
            'keywords' => $article['keywords'],
            'published' => (bool) $article['published'],
            'scheduled_publish_at' => $article['scheduled_publish_at'] ?? null,
            'created_at' => $article['created_at'],
            'updated_at' => $article['updated_at'],
        ],
    ]);
}

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

// GTA-BLOG-005 (punto 1) — action:restore: SOLO via blog.php diretto (token
// umano), MAI esposta come tool MCP. Rimette deleted_at a NULL ma torna
// sempre come bozza (published:0, scelta prudente — vedi commento su
// gta_blog_restore in blog-template.php), non ripubblica automaticamente.
if ($action === 'restore') {
    $slug = is_string($data['slug'] ?? null) ? gta_blog_slugify($data['slug']) : '';
    if ($slug === '') {
        gta_blog_respond(400, ['error' => 'slug obbligatorio per action restore']);
    }

    $existing = gta_blog_fetch_one_including_deleted($pdo, $slug);
    if ($existing === null || empty($existing['deleted_at'])) {
        gta_blog_respond(404, ['error' => 'Nessun articolo cancellato con questo slug: ' . $slug]);
    }

    $restored = gta_blog_restore($pdo, $slug);
    if (!$restored) {
        gta_blog_respond(404, ['error' => 'Nessun articolo cancellato con questo slug: ' . $slug]);
    }

    gta_blog_regenerate($pdo, $config);
    gta_blog_respond(200, ['ok' => true, 'slug' => $slug, 'restored' => true, 'published' => false]);
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

// GTA-BLOG-014: opzionale, NULL/assente = comportamento invariato (nessuna
// schedulazione, live appena published=true). Qui (blog.php diretto, token
// umano) il campo è pienamente rispettato, come published — a differenza
// del tool MCP publish_article dove resta solo una proposta, vedi mcp.php.
try {
    $scheduledPublishAt = gta_blog_normalize_scheduled_publish_at(
        is_string($data['scheduled_publish_at'] ?? null) ? $data['scheduled_publish_at'] : null
    );
} catch (InvalidArgumentException $e) {
    gta_blog_respond(400, ['error' => $e->getMessage()]);
}

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
    'scheduled_publish_at' => $scheduledPublishAt,
];

$saved = gta_blog_upsert($pdo, $articleData);

gta_blog_regenerate($pdo, $config, $saved);

$url = rtrim((string) $config['site_url'], '/') . '/blog/' . $slug . '.html';
gta_blog_respond(200, [
    'ok' => true,
    'slug' => $slug,
    'url' => $url,
    'published' => (bool) $saved['published'],
    'scheduled_publish_at' => $saved['scheduled_publish_at'] ?? null,
]);
