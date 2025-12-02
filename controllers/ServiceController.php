<?php
// controllers/ServiceController.php (v10)

class ServiceController extends BaseController {
    public function __construct(PDO $pdo) {
        parent::__construct($pdo, 'services_catalog', 'Item de Catálogo');
    }

    public function handleRequest($method, $id, $input, $resource) {
        switch ($method) {
            case 'GET':
                $stmt = $this->pdo->query("SELECT * FROM services_catalog ORDER BY description ASC");
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
        $stmt = $this->pdo->prepare("INSERT INTO services_catalog (description, type, unit_value, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$input['description'], $input['type'], (float)$input['unitValue']]);
        echo json_encode(["message" => "Criado"]);
    }

    protected function put($id, $input) {
        if (!$id) { http_response_code(400); echo json_encode(["error" => "ID necessário"]); return; }
        $this->pdo->prepare("UPDATE services_catalog SET description=?, type=?, unit_value=? WHERE id=?")->execute([$input['description'], $input['type'], (float)$input['unitValue'], $id]);
        echo json_encode(["message" => "Atualizado"]);
    }
    
    protected function delete($id) {
        if (!$id) { http_response_code(400); echo json_encode(["error" => "ID necessário"]); return; }
        try {
            $this->pdo->prepare("DELETE FROM services_catalog WHERE id=?")->execute([$id]);
            echo json_encode(["message" => "Excluído"]);
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                http_response_code(400);
                echo json_encode(["error" => "Erro ao excluir: Item em uso."]);
            } else {
                throw $e;
            }
        }
    }
}
?>