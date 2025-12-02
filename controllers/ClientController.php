<?php
// controllers/ClientController.php (v10)

class ClientController extends BaseController {
    public function __construct(PDO $pdo) {
        parent::__construct($pdo, 'clients', 'Cliente');
    }

    public function handleRequest($method, $id, $input, $resource) {
        switch ($method) {
            case 'GET':
                $stmt = $this->pdo->query("SELECT * FROM clients ORDER BY name ASC");
                echo json_encode($stmt->fetchAll());
                break;
            case 'POST':
                $this->post($input);
                break;
            case 'PUT':
                $this->put($id, $input);
                break;
            case 'DELETE':
                $this->delete($id);
                break;
        }
    }
    
    protected function post($input) {
        $stmt = $this->pdo->prepare("INSERT INTO clients (name, email, phone, document, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$input['name'], $input['email'], $input['phone'], $input['document']]);
        echo json_encode(["message" => "Criado", "id" => $this->pdo->lastInsertId()]);
    }

    protected function put($id, $input) {
        if (!$id) { http_response_code(400); echo json_encode(["error" => "ID necessário"]); return; }
        $this->pdo->prepare("UPDATE clients SET name=?, email=?, phone=?, document=? WHERE id=?")->execute([$input['name'], $input['email'], $input['phone'], $input['document'], $id]);
        echo json_encode(["message" => "Atualizado"]);
    }
}
?>