<?php
// auth_config.php - CONFIGURAÇÃO DE AUTENTICAÇÃO (v11)

// SEGURANÇA: Impede acesso direto ao arquivo
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    http_response_code(403);
    die('Acesso negado.');
}

// Chave Secreta para Assinar o JWT (IMPORTANTE: Mude esta chave em produção!)
define('JWT_SECRET', 'c2d54373a2cc459df08b457ee237a3745ca438a549f0d586f55a84a5e9d23743');

// Algoritmo de Criptografia do JWT
define('JWT_ALGORITHM', 'HS256');

// Tempo de Expiração do Token (em segundos) - 1 dia
define('JWT_EXPIRATION', 86400); 

// Emitente
define('JWT_ISSUER', 'ThreeLuneApp');
?>