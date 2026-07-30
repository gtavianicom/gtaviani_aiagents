<?php

declare(strict_types=1);

// GTA-BLOG-001 — motore blog per aiagents.gtaviani.com.
// Adattato da awc_website/api/blog-template.php (stesso contratto payload,
// vedi briefs/gtaviani_awaoffer/aiagents-blog-api-contract.md nel repo
// pm-websites), ma con differenze deliberate rispetto all'originale AWC:
//
// 1. Header/footer riprendono il markup reale del sito (site-header/site-nav,
//    site-footer/footer-links/footer-landing-links di index.html), non uno
//    stile Tailwind indipendente come in AWC — brand coerente.
// 2. NESSUNA riscrittura runtime di index.html: il deploy di questo sito fa
//    "git pull" diretto nel webroot (update.sh), quindi un file tracciato
//    riscritto a ogni publish andrebbe in conflitto ad ogni pull. Il link
//    "Blog" in navbar/footer è stato aggiunto una tantum, a mano, nelle
//    pagine sorgente — questo motore non lo tocca mai più.
// 3. Sitemap separata (sitemap-blog.xml, gitignored) invece di riscrivere
//    sitemap.xml (tracciato) — stesso motivo del punto 2. robots.txt referenzia
//    entrambe le sitemap con due righe "Sitemap:" distinte.
// 4. llms.txt NON viene toccato in questo task (nessun contenuto blog reale
//    da riportare finché non ci sono articoli veri).

