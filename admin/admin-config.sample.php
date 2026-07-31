<?php
// Copiare questo file in admin-config.php sullo stesso server (MAI in git,
// vedi .gitignore).
//
// GTA-ADMIN-001: pannello admin del blog. Riusa lo stesso motore/DB di
// api/blog.php (blog-config.php, già esistente) per leggere/scrivere gli
// articoli — questo file aggiunge solo ciò che serve in più al pannello
// (bootstrap primo utente, cartella upload immagini).

return [
    // Percorso del blog-config.php esistente (stesso motore di api/blog.php)
    // — fornisce db_path/site_root/site_url, riusati anche da questo pannello.
    // La tabella admin_users vive nello STESSO file SQLite degli articoli
    // (un solo file da backuppare, niente sincronizzazione tra due DB).
    'blog_config_path' => __DIR__ . '/../api/blog-config.php',

    // Bootstrap: se la tabella admin_users è vuota, viene creato UN utente
    // con queste credenziali al primo accesso a una pagina admin. Cambiale
    // SUBITO dopo il primo login — nessuna UI di "cambio password" oggi,
    // vedi users.php per aggiungere/disattivare altri utenti.
    'seed_username' => 'admin',
    'seed_password' => 'CHANGE_ME_TOKEN',

    // Cartella dove salvare le immagini caricate dal pannello.
    'upload_dir' => dirname(__DIR__) . '/assets/images/blog/uploads',
    'upload_url_base' => '/assets/images/blog/uploads',
];
