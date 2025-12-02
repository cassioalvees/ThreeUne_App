<?php
// controllers/BaseController.php - CLASSE BASE (v10)

abstract class BaseController {
    protected $pdo;
    protected $table;
    protected $resourceName;

    public function __construct(PDO $pdo, $table = null, $resourceName = null) {
        $this->pdo = $pdo;
        $this->table = $table;
        $this->resourceName = $resourceName;
    }

    abstract public function handleRequest($method, $id, $input, $resource);

    // Método DELETE genérico, com tratamento de erro 23000 (Foreign Key Constraint)
    protected function delete($id) {
        if (!$id) { http_response_code(400); echo json_encode(["error" => "ID necessário"]); return; }
        
        try {
            $this->pdo->prepare("DELETE FROM {$this->table} WHERE id=?")->execute([$id]);
            echo json_encode(["message" => "Excluído"]);
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                http_response_code(400);
                echo json_encode(["error" => "Não é possível excluir este {$this->resourceName} pois ele possui itens relacionados."]);
            } else {
                throw $e;
            }
        }
    }
}
?>