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

// GTA-BLOG-IMG-002 — vero se l'IP ricade in un range privato/riservato/
// loopback/link-local (IPv4 e IPv6) — protezione SSRF, usata per rifiutare
// URL che puntano a indirizzi interni prima di scaricarli. Spostata qui da
// mcp.php (era gta_mcp_is_disallowed_ip) perché ora la usa anche blog.php.
function gta_blog_is_disallowed_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

// GTA-BLOG-IMG-002 — scarica un'immagine da una URL pubblica arbitraria e la
// ri-ospita sul sito (assets/images/blog/uploads/), ritornando l'URL
// pubblico. Logica invariata rispetto alla versione originale in mcp.php
// (gta_mcp_upload_image, GTA-BLOG-IMG-001) — spostata qui perché ora la
// chiama anche gta_blog_ensure_hosted_image() sotto, usata da entrambi i
// publish path (blog.php diretto e tool MCP), non solo dal tool upload_image
// esplicito. Lancia RuntimeException col messaggio d'errore invece di
// ToolCallException (specifico dell'SDK MCP, non disponibile qui) — chi
// chiama da mcp.php la converte.
//
// Protezione SSRF (URL arbitraria fornita da un chiamante non fidato): solo
// schema http/https, l'hostname viene risolto e l'IP validato PRIMA di
// connettersi (rifiuta range privati/riservati/loopback/link-local, incluso
// il metadata endpoint cloud 169.254.169.254), l'IP validato viene
// "pinnato" con CURLOPT_RESOLVE per evitare DNS rebinding, nessun redirect
// seguito automaticamente, download interrotto oltre il limite dimensione
// anche se Content-Length mente, contenuto rivalidato come immagine reale
// dopo il download. Stessa policy formati/limite del pannello admin
// (admin/upload-image.php): jpg/png/webp, max 5MB.
function gta_blog_download_and_host_image(string $imageUrl, array $config): string
{
    $imageUrl = trim($imageUrl);
    if ($imageUrl === '') {
        throw new \RuntimeException('image_url obbligatorio');
    }

    $parts = parse_url($imageUrl);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        throw new \RuntimeException('image_url non valida');
    }

    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new \RuntimeException('Schema non consentito — solo http/https');
    }

    $host = $parts['host'];
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $ips[] = $host;
    } else {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = $r['ip'];
                }
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        if (empty($ips)) {
            $resolved = gethostbyname($host);
            if ($resolved !== $host) {
                $ips[] = $resolved;
            }
        }
    }

    if (empty($ips)) {
        throw new \RuntimeException('Impossibile risolvere l\'host della URL');
    }

    $safeIp = null;
    foreach ($ips as $ip) {
        if (gta_blog_is_disallowed_ip($ip)) {
            throw new \RuntimeException('URL non consentita — punta a un indirizzo interno/riservato');
        }
        if ($safeIp === null) {
            $safeIp = $ip;
        }
    }

    $maxBytes = 5 * 1024 * 1024;
    $tmpFile = tempnam(sys_get_temp_dir(), 'gta_img_');
    $fh = fopen($tmpFile, 'wb');
    if ($fh === false) {
        throw new \RuntimeException('Impossibile preparare il download');
    }

    $aborted = false;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $imageUrl,
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $safeIp],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_FILE => $fh,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => static function ($res, $downloadSize, $downloadedBytes) use ($maxBytes, &$aborted): int {
            if ($downloadedBytes > $maxBytes) {
                $aborted = true;

                return 1;
            }

            return 0;
        },
        CURLOPT_USERAGENT => 'GTAviani-BlogBot/1.0',
    ]);

    $ok = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    fclose($fh);

    if ($aborted) {
        @unlink($tmpFile);
        throw new \RuntimeException('Immagine troppo grande (limite 5MB)');
    }

    if ($ok === false) {
        @unlink($tmpFile);
        throw new \RuntimeException('Download fallito: ' . $curlErr);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        @unlink($tmpFile);
        throw new \RuntimeException('Download fallito: HTTP ' . $httpCode);
    }

    clearstatcache(true, $tmpFile);
    if (filesize($tmpFile) > $maxBytes) {
        @unlink($tmpFile);
        throw new \RuntimeException('Immagine troppo grande (limite 5MB)');
    }

    $imageInfo = @getimagesize($tmpFile);
    if ($imageInfo === false) {
        @unlink($tmpFile);
        throw new \RuntimeException('Il contenuto scaricato non è un\'immagine valida');
    }

    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $mime = $imageInfo['mime'];
    if (!isset($allowedMime[$mime])) {
        @unlink($tmpFile);
        throw new \RuntimeException('Formato non supportato — solo jpg, png, webp');
    }

    $uploadDir = $config['site_root'] . '/assets/images/blog/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = $allowedMime[$mime];
    $filename = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destination = $uploadDir . '/' . $filename;

    if (!rename($tmpFile, $destination)) {
        @unlink($tmpFile);
        throw new \RuntimeException('Impossibile salvare l\'immagine sul server');
    }
    chmod($destination, 0644);

    return rtrim((string) $config['site_url'], '/') . '/assets/images/blog/uploads/' . $filename;
}

