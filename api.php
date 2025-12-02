<?php
// api.php - ROTEADOR CENTRAL E FUNÇÕES GLOBAIS (v17 - ADIÇÃO de ROTA para delete_file)

// Inicia Sessão PHP para Autenticação
session_start();

// Configurações de Erro
error_reporting(E_ALL);
ini_set('display_errors', 0); 

header('Content-Type: application/json; charset=utf-8');

// CORREÇÃO CORS: Para aceitar credenciais (cookies), a Origin não pode ser '*'
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true'); // Permite cookies de sessão

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Garante que o arquivo de configuração do banco exista
if (!file_exists('db_config.php')) {
    http_response_code(500);
    echo json_encode(["error" => "Erro Fatal: O arquivo 'db_config.php' não foi encontrado."]);
    exit;
}
require 'db_config.php';

// Funções Globais (ACESSÍVEIS PARA TODOS OS CONTROLLERS)
function connectDB() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        return new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro de Conexão com o Banco: " . $e->getMessage()]);
        exit;
    }
}

function fetchUrl($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode == 200 && $data) return $data;
    }
    $options = ["http" => ["method" => "GET", "header" => "User-Agent: Mozilla/5.0\r\n"], "ssl" => ["verify_peer" => false]];
    $context = stream_context_create($options);
    return @file_get_contents($url, false, $context);
}

// Funções de Inicialização (Garantia de Tabelas)
function ensureUsersTable(PDO $pdo) {
    try { $pdo->query("SELECT 1 FROM users LIMIT 1"); } catch (Exception $e) {
        $sql = "CREATE TABLE users (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL
        )";
        $pdo->exec($sql);
    }
}

function ensureQuotesTable(PDO $pdo) {
    try {
        $pdo->query("SELECT 1 FROM quotes LIMIT 1");
        
        // Garante a coluna execution_date
        try { $pdo->query("SELECT execution_date FROM quotes LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE quotes ADD COLUMN execution_date DATE NULL"); }
        
        // Garante os novos campos de pagamento e observação
        try { $pdo->query("SELECT validity_days FROM quotes LIMIT 1"); } catch (Exception $e) { 
            try { $pdo->exec("ALTER TABLE quotes ADD COLUMN validity_days INT(11) NOT NULL DEFAULT 15"); } catch(Exception $ex) {} 
        }
        try { $pdo->query("SELECT discount_percent FROM quotes LIMIT 1"); } catch (Exception $e) { 
            try { $pdo->exec("ALTER TABLE quotes ADD COLUMN discount_percent DECIMAL(5, 2) NOT NULL DEFAULT 10.00"); } catch(Exception $ex) {} 
        }
        try { $pdo->query("SELECT down_payment_percent FROM quotes LIMIT 1"); } catch (Exception $e) { 
            try { $pdo->exec("ALTER TABLE quotes ADD COLUMN down_payment_percent DECIMAL(5, 2) NOT NULL DEFAULT 40.00"); } catch(Exception $ex) {} 
        }
        try { $pdo->query("SELECT installments_count FROM quotes LIMIT 1"); } catch (Exception $e) { 
            try { $pdo->exec("ALTER TABLE quotes ADD COLUMN installments_count INT(11) NOT NULL DEFAULT 12"); } catch(Exception $ex) {} 
        }
        try { $pdo->query("SELECT proposal_obs FROM quotes LIMIT 1"); } catch (Exception $e) { 
            try { $pdo->exec("ALTER TABLE quotes ADD COLUMN proposal_obs TEXT NULL"); } catch(Exception $ex) {} 
        }
        
        // CORREÇÃO v15: Garante a coluna company_id (que faltava na migração e é usada no QuoteController)
        try { $pdo->query("SELECT company_id FROM quotes LIMIT 1"); } catch (Exception $e) { 
            try { $pdo->exec("ALTER TABLE quotes ADD COLUMN company_id INT(11) NULL AFTER client_id"); } catch(Exception $ex) {} 
        }
        
    } catch (Exception $e) {
        // Bloco de criação da tabela (se a tabela quotes não existir)
        $sql = "
            CREATE TABLE quotes (
                id INT(11) AUTO_INCREMENT PRIMARY KEY,
                client_id INT(11) NOT NULL,
                company_id INT(11) NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'Rascunho',
                execution_date DATE NULL,
                validity_days INT(11) NOT NULL DEFAULT 15,
                discount_percent DECIMAL(5, 2) NOT NULL DEFAULT 10.00,
                down_payment_percent DECIMAL(5, 2) NOT NULL DEFAULT 40.00,
                installments_count INT(11) NOT NULL DEFAULT 12,
                proposal_obs TEXT NULL,
                created_at DATETIME NOT NULL,
                FOREIGN KEY (client_id) REFERENCES clients(id)
            );
            CREATE TABLE quote_services (
                id INT(11) AUTO_INCREMENT PRIMARY KEY,
                quote_id INT(11) NOT NULL,
                service_id INT(11) NULL,
                description VARCHAR(255) NOT NULL,
                quantity DECIMAL(10, 2) NOT NULL,
                unit_value DECIMAL(10, 2) NOT NULL,
                total_value DECIMAL(10, 2) NOT NULL,
                FOREIGN KEY (quote_id) REFERENCES quotes(id),
                FOREIGN KEY (service_id) REFERENCES services_catalog(id)
            );
        ";
        $pdo->exec($sql);
    }
}


