<?php
// Copiare questo file in blog-config.php sullo stesso server (MAI in git) e
// sostituire auth_token con un valore generato casualmente, es:
//   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
//
// GTA-BLOG-001: adattato dal motore blog di AI Workspace Club (awc_website),
// stesso contratto payload — vedi briefs/gtaviani_awaoffer/aiagents-blog-api-contract.md
// nel repo pm-websites per il documento di riferimento completo.

return [
    'auth_token' => 'CHANGE_ME_TOKEN',
    // Dentro api/, in una sotto-cartella dedicata (mai in assets/images, già
    // piena di asset statici tracciati) — vedi .gitignore, mai committato.
    'db_path' => __DIR__ . '/blog-data/blog.sqlite',
    'site_root' => dirname(__DIR__),
    'site_url' => 'https://aiagents.gtaviani.com',
];
