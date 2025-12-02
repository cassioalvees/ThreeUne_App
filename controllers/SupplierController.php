<?php
// controllers/SupplierController.php (v10)

class SupplierController extends BaseController {
    public function __construct(PDO $pdo) {
        parent::__construct($pdo, 'suppliers', 'Fornecedor');
    }

    public function handleRequest($method, $id, $input, $resource) {
        switch ($method) {
            case 'GET':
                $stmt = $this->pdo->query("SELECT * FROM suppliers ORDER BY name ASC");
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
        $stmt = $this->pdo->prepare("INSERT INTO suppliers (name, type, document, contact, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$input['name'], $input['type'], $input['document'], $input['contact']]);
        echo json_encode(["message" => "Criado"]);
    }

    protected function put($id, $input) {
        if (!$id) { http_response_code(400); echo json_encode(["error" => "ID necessário"]); return; }
        $this->pdo->prepare("UPDATE suppliers SET name=?, type=?, document=?, contact=? WHERE id=?")->execute([$input['name'], $input['type'], $input['document'], $input['contact'], $id]);
        echo json_encode(["message" => "Atualizado"]);
    }
    
    protected function delete($id) {
        if (!$id) { http_response_code(400); echo json_encode(["error" => "ID necessário"]); return; }
        try {
            $this->pdo->beginTransaction();
            $this->pdo->prepare("UPDATE operational_payments SET supplier_id = NULL WHERE supplier_id = ?")->execute([$id]);
            $this->pdo->prepare("DELETE FROM suppliers WHERE id=?")->execute([$id]);
            $this->pdo->commit();
            echo json_encode(["message" => "Excluído com sucesso"]);
        } catch (PDOException $e) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($e->getCode() == '23000') {
                http_response_code(400);
                echo json_encode(["error" => "Erro ao excluir: Fornecedor em uso."]);
            } else {
                http_response_code(500); echo json_encode(["error" => $e->getMessage()]);
            }
        }
    }
}
?>