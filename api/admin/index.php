<?php

declare(strict_types=1);

// Nessuna dashboard dedicata per ora — un solo modulo (blog) esiste oggi,
// quando ne arriverà un secondo questa pagina diventerà lo shell/menu reale.
require_once __DIR__ . '/admin-common.php';
admin_require_login();
header('Location: articles.php');
