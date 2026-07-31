<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';
$user = admin_require_login();
$pdo = admin_db();
$blogConfig = admin_blog_config();

$requestedSlug = trim((string) ($_GET['slug'] ?? ''));
$article = $requestedSlug !== '' ? gta_blog_fetch_one($pdo, $requestedSlug) : null;
$error = null;
$values = $article ?? [
    'slug' => '', 'title' => '', 'intro' => '', 'body_html' => '',
    'primary_image' => '', 'meta_description' => '', 'og_image' => '', 'keywords' => '',
    'published' => 0,
];
$originalSlug = $requestedSlug;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check();
    $originalSlug = trim((string) ($_POST['original_slug'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $newSlug = trim((string) ($_POST['slug'] ?? '')) ?: gta_blog_slugify($title);
    $intro = trim((string) ($_POST['intro'] ?? ''));
    $bodyHtml = (string) ($_POST['body_html'] ?? '');

    $values = [
        'slug' => $newSlug,
        'title' => $title,
        'intro' => $intro,
        'body_html' => $bodyHtml,
        'primary_image' => trim((string) ($_POST['primary_image'] ?? '')),
        'meta_description' => trim((string) ($_POST['meta_description'] ?? '')),
        'og_image' => trim((string) ($_POST['og_image'] ?? '')),
        'keywords' => trim((string) ($_POST['keywords'] ?? '')),
        'published' => 0,
    ];

    if ($title === '' || $intro === '' || $bodyHtml === '') {
        $error = 'Titolo, sommario e corpo articolo sono obbligatori.';
    } elseif ($newSlug === '') {
        $error = 'Slug non derivabile — controlla il titolo.';
    } else {
        $existingOriginal = $originalSlug !== '' ? gta_blog_fetch_one($pdo, $originalSlug) : null;
        // Il salvataggio normale NON cambia lo stato pubblicato/bozza — quello
        // si fa solo dal bottone dedicato in articles.php (azione distinta,
        // richiesta esplicita del brief).
        $publishedCurrent = $existingOriginal['published'] ?? 0;

        $data = [
            'slug' => $newSlug,
            'title' => $title,
            'intro' => $intro,
            'body_html' => $bodyHtml,
            'primary_image' => $values['primary_image'] ?: null,
            'meta_description' => $values['meta_description'] ?: null,
            'og_image' => $values['og_image'] ?: null,
            'keywords' => $values['keywords'] ?: null,
            'published' => (bool) $publishedCurrent,
        ];

        $updated = gta_blog_upsert($pdo, $data);
        gta_blog_regenerate($pdo, $blogConfig, $updated);

        // Slug cambiato rispetto a un articolo esistente: la riga vecchia
        // resterebbe come un doppione separato (upsert è keyed sul NUOVO
        // slug) — la soft-deletiamo per evitare due articoli sullo stesso
        // contenuto sotto URL diversi. Segnalato in UI prima del submit.
        if ($originalSlug !== '' && $originalSlug !== $newSlug) {
            gta_blog_delete($pdo, $originalSlug);
            $oldRow = gta_blog_fetch_one_including_deleted($pdo, $originalSlug);
            gta_blog_regenerate($pdo, $blogConfig, $oldRow);
        }

        header('Location: articles.php');
        exit;
    }
}

$isNew = $originalSlug === '';
$csrf = admin_csrf_token();
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $isNew ? 'Nuovo articolo' : 'Modifica articolo' ?> — Admin Blog GTAVIANI</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <?= admin_topbar_html('articles', $user['username']) ?>
  <div class="admin-container">
    <?php if ($error !== null): ?><p class="admin-error"><?= admin_html_escape($error) ?></p><?php endif; ?>

    <div class="admin-card admin-form">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= admin_html_escape($csrf) ?>">
        <input type="hidden" name="original_slug" value="<?= admin_html_escape($originalSlug) ?>">

        <label>Titolo
          <input type="text" name="title" required value="<?= admin_html_escape((string) $values['title']) ?>">
        </label>

        <label>Slug (URL)
          <input type="text" name="slug" value="<?= admin_html_escape((string) $values['slug']) ?>">
          <span class="admin-hint">Vuoto = derivato dal titolo. <?= $isNew ? '' : 'Attenzione: cambiarlo genera un nuovo URL — quello vecchio smette di funzionare (l\'articolo alla vecchia URL viene ritirato).' ?></span>
        </label>

        <label>Sommario (intro)
          <textarea name="intro" rows="3" required><?= admin_html_escape((string) $values['intro']) ?></textarea>
        </label>

        <label>Corpo articolo (HTML)
          <textarea name="body_html" rows="16" required><?= admin_html_escape((string) $values['body_html']) ?></textarea>
          <span class="admin-hint">HTML già pronto (non markdown).</span>
        </label>

        <label>Immagine principale
          <input type="file" id="image-upload-input" accept="image/jpeg,image/png,image/webp">
          <span class="admin-hint">Formati accettati: jpg, png, webp — max 5MB. Carica per riempire il campo URL sotto.</span>
          <span id="upload-status" class="admin-hint"></span>
          <input type="text" name="primary_image" id="primary-image-field" value="<?= admin_html_escape((string) $values['primary_image']) ?>" placeholder="https://... (o carica un file sopra)">
          <?php if (!empty($values['primary_image'])): ?>
            <img id="image-preview" src="<?= admin_html_escape((string) $values['primary_image']) ?>" class="admin-image-preview" alt="Anteprima">
          <?php else: ?>
            <img id="image-preview" src="" class="admin-image-preview" alt="Anteprima" style="display:none;">
          <?php endif; ?>
        </label>

        <label>Immagine OG (opzionale)
          <input type="text" name="og_image" value="<?= admin_html_escape((string) $values['og_image']) ?>" placeholder="Default = immagine principale">
        </label>

        <label>Meta description (opzionale)
          <input type="text" name="meta_description" value="<?= admin_html_escape((string) $values['meta_description']) ?>" placeholder="Default = sommario">
        </label>

        <label>Keywords (opzionale, separate da virgola)
          <input type="text" name="keywords" value="<?= admin_html_escape((string) $values['keywords']) ?>">
        </label>

        <button type="submit" class="admin-btn">Salva</button>
        <a href="articles.php" class="admin-btn admin-btn-secondary">Annulla</a>
        <span class="admin-hint">Il salvataggio non cambia lo stato pubblicato/bozza — usa i bottoni dedicati nella lista articoli.</span>
      </form>
    </div>
  </div>

  <script>
    // Upload asincrono: carica il file su upload-image.php, poi riempie il
    // campo testo primary_image con l'URL pubblico ritornato — niente
    // framework, submit del form principale resta invariato.
    (function () {
      var fileInput = document.getElementById('image-upload-input');
      var statusEl = document.getElementById('upload-status');
      var urlField = document.getElementById('primary-image-field');
      var preview = document.getElementById('image-preview');
      var csrfToken = <?= json_encode($csrf) ?>;

      fileInput.addEventListener('change', function () {
        if (!fileInput.files.length) { return; }
        var formData = new FormData();
        formData.append('image', fileInput.files[0]);
        formData.append('csrf_token', csrfToken);
        statusEl.textContent = 'Caricamento in corso...';

        fetch('upload-image.php', { method: 'POST', body: formData })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if (data.ok) {
              urlField.value = data.url;
              preview.src = data.url;
              preview.style.display = 'block';
              statusEl.textContent = 'Caricata: ' + data.url;
            } else {
              statusEl.textContent = 'Errore: ' + data.error;
            }
          })
          .catch(function () {
            statusEl.textContent = 'Errore di rete durante il caricamento.';
          });
      });
    })();
  </script>
</body>
</html>