// GTA-BLOG-IMG-002 (2026-08-04) — responsabilità spostata dall'agente al
// server: prima l'agente Paperclip doveva chiamare esplicitamente il tool
// upload_image PRIMA di publish_article, passandogli l'URL esterno
// (tipicamente il bucket Google Cloud del generatore immagini) e usando
// l'URL ritornato — un bug di binding lato Paperclip (tools/list in cache,
// non vede i tool aggiunti dopo la connessione) ha fatto sì che l'agente
// pubblicasse comunque con l'URL esterno diretto, mai ri-ospitato. Fix
// strutturale: ogni volta che un articolo viene salvato con
// primary_image/og_image che punta a un host DIVERSO dal nostro
// (site_url), il server la scarica e ri-ospita da solo, qui — a prescindere
// da cosa fa/dimentica l'agente o quale tool riesce a chiamare. Mai
// bloccante: se il download fallisce (rete, formato, SSRF), l'URL originale
// resta invariata e l'errore torna nel campo 'error' — il publish/save non
// si interrompe mai per questo (stesso principio del fallback già adottato
// lato skill Paperclip, solo che ora è anche una rete di sicurezza qui).
//
// @return array{url: ?string, rehosted: bool, error: ?string}
function gta_blog_ensure_hosted_image(?string $url, array $config): array
{
    $url = $url !== null ? trim($url) : '';
    if ($url === '') {
        return ['url' => $url, 'rehosted' => false, 'error' => null];
    }

    $ourHost = strtolower((string) parse_url((string) $config['site_url'], PHP_URL_HOST));
    $urlHost = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($urlHost === '' || $urlHost === $ourHost) {
        // Già nostra (o URL relativa/non http) — niente da fare.
        return ['url' => $url, 'rehosted' => false, 'error' => null];
    }

    try {
        $hostedUrl = gta_blog_download_and_host_image($url, $config);

        return ['url' => $hostedUrl, 'rehosted' => true, 'error' => null];
    } catch (\RuntimeException $e) {
        // Loggato lato server (prima non lo era): il chiamante MCP/API vede
        // comunque il motivo in primary_image_rehost_warning, ma quel campo
        // è visibile solo se qualcuno legge la risposta — questo resta nel
        // log del server anche se nessuno la controlla in quel momento.
        error_log('[gta_blog_ensure_hosted_image] rehost fallito per ' . $url . ': ' . $e->getMessage());

        return ['url' => $url, 'rehosted' => false, 'error' => $e->getMessage()];
    }
}

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

    // GTA-BLOG-014 — stessa migrazione idempotente di deleted_at sopra:
    // data/ora (UTC, ISO8601) da cui un articolo approvato (published=1)
    // diventa effettivamente live. NULL = nessuna schedulazione, comportamento
    // invariato (live appena published=1).
    $hasScheduledPublishAt = false;
    foreach ($pdo->query('PRAGMA table_info(articles)') as $col) {
        if ($col['name'] === 'scheduled_publish_at') {
            $hasScheduledPublishAt = true;
            break;
        }
    }
    if (!$hasScheduledPublishAt) {
        $pdo->exec('ALTER TABLE articles ADD COLUMN scheduled_publish_at TEXT NULL');
    }

    // GTA-ADMIN-002 — data di pubblicazione "vera", per ordinare/mostrare la
    // lista admin senza usare updated_at (che si sposta a ogni modifica di
    // contenuto, anche su un articolo pubblicato da settimane — sballerebbe
    // l'ordine "più recente in alto"). Calcolata/stampata da gta_blog_upsert,
    // vedi lì per la logica completa.
    $hasPublishedAt = false;
    foreach ($pdo->query('PRAGMA table_info(articles)') as $col) {
        if ($col['name'] === 'published_at') {
            $hasPublishedAt = true;
            break;
        }
    }
    if (!$hasPublishedAt) {
        $pdo->exec('ALTER TABLE articles ADD COLUMN published_at TEXT NULL');
    }

    // Backfill self-healing (NON solo al momento della ALTER sopra: sulla
    // riga di produzione la colonna può già esistere — creata da un deploy
    // precedente — con righe rimaste a NULL perché scritte prima che questa
    // logica di backfill esistesse). Righe già valorizzate non vengono mai
    // toccate (entrambe le query filtrano su published_at IS NULL), quindi
    // ripetere questo passo a ogni connessione costa una query quasi sempre
    // a zero righe — stesso principio di gta_blog_publish_due(). Priorità:
    // se c'è una schedulazione, quella è la data; altrimenti, per un
    // articolo già pubblicato, la miglior approssimazione disponibile è
    // updated_at (non abbiamo mai registrato il vero istante di
    // pubblicazione prima d'ora).
    $pdo->exec("UPDATE articles SET published_at = scheduled_publish_at
        WHERE published_at IS NULL AND scheduled_publish_at IS NOT NULL");
    $pdo->exec("UPDATE articles SET published_at = updated_at
        WHERE published_at IS NULL AND published = 1");

    // GTA-BLOG-018 — stessa migrazione idempotente di deleted_at/
    // scheduled_publish_at/published_at sopra: codice interno alphanumerico
    // (es. "10.1", riferimento al piano contenuti) — SOLO uso backend/MCP,
    // mai reso nel frontend pubblico (gta_blog_render_article/blog.html non
    // lo referenziano, di proposito).
    $hasArticleCode = false;
    foreach ($pdo->query('PRAGMA table_info(articles)') as $col) {
        if ($col['name'] === 'article_code') {
            $hasArticleCode = true;
            break;
        }
    }
    if (!$hasArticleCode) {
        $pdo->exec('ALTER TABLE articles ADD COLUMN article_code TEXT NULL');
    }

    return $pdo;
}

// GTA-BLOG-014 — normalizza un input data/ora libero (form admin già
// convertito in UTC, o stringa ISO8601 passata da blog.php/tool MCP) nel
// formato canonico salvato a DB: ISO8601 UTC (stesso stile di created_at/
// updated_at, gmdate('c')). Una stringa senza offset esplicito è trattata
// come UTC di default — scelta pensata per un chiamante API/agente, non per
// input umano diretto (quello passa da un form con conversione dedicata,
// vedi admin/article-edit.php). Stringa vuota/NULL -> NULL (nessuna
// schedulazione). Lancia InvalidArgumentException se non parsabile.
function gta_blog_normalize_scheduled_publish_at(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
    } catch (\Exception $e) {
        throw new InvalidArgumentException('scheduled_publish_at non valida — usa un formato data/ora riconoscibile, es. 2026-08-10T09:00:00Z');
    }

    return $dt->setTimezone(new DateTimeZone('UTC'))->format('c');
}

