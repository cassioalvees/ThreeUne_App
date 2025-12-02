<?php
// controllers/AuthController.php (v1)

class AuthController {
    protected $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function handleRequest($method, $input) {
        $action = isset($_GET['action']) ? $_GET['action'] : null;

        switch ($action) {
            case 'login':
                if ($method === 'POST') $this->login($input);
                break;
            case 'register':
                if ($method === 'POST') $this->register($input);
                break;
            case 'logout':
                $this->logout();
                break;
            case 'check':
                $this->checkAuth();
                break;
            default:
                http_response_code(400);
                echo json_encode(["error" => "Ação inválida para autenticação"]);
                break;
        }
    }

    private function login($input) {
        if (empty($input['email']) || empty($input['password'])) {
            http_response_code(400);
            echo json_encode(["error" => "Email e senha são obrigatórios"]);
            return;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$input['email']]);
        $user = $stmt->fetch();

        if ($user && password_verify($input['password'], $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_company'] = $user['company_name'];
            
            echo json_encode([
                "message" => "Login realizado com sucesso",
                "user" => [
                    "id" => $user['id'],
                    "name" => $user['name'],
                    "company" => $user['company_name']
                ]
            ]);
        } else {
            http_response_code(401);
            echo json_encode(["error" => "Credenciais inválidas"]);
        }
    }

    private function register($input) {
        if (empty($input['company']) || empty($input['name']) || empty($input['email']) || empty($input['password'])) {
            http_response_code(400);
            echo json_encode(["error" => "Todos os campos são obrigatórios"]);
            return;
        }

        // Verifica se email já existe
        $stmtCheck = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmtCheck->execute([$input['email']]);
        if ($stmtCheck->fetch()) {
            http_response_code(409);
            echo json_encode(["error" => "Este email já está cadastrado"]);
            return;
        }

        $hash = password_hash($input['password'], PASSWORD_DEFAULT);

        try {
            $stmt = $this->pdo->prepare("INSERT INTO users (company_name, name, email, password, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$input['company'], $input['name'], $input['email'], $hash]);
            
            $userId = $this->pdo->lastInsertId();

            // Loga o usuário automaticamente após cadastro
            $_SESSION['user_id'] = $userId;
            $_SESSION['user_name'] = $input['name'];
            $_SESSION['user_company'] = $input['company'];

            echo json_encode(["message" => "Cadastro realizado com sucesso", "userId" => $userId]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Erro ao cadastrar: " . $e->getMessage()]);
        }
    }

    private function logout() {
        session_destroy();
        echo json_encode(["message" => "Logout realizado"]);
    }

    private function checkAuth() {
        if (isset($_SESSION['user_id'])) {
            echo json_encode([
                "authenticated" => true,
                "user" => [
                    "id" => $_SESSION['user_id'],
                    "name" => $_SESSION['user_name'],
                    "company" => $_SESSION['user_company']
                ]
            ]);
        } else {
            http_response_code(401);
            echo json_encode(["authenticated" => false]);
        }
    }
}
?>