function gta_blog_slugify(string $text): string
{
    $text = trim($text);
    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    if ($translit !== false) {
        $text = $translit;
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim((string) $text, '-');
}

function gta_blog_db(array $config): PDO
{
    $dir = dirname($config['db_path']);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS articles (
        slug TEXT PRIMARY KEY,
        title TEXT NOT NULL,
        intro TEXT NOT NULL,
        body_html TEXT NOT NULL,
        primary_image TEXT,
        meta_description TEXT,
        og_image TEXT,
        keywords TEXT,
        published INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    // GTA-BLOG-005 (punto 1) — soft-delete: colonna aggiunta con migrazione
    // idempotente, cosi' funziona sia su DB nuovi (creati dopo questa
    // modifica, dove CREATE TABLE sopra non la include ancora di proposito
    // — vedi nota) sia su DB esistenti creati prima. SQLite non supporta
    // "ADD COLUMN IF NOT EXISTS", quindi il check passa da PRAGMA table_info.
    $hasDeletedAt = false;
    foreach ($pdo->query('PRAGMA table_info(articles)') as $col) {
        if ($col['name'] === 'deleted_at') {
            $hasDeletedAt = true;
            break;
        }
    }
    if (!$hasDeletedAt) {
        $pdo->exec('ALTER TABLE articles ADD COLUMN deleted_at TEXT NULL');
    }

    return $pdo;
}

function gta_blog_fetch_one(PDO $pdo, string $slug): ?array
{
    // GTA-BLOG-005: esclude di default le righe soft-deleted — per un
    // agente/consumer normale, un articolo cancellato e' sparito. Nessun
    // parametro "includiCancellati" oggi: non serve un consumer che li veda
    // (restore/verifiche dirette si fanno via query SQL diretta, vedi task).
    $stmt = $pdo->prepare('SELECT * FROM articles WHERE slug = :slug AND deleted_at IS NULL');
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function gta_blog_fetch_published(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT * FROM articles WHERE published = 1 AND deleted_at IS NULL ORDER BY created_at DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// GTA-BLOG-002: usata da action:list — TUTTI gli articoli, pubblicati e non
// (le bozze devono essere visibili a un agente editor), ordinati per
// updated_at più recente prima.
// GTA-BLOG-005: esclude comunque le righe soft-deleted (deleted_at valorizzato)
// — "tutti" qui significa "tutti i non cancellati", non un vero storico.
function gta_blog_fetch_all(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT * FROM articles WHERE deleted_at IS NULL ORDER BY updated_at DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// GTA-BLOG-005: lettura diretta di una riga soft-deleted, usata solo da
// gta_blog_restore() — non esposta ne' come action REST ne' come tool MCP.
function gta_blog_fetch_one_including_deleted(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM articles WHERE slug = :slug');
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function gta_blog_upsert(PDO $pdo, array $data): array
{
    $now = gmdate('c');
    $existing = gta_blog_fetch_one($pdo, $data['slug']);
    $createdAt = $existing['created_at'] ?? $now;

    $stmt = $pdo->prepare('INSERT INTO articles
        (slug, title, intro, body_html, primary_image, meta_description, og_image, keywords, published, created_at, updated_at)
        VALUES (:slug, :title, :intro, :body_html, :primary_image, :meta_description, :og_image, :keywords, :published, :created_at, :updated_at)
        ON CONFLICT(slug) DO UPDATE SET
            title = excluded.title,
            intro = excluded.intro,
            body_html = excluded.body_html,
            primary_image = excluded.primary_image,
            meta_description = excluded.meta_description,
            og_image = excluded.og_image,
            keywords = excluded.keywords,
            published = excluded.published,
            updated_at = excluded.updated_at');

    $stmt->execute([
        ':slug' => $data['slug'],
        ':title' => $data['title'],
        ':intro' => $data['intro'],
        ':body_html' => $data['body_html'],
        ':primary_image' => $data['primary_image'],
        ':meta_description' => $data['meta_description'],
        ':og_image' => $data['og_image'],
        ':keywords' => $data['keywords'],
        ':published' => $data['published'] ? 1 : 0,
        ':created_at' => $createdAt,
        ':updated_at' => $now,
    ]);

    return gta_blog_fetch_one($pdo, $data['slug']);
}

// GTA-BLOG-005 (punto 1) — soft-delete: un agente Paperclip lavora senza
// supervisione umana in tempo reale, uno slug sbagliato (allucinazione/
// errore) non deve poter cancellare in modo irreversibile. La riga resta nel
// DB con deleted_at valorizzato e published forzato a 0 — la rigenerazione
// del sito statico (chiamata dal caller dopo questa funzione, invariata)
// rimuove comunque l'articolo dal sito pubblico esattamente come prima.
function gta_blog_delete(PDO $pdo, string $slug): bool
{
    $stmt = $pdo->prepare("UPDATE articles SET deleted_at = :deleted_at, published = 0, updated_at = :updated_at
        WHERE slug = :slug AND deleted_at IS NULL");
    $now = gmdate('c');
    $stmt->execute([':slug' => $slug, ':deleted_at' => $now, ':updated_at' => $now]);
    return $stmt->rowCount() > 0;
}

// GTA-BLOG-005 (punto 1) — azione "restore", raggiungibile SOLO via blog.php
// diretto (token blog-config.php), MAI esposta come tool MCP (l'agente
// Paperclip non deve poter reincludere un articolo cancellato).
// Scelta prudente: rimette deleted_at a NULL ma NON ripristina lo stato
// "published" che l'articolo aveva prima della cancellazione — torna sempre
// come bozza (published:0). Motivo: chi esegue il restore sta verificando
// un recupero da errore, non necessariamente rimettendo l'articolo live
// all'istante; forzare una revisione umana esplicita (publish separato) prima
// che torni visibile pubblicamente e' piu' sicuro che ripubblicarlo di scatto.
function gta_blog_restore(PDO $pdo, string $slug): bool
{
    $stmt = $pdo->prepare("UPDATE articles SET deleted_at = NULL, published = 0, updated_at = :updated_at
        WHERE slug = :slug AND deleted_at IS NOT NULL");
    $stmt->execute([':slug' => $slug, ':updated_at' => gmdate('c')]);
    return $stmt->rowCount() > 0;
}

function gta_blog_write_file(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Impossibile scrivere il file: ' . $path);
    }
}

// Header identico (a meno del link Blog, già "attivo" qui) al site-header di
// index.html/retail-ecommerce-ai-team.html — pagine blog vivono a root
// (/blog.html, /blog/<slug>.html), i link sono quindi assoluti.
function gta_blog_header_html(): string
{
    return '  <header class="site-header">' . "\n"
        . '    <div class="container header-inner">' . "\n"
        . '      <a href="/" class="logo-link">' . "\n"
        . '        <img src="/assets/images/gtaviani-logo-orizzontale.png" alt="GTAVIANI — Retail E-Commerce Growth" class="logo">' . "\n"
        . '      </a>' . "\n"
        . "\n"
        . '      <input type="checkbox" id="nav-toggle" class="nav-toggle-input">' . "\n"
        . '      <label for="nav-toggle" class="nav-toggle-label" aria-label="Apri il menu">' . "\n"
        . '        <i class="fas fa-bars"></i>' . "\n"
        . '      </label>' . "\n"
        . "\n"
        . '      <nav class="site-nav">' . "\n"
        . '        <a href="/#fiducia-faq">AI Act &amp; GDPR</a>' . "\n"
        . '        <a href="/#consulente-esterno">Consulenza AI</a>' . "\n"
        . '        <a href="/#piattaforma">AI Workspace</a>' . "\n"
        . '        <a href="/retail-ecommerce-ai-team.html" class="nav-badge-link">AI E-Commerce</a>' . "\n"
        . '        <a href="/blog.html" class="nav-badge-link active">Blog</a>' . "\n"
        . '      </nav>' . "\n"
        . "\n"
        . '      <a href="/contact.html" id="cta-nav-contact" class="btn btn-primary nav-contact">CONTATTO</a>' . "\n"
        . '      <a href="https://aispace.gtaviani.com" class="btn btn-primary-light nav-login">LOGIN</a>' . "\n"
        . '    </div>' . "\n"
        . '  </header>';
}

// Footer identico al site-footer di index.html (footer-links + footer-landing-links
// già esistenti, con "Blog" aggiunto in footer-links) — NON rigenerato con gli
// articoli recenti come in AWC: qui il footer è statico, stesso motivo del
// punto 2 in cima al file.
function gta_blog_footer_html(): string
{
    return '  <section id="cta-footer" class="dark-section">' . "\n"
        . '    <footer class="site-footer">' . "\n"
        . '      <div class="footer-inner">' . "\n"
        . '        <img src="/assets/images/gtaviani-logo-orizzontale.png" alt="GTAVIANI — Retail E-Commerce Growth" class="footer-logo">' . "\n"
        . '        <div class="footer-links">' . "\n"
        . '          <a href="https://www.gtaviani.com">gtaviani.com</a>' . "\n"
        . '          <a href="https://aispace.gtaviani.com">Accesso Cliente</a>' . "\n"
        . '          <a href="/sitemap.xml">Sitemap</a>' . "\n"
        . '          <a href="/privacy.html">Privacy</a>' . "\n"
        . '          <a href="/blog.html">Blog</a>' . "\n"
        . '        </div>' . "\n"
        . '      </div>' . "\n"
        . '      <nav class="footer-landing-links" aria-label="Soluzioni AI per la tua PMI">' . "\n"
        . '        <a href="/retail-ecommerce-ai-team.html">AI E-Commerce Team (Retail)</a>' . "\n"
        . '        <a href="/lp-consulente-ai.html">Consulente AI per il Retail</a>' . "\n"
        . '        <a href="/lp-ai-act-gdpr.html">AI Act e GDPR per PMI</a>' . "\n"
        . '        <a href="/lp-chatbot-aziendale.html">AI Chatbot Aziendali</a>' . "\n"
        . '        <a href="/lp-fondi-pmi.html">Fondi e Finanziamenti per AI</a>' . "\n"
        . '      </nav>' . "\n"
        . '      <p class="footer-copy">&copy; 2026 GTAVIANI — Retail E-Commerce Growth. Tutti i diritti riservati.</p>' . "\n"
        . '    </footer>' . "\n"
        . '  </section>';
}

function gta_blog_head_html(string $title, string $description, string $canonical, string $ogImage, string $robots = 'index, follow', string $extraJsonLd = ''): string
{
    // NOTA: niente Google Tag Manager/Consent Mode qui — scope GTA-BLOG-001 è
    // il motore/infrastruttura, non l'integrazione analytics sulle pagine
    // generate (nessun articolo reale da tracciare oggi). Se in futuro serve,
    // è lo stesso blocco <script> già presente in cima a index.html.
    $html = '<!DOCTYPE html>' . "\n" . '<html lang="it">' . "\n" . '<head>' . "\n"
        . '  <meta charset="UTF-8">' . "\n"
        . '  <meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n"
        . '  <title>' . $title . '</title>' . "\n"
        . '  <meta name="description" content="' . $description . '">' . "\n"
        . '  <link rel="canonical" href="' . $canonical . '">' . "\n"
        . '  <meta name="robots" content="' . $robots . '">' . "\n"
        . '  <meta name="theme-color" content="#014374">' . "\n"
        . '  <link rel="icon" type="image/png" href="/assets/images/gtaviani-marca.png">' . "\n"
        . '  <link rel="apple-touch-icon" href="/assets/images/gtaviani-marca.png">' . "\n"
        . '  <meta property="og:type" content="article">' . "\n"
        . '  <meta property="og:url" content="' . $canonical . '">' . "\n"
        . '  <meta property="og:site_name" content="GTaviani Consulting">' . "\n"
        . '  <meta property="og:locale" content="it_IT">' . "\n"
        . '  <meta property="og:title" content="' . $title . '">' . "\n"
        . '  <meta property="og:description" content="' . $description . '">' . "\n"
        . '  <meta property="og:image" content="' . $ogImage . '">' . "\n"
        . '  <meta name="twitter:card" content="summary_large_image">' . "\n"
        . '  <meta name="twitter:title" content="' . $title . '">' . "\n"
        . '  <meta name="twitter:description" content="' . $description . '">' . "\n"
        . '  <meta name="twitter:image" content="' . $ogImage . '">' . "\n"
        . '  <link rel="stylesheet" href="/assets/css/style.css">' . "\n"
        . '  <link rel="stylesheet" href="/assets/css/blog.css">' . "\n"
        . '  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">' . "\n";
    if ($extraJsonLd !== '') {
        $html .= $extraJsonLd . "\n";
    }
    $html .= '</head>' . "\n";
    return $html;
}

function gta_blog_render_article(array $article, array $config): string
{
    $siteUrl = rtrim($config['site_url'], '/');
    $title = htmlspecialchars($article['title'] . ' | Blog GTAVIANI', ENT_QUOTES);
    $descriptionRaw = $article['meta_description'] ?: $article['intro'];
    $description = htmlspecialchars($descriptionRaw, ENT_QUOTES);
    $canonical = $siteUrl . '/blog/' . $article['slug'] . '.html';

    $ogImageRaw = $article['og_image'] ?: $article['primary_image'];
    if ($ogImageRaw) {
        $ogImage = (strpos($ogImageRaw, 'http') === 0) ? $ogImageRaw : $siteUrl . '/' . ltrim($ogImageRaw, '/');
    } else {
        $ogImage = $siteUrl . '/assets/images/og-image.png';
    }
    $ogImageEsc = htmlspecialchars($ogImage, ENT_QUOTES);

    $jsonLdData = [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $article['title'],
        'image' => $ogImage,
        'datePublished' => $article['created_at'],
        'dateModified' => $article['updated_at'],
        'description' => $descriptionRaw,
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'GTAVIANI Consulting',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $siteUrl . '/assets/images/gtaviani-marca.png',
            ],
        ],
    ];
    // NB: niente JSON_UNESCAPED_SLASHES qui — se un titolo/testo contenesse
    // letteralmente "</script>" senza slash escapate romperebbe il tag e il
    // resto della pagina (il body_html è HTML fidato, ma title/description
    // arrivano come stringhe qualsiasi dall'agente che pubblica).
    $jsonLd = '  <script type="application/ld+json">' . "\n"
        . json_encode($jsonLdData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
        . '  </script>';

    $head = gta_blog_head_html($title, $description, $canonical, $ogImageEsc, 'index, follow', $jsonLd);

    $titleEsc = htmlspecialchars($article['title'], ENT_QUOTES);
    $introEsc = htmlspecialchars($article['intro'], ENT_QUOTES);

    $imageHtml = '';
    if (!empty($article['primary_image'])) {
        $imgSrc = htmlspecialchars($article['primary_image'], ENT_QUOTES);
        $imageHtml = '        <img src="' . $imgSrc . '" alt="' . $titleEsc . '" class="blog-article-image">' . "\n";
    }

    $keywordsHtml = '';
    if (!empty($article['keywords'])) {
        $kws = array_filter(array_map('trim', explode(',', (string) $article['keywords'])));
        if ($kws) {
            $chips = '';
            foreach ($kws as $kw) {
                $chips .= '<span class="blog-keyword-chip">' . htmlspecialchars($kw, ENT_QUOTES) . '</span>';
            }
            $keywordsHtml = '        <div class="blog-article-keywords">' . $chips . '</div>' . "\n";
        }
    }

    $header = gta_blog_header_html();
    $footer = gta_blog_footer_html();
    $bodyHtml = $article['body_html'];

    return $head
        . '<body>' . "\n\n"
        . $header . "\n\n"
        . '  <section class="hero blog-hero">' . "\n"
        . '    <p class="hero-badge">Blog</p>' . "\n"
        . '    <h1>' . $titleEsc . '</h1>' . "\n"
        . '    <p class="hero-subtitle">' . $introEsc . '</p>' . "\n"
        . '  </section>' . "\n\n"
        . '  <section class="blog-article">' . "\n"
        . $imageHtml
        . '        <div class="blog-article-body">' . "\n"
        . $bodyHtml . "\n"
        . '        </div>' . "\n"
        . $keywordsHtml
        . '  </section>' . "\n\n"
        . $footer . "\n"
        . '</body>' . "\n"
        . '</html>' . "\n";
}

function gta_blog_render_list(array $articles, array $config): string
{
    $siteUrl = rtrim($config['site_url'], '/');
    $title = htmlspecialchars('Blog | GTAVIANI Consulting', ENT_QUOTES);
    $descriptionRaw = 'Approfondimenti di GTAVIANI Consulting su adozione AI per PMI retail ed e-commerce italiane, AI Act, GDPR e AI Workspace.';
    $description = htmlspecialchars($descriptionRaw, ENT_QUOTES);
    $canonical = $siteUrl . '/blog.html';
    $ogImage = htmlspecialchars($siteUrl . '/assets/images/og-image.png', ENT_QUOTES);

    $head = gta_blog_head_html($title, $description, $canonical, $ogImage);

    if (empty($articles)) {
        $cardsHtml = '      <div class="blog-empty">' . "\n"
            . '        I primi articoli arrivano presto.' . "\n"
            . '      </div>' . "\n";
    } else {
        $cardsHtml = '';
        foreach ($articles as $article) {
            $titleEsc = htmlspecialchars($article['title'], ENT_QUOTES);
            $introEsc = htmlspecialchars($article['intro'], ENT_QUOTES);
            $href = '/blog/' . $article['slug'] . '.html';
            $imgHtml = '';
            if (!empty($article['primary_image'])) {
                $imgSrc = htmlspecialchars($article['primary_image'], ENT_QUOTES);
                $imgHtml = '          <img src="' . $imgSrc . '" alt="' . $titleEsc . '" class="blog-card-img">' . "\n";
            }
            $cardsHtml .= '      <a href="' . $href . '" class="blog-card">' . "\n"
                . $imgHtml
                . '        <h2 class="blog-card-title">' . $titleEsc . '</h2>' . "\n"
                . '        <p class="blog-card-intro">' . $introEsc . '</p>' . "\n"
                . '        <span class="blog-card-cta">Leggi articolo &rarr;</span>' . "\n"
                . '      </a>' . "\n";
        }
    }

    $header = gta_blog_header_html();
    $footer = gta_blog_footer_html();

    return $head
        . '<body>' . "\n\n"
        . $header . "\n\n"
        . '  <section class="hero blog-hero">' . "\n"
        . '    <h1>Blog</h1>' . "\n"
        . '    <p class="hero-subtitle">Approfondimenti su adozione AI, AI Act/GDPR e consulenza per le PMI italiane del retail e dell\'e-commerce.</p>' . "\n"
        . '  </section>' . "\n\n"
        . '  <section class="blog-grid-section">' . "\n"
        . '    <div class="blog-grid">' . "\n"
        . $cardsHtml
        . '    </div>' . "\n"
        . '  </section>' . "\n\n"
        . $footer . "\n"
        . '</body>' . "\n"
        . '</html>' . "\n";
}

// Sitemap dedicata (sitemap-blog.xml, MAI sitemap.xml — vedi nota in cima al
// file): file gitignored di cui questo motore è l'unico proprietario, quindi
// possiamo rigenerarlo da zero ad ogni chiamata senza preoccuparci di
// mergiare con contenuto preesistente (a differenza del pattern AWC su
// sitemap.xml, che invece è tracciato e va preservato).
function gta_blog_update_sitemap(array $articles, array $config): void
{
    $path = $config['site_root'] . '/sitemap-blog.xml';
    $today = gmdate('Y-m-d');
    $siteUrl = rtrim($config['site_url'], '/');

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    $urlset = $dom->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');
    $dom->appendChild($urlset);

    $addUrl = static function (string $loc, string $lastmod, string $changefreq, string $priority) use ($dom, $urlset): void {
        $url = $dom->createElement('url');
        $url->appendChild($dom->createElement('loc', $loc));
        $url->appendChild($dom->createElement('lastmod', $lastmod));
        $url->appendChild($dom->createElement('changefreq', $changefreq));
        $url->appendChild($dom->createElement('priority', $priority));
        $urlset->appendChild($url);
    };

    $addUrl($siteUrl . '/blog.html', $today, 'weekly', '0.6');

    foreach ($articles as $article) {
        $lastmod = $article['updated_at'] ? substr($article['updated_at'], 0, 10) : $today;
        $addUrl($siteUrl . '/blog/' . $article['slug'] . '.html', $lastmod, 'monthly', '0.5');
    }

    $dom->save($path);
}

// GTA-BLOG-007: notifica automatica a Google quando la sitemap del blog
// cambia per un articolo che va live — sostituisce il resubmit manuale che
// Gabriele faceva a mano su Search Console (insostenibile con articoli
// generati/pubblicati più di frequente). Solo un "hint" al crawler, non
// garantisce indicizzazione immediata; fallisce in silenzio (mai bloccare
// una publish/regenerate per un problema di rete verso Google).
function gta_blog_ping_search_engines(array $config): void
{
    $sitemapUrl = rtrim($config['site_url'], '/') . '/sitemap-blog.xml';
    $pingUrl = 'https://www.google.com/ping?sitemap=' . urlencode($sitemapUrl);

    $context = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 3, 'ignore_errors' => true],
    ]);

    try {
        @file_get_contents($pingUrl, false, $context);
    } catch (\Throwable $e) {
        // Silenzioso di proposito — vedi commento sopra.
    }
}

function gta_blog_regenerate(PDO $pdo, array $config, ?array $affectedArticle = null): void
{
    $published = gta_blog_fetch_published($pdo);

    if ($affectedArticle !== null) {
        $path = $config['site_root'] . '/blog/' . $affectedArticle['slug'] . '.html';
        if (!empty($affectedArticle['published'])) {
            $html = gta_blog_render_article($affectedArticle, $config);
            gta_blog_write_file($path, $html);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    $listHtml = gta_blog_render_list($published, $config);
    gta_blog_write_file($config['site_root'] . '/blog.html', $listHtml);

    gta_blog_update_sitemap($published, $config);
    // NB: niente aggiornamento di llms.txt qui — deliberatamente fuori scope
    // GTA-BLOG-001 (nessun contenuto blog reale da riportare oggi).

    // GTA-BLOG-007: ping automatico a Google solo quando l'articolo toccato
    // da questa chiamata è effettivamente pubblicato (non su ogni rigenerazione
    // generica, non su delete/restore-a-bozza).
    if ($affectedArticle !== null && !empty($affectedArticle['published'])) {
        gta_blog_ping_search_engines($config);
    }
}