function ensureGroupedPaymentsTable(PDO $pdo) {
    try {
        $pdo->query("SELECT 1 FROM grouped_payments LIMIT 1");
        try { $pdo->query("SELECT grouped_payment_id FROM projects LIMIT 1"); } catch (Exception $e) {
            $pdo->exec("ALTER TABLE projects ADD COLUMN grouped_payment_id INT(11) NULL");
            $pdo->exec("ALTER TABLE projects ADD CONSTRAINT fk_projects_grouped_payment FOREIGN KEY (grouped_payment_id) REFERENCES grouped_payments(id) ON DELETE SET NULL");
        }
    } catch (Exception $e) {
        $sql = "
            CREATE TABLE grouped_payments (
                id INT(11) AUTO_INCREMENT PRIMARY KEY,
                client_id INT(11) NOT NULL,
                amount DECIMAL(10, 2) NOT NULL,
                date DATE NOT NULL,
                method VARCHAR(50) NOT NULL,
                description VARCHAR(255) NULL,
                invoice_file VARCHAR(255) NULL,
                receipt_link VARCHAR(255) NULL,
                group_name VARCHAR(255) NULL, 
                created_at DATETIME NOT NULL
            );
            CREATE TABLE grouped_payment_projects (
                grouped_payment_id INT(11) NOT NULL,
                project_id INT(11) NOT NULL,
                PRIMARY KEY (grouped_payment_id, project_id),
                FOREIGN KEY (grouped_payment_id) REFERENCES grouped_payments(id) ON DELETE CASCADE,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            );
            ALTER TABLE projects ADD COLUMN grouped_payment_id INT(11) NULL;
            ALTER TABLE projects ADD CONSTRAINT fk_projects_grouped_payment FOREIGN KEY (grouped_payment_id) REFERENCES grouped_payments(id) ON DELETE SET NULL;
        ";
        $pdo->exec($sql);
    }
    try { $pdo->query("SELECT group_name FROM grouped_payments LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE grouped_payments ADD COLUMN group_name VARCHAR(255) NULL"); }
}

function ensureSettingsTable(PDO $pdo) {
    try { $pdo->query("SELECT 1 FROM app_settings LIMIT 1"); } catch (Exception $e) {
        $pdo->exec("CREATE TABLE app_settings (key_name VARCHAR(50) PRIMARY KEY, key_value TEXT)");
    }
    // CORREÇÃO v11: Remove o campo proposalObs das configurações se existir (agora é por orçamento)
    try { $pdo->query("SELECT key_value FROM app_settings WHERE key_name='proposalObs' LIMIT 1"); $pdo->exec("DELETE FROM app_settings WHERE key_name='proposalObs'"); } catch(Exception $e) {}
}

