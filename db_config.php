<?php
// db_config.php - CONFIGURAÇÃO SEGURA

// SEGURANÇA: Impede acesso direto ao arquivo via URL (navegador)
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    http_response_code(403);
    die('Acesso negado.');
}

// ATENÇÃO: Em hospedagens compartilhadas (como HostGator), 
// scripts que rodam NO MESMO SERVIDOR devem usar 'localhost'
define('DB_HOST', '69.6.249.20');
define('DB_NAME', 'hg490236_financeiro');
define('DB_USER', 'hg490236_cassio');
define('DB_PASS', '@!425361**');

define('DB_CHARSET', 'utf8mb4');
?>