// GTA-BLOG-014 — vero se un articolo è EFFETTIVAMENTE raggiungibile in
// pubblico ora: published=1 E non cancellato E (nessuna data schedulata O
// data già raggiunta). Sostituisce, ovunque prima si controllava solo
// "published", il vero criterio di visibilità pubblica (pagina articolo,
// blog.html, sitemap-blog.xml).
function gta_blog_is_live(array $article, ?string $nowIso = null): bool
{
    if (empty($article['published']) || !empty($article['deleted_at'])) {
        return false;
    }
    $scheduled = $article['scheduled_publish_at'] ?? null;
    if (empty($scheduled)) {
        return true;
    }
    $now = $nowIso ?? gmdate('c');
    $scheduledTs = strtotime((string) $scheduled);
    $nowTs = strtotime($now);
    if ($scheduledTs === false || $nowTs === false) {
        // Dato corrotto/non parsabile: non blocchiamo un articolo già
        // approvato per un valore che non dovrebbe mai arrivare qui (la
        // scrittura passa sempre da gta_blog_normalize_scheduled_publish_at).
        return true;
    }

    return $scheduledTs <= $nowTs;
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
    // GTA-BLOG-014: "pubblicato" da solo non basta più — un articolo con
    // scheduled_publish_at nel futuro resta escluso da qui (quindi da
    // blog.html/sitemap-blog.xml, entrambi popolati da questa funzione) anche
    // se published=1. Confronto a livello SQL (non in PHP dopo il fetch) per
    // restare corretto anche quando la lista è grande.
    $stmt = $pdo->prepare("SELECT * FROM articles
        WHERE published = 1 AND deleted_at IS NULL
          AND (scheduled_publish_at IS NULL OR scheduled_publish_at <= :now)
        ORDER BY created_at DESC");
    $stmt->execute([':now' => gmdate('c')]);
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

// GTA-ADMIN-002 — variante di gta_blog_fetch_all SOLO per la lista del
// pannello admin: ordina per data di pubblicazione (published_at) più
// recente in alto, righe senza data (mai pubblicate) in fondo. Funzione
// separata invece di cambiare l'ordinamento di gta_blog_fetch_all perché
// quella è anche il contratto action:list per blog.php/tool MCP
// (documentato come "ordinati per updated_at decrescente" — cambiarlo
// avrebbe effetti sul consumo lato agente, fuori scope di questo aggiustamento
// solo-admin-panel).
function gta_blog_fetch_all_for_admin(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT * FROM articles WHERE deleted_at IS NULL
        ORDER BY (published_at IS NULL) ASC, published_at DESC, updated_at DESC");
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
    // GTA-BLOG-MCPAUTH-24H-001 (fix reale, 2026-08-06) — deve vedere anche
    // una riga soft-deleted (non gta_blog_fetch_one, che la esclude): un
    // upsert su uno slug cancellato in precedenza deve "resuscitarlo"
    // (vedi ON CONFLICT sotto, deleted_at = NULL), non trattarlo come
    // inesistente. Con la vecchia gta_blog_fetch_one qui, $existing
    // risultava sempre null per uno slug gia' cancellato -> created_at
    // veniva perso E, piu' grave, l'upsert successivo lasciava deleted_at
    // ancora valorizzato (mai nella lista UPDATE SET) mentre tutto il
    // resto veniva aggiornato: la riga restava invisibile per sempre a
    // qualunque fetch normale, pur essendo stata "ripubblicata" con
    // successo -- causa reale del fallimento ricorrente su uno slug
    // riusato dopo un delete_article (isolato con una riproduzione diretta
    // il 06/08, non un problema di dimensione payload ne' di SDK/sessioni
    // come inizialmente sospettato).
    $existing = gta_blog_fetch_one_including_deleted($pdo, $data['slug']);
    $createdAt = $existing['created_at'] ?? $now;

    // GTA-ADMIN-002 — data di pubblicazione "vera" (published_at), derivata
    // qui invece che lasciata a chi chiama, così ogni via di scrittura
    // (blog.php, tool MCP, admin panel) resta coerente automaticamente.
    // Regola: se questo salvataggio propone/conferma una schedulazione,
    // published_at SEGUE quella data (è il "vivo da" annunciato, passato o
    // futuro). Altrimenti, se l'articolo era GIA' pubblicato-senza-
    // schedulazione anche prima di questo salvataggio (un semplice edit di
    // contenuto), non tocchiamo published_at — un fix di battitura non deve
    // far "risalire" un articolo vecchio in cima alla lista. In ogni altro
    // caso (primo publish, o una schedulazione che viene tolta/cancellata
    // mentre resta pubblicato: l'intento è "vivo ORA") published_at diventa
    // adesso. Se published=false, published_at resta quello che era prima
    // (storico, non azzerato — se poi ripubblicato senza schedulazione
    // ripartirà da "adesso", vedi il ramo sopra: existing['published')
    // sarebbe false in quel momento).
    //
    // GTA-ADMIN-003: "senza schedulazione" va giudicato allo stato
    // dell'articolo ESISTENTE, non solo "scheduled_publish_at non
    // impostato" — un articolo pubblicato con una scheduled_publish_at
    // ormai PASSATA (consumata da gta_blog_publish_due, mai ripulita in
    // automatico) era comunque già live prima di questo salvataggio. Senza
    // questo controllo, aprire quell'articolo e svuotare il campo
    // "Pubblicazione programmata" (pensando sia "consumato e da ripulire")
    // veniva trattato come "schedulazione tolta mentre resta pubblicato" →
    // published_at saltava a oggi, cancellando la data storica reale. Solo
    // una schedulazione ancora FUTURA (articolo non ancora davvero live)
    // deve contare come "non era live", per coerenza con l'intento di
    // quel ramo (cancellare uno scheduling futuro = "vivo ORA" da adesso).
    $publishedAt = $existing['published_at'] ?? null;
    // GTA-BLOG-021 — prima questo intero ramo era condizionato a
    // "!empty($data['published'])": un salvataggio in bozza (published=false,
    // il caso normale per il tool MCP publish_article, sempre bozza per
    // design) che aggiornava SOLO scheduled_publish_at lasciava published_at
    // congelato al vecchio valore, disallineato dalla schedulazione reale
    // (visto in produzione: lista admin mostrava una data vecchia, l'edit
    // quella nuova). Una schedulazione presente è la fonte di verità su
    // "quando" indipendentemente da published — spostata fuori dal check.
    if (!empty($data['scheduled_publish_at'])) {
        $publishedAt = $data['scheduled_publish_at'];
    } elseif (!empty($data['published'])) {
        $existingScheduled = $existing['scheduled_publish_at'] ?? null;
        $wasLiveWithoutFutureSchedule = $existing !== null
            && !empty($existing['published'])
            && (empty($existingScheduled) || $existingScheduled <= $now);
        if (!$wasLiveWithoutFutureSchedule) {
            $publishedAt = $now;
        }
    }

    // GTA-BLOG-018 — a differenza degli altri campi (sempre sovrascritti con
    // quel che passa il chiamante), article_code segue la regola "assente =
    // non toccare": un chiamante che non conosce il campo (es. l'agente
    // Paperclip che aggiorna solo i contenuti di un articolo già codificato
    // da admin) non deve poterlo azzerare per omissione. Solo un chiamante
    // che passa ESPLICITAMENTE la chiave (anche null/vuota, per svuotarlo —
    // vedi admin/article-edit.php) può cambiarlo.
    $articleCode = array_key_exists('article_code', $data)
        ? (($data['article_code'] !== null && trim((string) $data['article_code']) !== '')
            ? trim((string) $data['article_code'])
            : null)
        : ($existing['article_code'] ?? null);

    $stmt = $pdo->prepare('INSERT INTO articles
        (slug, title, intro, body_html, primary_image, meta_description, og_image, keywords, published, scheduled_publish_at, published_at, article_code, created_at, updated_at)
        VALUES (:slug, :title, :intro, :body_html, :primary_image, :meta_description, :og_image, :keywords, :published, :scheduled_publish_at, :published_at, :article_code, :created_at, :updated_at)
        ON CONFLICT(slug) DO UPDATE SET
            title = excluded.title,
            intro = excluded.intro,
            body_html = excluded.body_html,
            primary_image = excluded.primary_image,
            meta_description = excluded.meta_description,
            og_image = excluded.og_image,
            keywords = excluded.keywords,
            published = excluded.published,
            scheduled_publish_at = excluded.scheduled_publish_at,
            published_at = excluded.published_at,
            article_code = excluded.article_code,
            updated_at = excluded.updated_at,
            deleted_at = NULL');

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
        // GTA-BLOG-014: chiave opzionale — i caller che non la impostano
        // (nessuna modifica sotto GTA-BLOG-014) continuano a salvare NULL,
        // comportamento identico a prima.
        ':scheduled_publish_at' => $data['scheduled_publish_at'] ?? null,
        ':published_at' => $publishedAt,
        ':article_code' => $articleCode,
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

// GTA-BLOG-HARDDELETE-001 — cancellazione REALE (DELETE, non deleted_at),
// SOLO per il bottone "Elimina" del pannello admin (admin/articles.php) —
// richiesta esplicita di Gabriele: quel bottone deve rimuovere la riga per
// sempre, non lasciarla recuperabile. Il tool MCP delete_article (agente
// Paperclip) e il ramo di admin/article-edit.php che soft-cancella il vecchio
// slug durante un rename restano su gta_blog_delete() sopra, invariati — la
// rete di sicurezza contro uno slug allucinato/sbagliato dall'agente non è
// in scope di questa richiesta.
function gta_blog_delete_permanent(PDO $pdo, string $slug): bool
{
    $stmt = $pdo->prepare('DELETE FROM articles WHERE slug = :slug');
    $stmt->execute([':slug' => $slug]);
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

    // GTA-BLOG-SEO-001: $dom->save() ritorna false su fallimento (es. il file
    // esiste già ma non è scrivibile dall'utente PHP-FPM — capita se è stato
    // toccato una volta via FTP/SSH con owner diverso) invece di lanciare
    // un'eccezione. Prima non veniva controllato: la sitemap restava ferma
    // silenziosamente, nessun errore visibile finché qualcuno non notava che
    // Search Console non trovava gli articoli nuovi. Stesso pattern di
    // silent-failure già noto su GTA-BLOG-IMG-004 (ri-hosting immagini).
    if ($dom->save($path) === false) {
        error_log('gta_blog_update_sitemap: scrittura fallita su ' . $path . ' — verificare permessi/owner del file sul server.');
    }
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
        // GTA-BLOG-014: "raggiungibile" ora significa is_live (published +
        // data raggiunta), non più solo published — un articolo approvato ma
        // schedulato nel futuro non deve avere una pagina statica raggiungibile
        // prima della data, esattamente come una bozza.
        if (gta_blog_is_live($affectedArticle)) {
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
    // da questa chiamata è effettivamente live ora (non su ogni rigenerazione
    // generica, non su delete/restore-a-bozza, non su un articolo ancora
    // schedulato nel futuro).
    if ($affectedArticle !== null && gta_blog_is_live($affectedArticle)) {
        gta_blog_ping_search_engines($config);
    }
}

// GTA-BLOG-014 — il sito è statico e non esiste nessun cron sull'hosting:
// questo è il meccanismo di "risveglio" al posto di un job schedulato.
// Ogni volta che blog.php, il pannello admin o l'endpoint MCP ricevono una
// richiesta (autenticata), controlliamo se qualche articolo già approvato
// (published=1) e schedulato ha superato la sua data senza che la pagina
// statica sia mai stata materializzata, e in quel caso la generiamo adesso.
// Costo per richiesta: una query indicizzabile su una tabella piccola, più
// il lavoro vero e proprio SOLO per gli articoli effettivamente scaduti (il
// caso comune — nessun articolo scaduto in questo momento — costa una sola
// query vuota). Latenza accettata fino alla prossima richiesta, come da
// spec del task (nessun cron reale disponibile su questo hosting).
function gta_blog_publish_due(PDO $pdo, array $config): void
{
    $stmt = $pdo->prepare("SELECT * FROM articles
        WHERE published = 1 AND deleted_at IS NULL
          AND scheduled_publish_at IS NOT NULL
          AND scheduled_publish_at <= :now");
    $stmt->execute([':now' => gmdate('c')]);
    $due = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($due as $article) {
        $path = $config['site_root'] . '/blog/' . $article['slug'] . '.html';
        if (!file_exists($path)) {
            gta_blog_regenerate($pdo, $config, $article);
        }
    }
}
