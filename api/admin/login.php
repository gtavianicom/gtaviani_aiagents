<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';
admin_session_start();

if (admin_current_user() !== null) {
    header('Location: articles.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (admin_attempt_login($username, $password)) {
        header('Location: articles.php');
        exit;
    }
    $error = 'Credenziali non valide, o utente disattivato.';
}

$csrf = admin_csrf_token();
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — Admin Blog GTAVIANI</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="stylesheet" href="style.css">
</head>
<body class="admin-login-body">
  <form method="post" class="admin-login-form">
    <h1>Admin Blog GTAVIANI</h1>
    <?php if ($error !== null): ?>
      <p class="admin-error"><?= admin_html_escape($error) ?></p>
    <?php endif; ?>
    <input type="hidden" name="csrf_token" value="<?= admin_html_escape($csrf) ?>">
    <label>Username
      <input type="text" name="username" required autofocus>
    </label>
    <label>Password
      <input type="password" name="password" required>
    </label>
    <button type="submit" class="admin-btn">Accedi</button>
  </form>
</body>
</html>
