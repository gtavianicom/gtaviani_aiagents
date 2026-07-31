<?php

declare(strict_types=1);

// GTA-ADMIN-001 — bootstrap condiviso del pannello admin blog: config,
// connessione DB (stesso file SQLite del motore blog + tabella admin_users
// aggiunta qui), sessione, autenticazione, CSRF. Ogni pagina admin fa
// require_once di questo file per primo.

// Incidente 31/7: admin-config.php mancante in produzione ha fatto
// arrivare un'eccezione non gestita fino al browser, con lo stack trace
// completo — PHP include di default gli argomenti reali delle funzioni
// (qui: username/password digitati) nei trace. Da qui in poi: mai
// mostrare errori grezzi al browser, mai loggare gli argomenti delle
// chiamate, sempre e solo un messaggio generico + log lato server.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('zend.exception_ignore_args', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    error_log('[admin] uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Si è verificato un errore interno. Riprova più tardi o contatta chi gestisce il sito.';
});

require_once __DIR__ . '/../api/blog-template.php';

function admin_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/admin-config.php';
        if (!file_exists($path)) {
            throw new RuntimeException('admin-config.php mancante — copiare da admin-config.sample.php e valorizzare.');
        }
        $config = require $path;
    }
    return $config;
}

function admin_blog_config(): array
{
    static $blogConfig = null;
    if ($blogConfig === null) {
        $path = admin_config()['blog_config_path'];
        if (!file_exists($path)) {
            throw new RuntimeException('blog-config.php mancante — necessario anche al pannello admin per leggere/scrivere gli articoli.');
        }
        $blogConfig = require $path;
    }
    return $blogConfig;
}

function admin_ensure_users_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL
    )");
}

// Se non esiste ancora nessun utente, ne crea uno dalle credenziali di seed
// in admin-config.php — non c'è (ancora) una UI per creare il primissimo
// utente dal nulla, deve esistere già una riga perché login.php funzioni.
function admin_bootstrap_first_user(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $config = admin_config();
    $seedUser = $config['seed_username'] ?? null;
    $seedPass = $config['seed_password'] ?? null;
    if (!$seedUser || !$seedPass) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash, active, created_at) VALUES (:u, :p, 1, :c)');
    $stmt->execute([
        ':u' => $seedUser,
        ':p' => password_hash($seedPass, PASSWORD_DEFAULT),
        ':c' => gmdate('c'),
    ]);
}

function admin_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = gta_blog_db(admin_blog_config());
        admin_ensure_users_table($pdo);
        admin_bootstrap_first_user($pdo);
    }
    return $pdo;
}

function admin_session_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function admin_current_user(): ?array
{
    admin_session_start();
    if (empty($_SESSION['admin_user_id'])) {
        return null;
    }
    $stmt = admin_db()->prepare('SELECT id, username, active FROM admin_users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['admin_user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !$user['active']) {
        return null;
    }
    return $user;
}

// Da chiamare in cima a ogni pagina che richiede login (redirect browser) —
// per endpoint JSON/AJAX (upload-image.php) usare admin_current_user()
// direttamente e rispondere 401 JSON invece di reindirizzare.
function admin_require_login(): array
{
    $user = admin_current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function admin_attempt_login(string $username, string $password): bool
{
    admin_session_start();
    $stmt = admin_db()->prepare('SELECT id, password_hash, active FROM admin_users WHERE username = :u');
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !$user['active'] || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['admin_user_id'] = $user['id'];
    return true;
}

function admin_logout(): void
{
    admin_session_start();
    $_SESSION = [];
    session_destroy();
}

function admin_csrf_token(): string
{
    admin_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function admin_csrf_check(): void
{
    admin_session_start();
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) $token)) {
        http_response_code(400);
        die('Token di sicurezza non valido — ricarica la pagina e riprova.');
    }
}

function admin_html_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Barra di navigazione condivisa tra le pagine loggate — $active è 'articles'
// o 'users'. Pensata per crescere con una voce per modulo quando arriverà un
// secondo servizio oltre al blog (guscio estendibile, vedi GTA-ADMIN-001).
function admin_topbar_html(string $active, string $username): string
{
    $articlesClass = $active === 'articles' ? ' class="active"' : '';
    $usersClass = $active === 'users' ? ' class="active"' : '';
    return '<div class="admin-topbar">'
        . '<div><a href="articles.php"' . $articlesClass . '>Articoli</a>'
        . '<a href="users.php"' . $usersClass . '>Utenti</a></div>'
        . '<div>' . admin_html_escape($username) . ' &middot; <a href="logout.php">Esci</a></div>'
        . '</div>';
}
