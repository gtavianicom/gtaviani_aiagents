<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';
$user = admin_require_login();
$pdo = admin_db();

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || strlen($password) < 8) {
            $error = 'Username obbligatorio, password di almeno 8 caratteri.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash, active, created_at) VALUES (:u, :p, 1, :c)');
                $stmt->execute([
                    ':u' => $username,
                    ':p' => password_hash($password, PASSWORD_DEFAULT),
                    ':c' => gmdate('c'),
                ]);
                $message = 'Utente creato.';
            } catch (\PDOException $e) {
                $error = 'Username già esistente.';
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $activeCount = (int) $pdo->query('SELECT COUNT(*) FROM admin_users WHERE active = 1')->fetchColumn();
        $target = $pdo->prepare('SELECT active FROM admin_users WHERE id = :id');
        $target->execute([':id' => $id]);
        $targetActive = (int) $target->fetchColumn();

        if ($targetActive === 1 && $activeCount <= 1) {
            $error = 'Non puoi disattivare l\'ultimo utente attivo — resteresti fuori dal pannello.';
        } else {
            $pdo->prepare('UPDATE admin_users SET active = 1 - active WHERE id = :id')->execute([':id' => $id]);
            $message = 'Stato utente aggiornato.';
        }
    } else {
        $error = 'Azione non riconosciuta.';
    }
}

$users = $pdo->query('SELECT id, username, active, created_at FROM admin_users ORDER BY created_at ASC')->fetchAll(PDO::FETCH_ASSOC);
$csrf = admin_csrf_token();
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Utenti — Admin Blog GTAVIANI</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="stylesheet" href="style.css?v=<?= admin_asset_version() ?>">
</head>
<body>
  <?= admin_topbar_html('users', $user['username']) ?>
  <div class="admin-container">
    <?php if ($message !== null): ?><p class="admin-message"><?= admin_html_escape($message) ?></p><?php endif; ?>
    <?php if ($error !== null): ?><p class="admin-error"><?= admin_html_escape($error) ?></p><?php endif; ?>

    <div class="admin-card">
      <table class="admin-table">
        <thead>
          <tr><th>Username</th><th>Stato</th><th>Creato</th><th>Azioni</th></tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= admin_html_escape($u['username']) ?></td>
              <td>
                <?php if ((int) $u['active'] === 1): ?>
                  <span class="admin-badge admin-badge-active">Attivo</span>
                <?php else: ?>
                  <span class="admin-badge admin-badge-inactive">Disattivato</span>
                <?php endif; ?>
              </td>
              <td><?= admin_html_escape(substr((string) $u['created_at'], 0, 10)) ?></td>
              <td>
                <form method="post" class="admin-inline">
                  <input type="hidden" name="csrf_token" value="<?= admin_html_escape($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <input type="hidden" name="action" value="toggle">
                  <button type="submit" class="admin-btn admin-btn-small admin-btn-secondary">
                    <?= (int) $u['active'] === 1 ? 'Disattiva' : 'Riattiva' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="admin-card admin-form">
      <h3>Aggiungi utente</h3>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_html_escape($csrf) ?>">
        <input type="hidden" name="action" value="add">
        <label>Username
          <input type="text" name="username" required>
        </label>
        <label>Password
          <input type="password" name="password" required minlength="8">
          <span class="admin-hint">Minimo 8 caratteri.</span>
        </label>
        <button type="submit" class="admin-btn">Crea utente</button>
      </form>
    </div>
  </div>
</body>
</html>