function ensureProjectInvoices(PDO $pdo) {
    try { $pdo->query("SELECT invoice_file FROM projects LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE projects ADD COLUMN invoice_file VARCHAR(255) NULL"); } catch(Exception $ex) {} }
}

function ensurePaymentDescriptions(PDO $pdo) {
    try { $pdo->query("SELECT description FROM client_payments LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE client_payments ADD COLUMN description VARCHAR(255) NULL"); } catch(Exception $ex) {} }
    try { $pdo->query("SELECT description FROM operational_payments LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE operational_payments ADD COLUMN description VARCHAR(255) NULL"); } catch(Exception $ex) {} }
}


// Lógica Principal do Roteador
try {
    $pdo = connectDB();
    
    // Garante estrutura atualizada
    ensureUsersTable($pdo);
    ensureQuotesTable($pdo);
    ensureSettingsTable($pdo);
    ensureGroupedPaymentsTable($pdo);
    ensureProjectInvoices($pdo);
    ensurePaymentDescriptions($pdo);
    
    // Cria pasta controllers se não existir
    if (!is_dir('controllers')) { mkdir('controllers', 0777, true); }
    
    // INCLUSÃO DOS ARQUIVOS DE CONTROLLER
    require_once 'controllers/AuthController.php'; 
    require_once 'controllers/BaseController.php';
    require_once 'controllers/ClientController.php';
    require_once 'controllers/SupplierController.php';
    require_once 'controllers/ServiceController.php';
    require_once 'controllers/ProjectController.php';
    require_once 'controllers/QuoteController.php';
    require_once 'controllers/PaymentController.php';
    require_once 'controllers/SettingsController.php';
    require_once 'controllers/UtilController.php';
    
    $method = $_SERVER['REQUEST_METHOD'];
    $input = null;
    if ($method !== 'GET' && empty($_FILES)) {
        $input = json_decode(file_get_contents('php://input'), true);
    } else if ($method === 'POST' && !empty($_POST)) {
        $input = $_POST; 
    }
    
    $resource = isset($_GET['resource']) ? $_GET['resource'] : null;
    $id = isset($_GET['id']) ? $_GET['id'] : null;

    if (!$resource) { echo json_encode(["status" => "API Ready"]); exit; }
    
    // ----------------------------------------------------
    // PROTEÇÃO DE ROTAS (MIDDLEWARE SIMPLES)
    // ----------------------------------------------------
    // Se não for rota de 'auth', exige login na sessão
    if ($resource !== 'auth' && !isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(["error" => "Não autorizado. Faça login."]);
        exit;
    }
    
    $controller = null;

    switch ($resource) {
        case 'auth':
            $controller = new AuthController($pdo);
            $controller->handleRequest($method, $input);
            exit; 
        case 'clients':
            $controller = new ClientController($pdo);
            break;
        case 'suppliers':
            $controller = new SupplierController($pdo);
            break;
        case 'services':
            $controller = new ServiceController($pdo);
            break;
        case 'projects':
            $controller = new ProjectController($pdo);
            break;
        case 'quotes':
            $controller = new QuoteController($pdo);
            break;
        case 'payments':
        case 'payments_group':
            $controller = new PaymentController($pdo);
            break;
        case 'settings':
            $controller = new SettingsController($pdo);
            break;
        case 'upload':
        case 'sync_calendar':
        case 'convert_quote':
        case 'delete_file': // NOVO: Rota de exclusão de arquivo
            $controller = new UtilController($pdo);
            break;
        default:
            http_response_code(404); echo json_encode(["error" => "Rota não encontrada"]); exit;
    }
    
    $controller->handleRequest($method, $id, $input, $resource);

} catch (Exception $e) {
    http_response_code(500); echo json_encode(["error" => $e->getMessage()]);
}
?>