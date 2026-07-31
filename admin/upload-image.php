<?php

declare(strict_types=1);

// Endpoint AJAX (JSON) chiamato da article-edit.php via fetch(). A differenza
// delle pagine normali, in caso di sessione scaduta risponde 401 JSON invece
// di reindirizzare al login — un redirect qui romperebbe la fetch().

require_once __DIR__ . '/admin-common.php';
header('Content-Type: application/json');

$user = admin_current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sessione scaduta — ricarica la pagina e rifai login.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']);
    exit;
}

admin_session_start();
$token = (string) ($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token di sicurezza non valido.']);
    exit;
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nessun file valido ricevuto.']);
    exit;
}

$file = $_FILES['image'];

// Limite dimensione — default 5MB, assunzione da confermare con Gabriele.
$maxBytes = 5 * 1024 * 1024;
if ($file['size'] > $maxBytes) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'File troppo grande (limite 5MB).']);
    exit;
}

// Validazione reale del contenuto (non ci si fida di estensione/mime
// dichiarato dal browser): getimagesize legge gli header effettivi del file.
$imageInfo = @getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Il file non è un\'immagine valida.']);
    exit;
}

$allowedMime = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
$mime = $imageInfo['mime'];
if (!isset($allowedMime[$mime])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Formato non supportato — solo jpg, png, webp.']);
    exit;
}

$config = admin_config();
$uploadDir = $config['upload_dir'];
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Nome file generato lato server — mai il nome originale (niente path
// traversal, niente collisioni, niente caratteri da sanificare).
$ext = $allowedMime[$mime];
$filename = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
$destination = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Impossibile salvare il file sul server.']);
    exit;
}

$publicUrl = rtrim((string) $config['upload_url_base'], '/') . '/' . $filename;
echo json_encode(['ok' => true, 'url' => $publicUrl]);
