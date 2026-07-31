<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';
$user = admin_require_login();
$pdo = admin_db();
$blogConfig = admin_blog_config();

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $slug = (string) ($_POST['slug'] ?? '');

    if ($slug === '') {
        $error = 'Slug mancante nella richiesta.';
    } elseif ($action === 'delete') {
        gta_blog_delete($pdo, $slug);
        // gta_blog_delete forza published=0 e valorizza deleted_at: passiamo
        // la riga (ora esclusa dai fetch normali) a gta_blog_regenerate solo
        // per rimuovere l'eventuale pagina statica — stesso pattern di blog.php.
        $deletedRow = gta_blog_fetch_one_including_deleted($pdo, $slug);
        gta_blog_regenerate($pdo, $blogConfig, $deletedRow);
        $message = 'Articolo eliminato (recuperabile solo manualmente sul database — nessuna azione di ripristino esposta qui di proposito).';
    } elseif ($action === 'publish' || $action === 'unpublish') {
        $article = gta_blog_fetch_one($pdo, $slug);
        if ($article === null) {
            $error = 'Articolo non trovato.';
        } else {
            $article['published'] = $action === 'publish';
            $updated = gta_blog_upsert($pdo, $article);
            gta_blog_regenerate($pdo, $blogConfig, $updated);
            $message = $action === 'publish' ? 'Articolo pubblicato — ora visibile pubblicamente.' : 'Articolo rimesso in bozza.';
        }
    } else {
        $error = 'Azione non riconosciuta.';
    }
}

$articles = gta_blog_fetch_all($pdo);
$csrf = admin_csrf_token();
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Articoli — Admin Blog GTAVIANI</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <?= admin_topbar_html('articles', $user['username']) ?>
  <div class="admin-container">
    <?php if ($message !== null): ?><p class="admin-message"><?= admin_html_escape($message) ?></p><?php endif; ?>
    <?php if ($error !== null): ?><p class="admin-error"><?= admin_html_escape($error) ?></p><?php endif; ?>

    <div class="admin-card">
      <a href="article-edit.php" class="admin-btn">+ Nuovo articolo</a>
    </div>

    <div class="admin-card">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Titolo</th>
            <th>Stato</th>
            <th>Aggiornato</th>
            <th>Azioni</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($articles)): ?>
            <tr><td colspan="4">Nessun articolo ancora. Crea il primo con "+ Nuovo articolo".</td></tr>
          <?php endif; ?>
          <?php foreach ($articles as $article): ?>
            <tr>
              <td>
                <a href="article-edit.php?slug=<?= urlencode($article['slug']) ?>"><?= admin_html_escape($article['title']) ?></a>
              </td>
              <td>
                <?php if (!empty($article['published'])): ?>
                  <span class="admin-badge admin-badge-published">Pubblicato</span>
                <?php else: ?>
                  <span class="admin-badge admin-badge-draft">Bozza</span>
                <?php endif; ?>
              </td>
              <td><?= admin_html_escape(substr((string) $article['updated_at'], 0, 10)) ?></td>
              <td>
                <a href="article-edit.php?slug=<?= urlencode($article['slug']) ?>" class="admin-btn admin-btn-small admin-btn-secondary">Modifica</a>
                <form method="post" class="admin-inline">
                  <input type="hidden" name="csrf_token" value="<?= admin_html_escape($csrf) ?>">
                  <input type="hidden" name="slug" value="<?= admin_html_escape($article['slug']) ?>">
                  <?php if (!empty($article['published'])): ?>
                    <input type="hidden" name="action" value="unpublish">
                    <button type="submit" class="admin-btn admin-btn-small admin-btn-secondary">Metti in bozza</button>
                  <?php else: ?>
                    <input type="hidden" name="action" value="publish">
                    <button type="submit" class="admin-btn admin-btn-small">Pubblica</button>
                  <?php endif; ?>
                </form>
                <form method="post" class="admin-inline" onsubmit="return confirm('Eliminare questo articolo? Resterà recuperabile solo manualmente sul DB.');">
                  <input type="hidden" name="csrf_token" value="<?= admin_html_escape($csrf) ?>">
                  <input type="hidden" name="slug" value="<?= admin_html_escape($article['slug']) ?>">
                  <input type="hidden" name="action" value="delete">
                  <button type="submit" class="admin-btn admin-btn-small admin-btn-danger">Elimina</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
