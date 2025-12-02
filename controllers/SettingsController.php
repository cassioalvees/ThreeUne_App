<?php
// controllers/SettingsController.php (v12 - ADIÇÃO de CAMPOS de CABEÇALHO)

class SettingsController extends BaseController {
    public function __construct(PDO $pdo) {
        parent::__construct($pdo, 'app_settings', 'Configuração');
    }

    public function handleRequest($method, $id, $input, $resource) {
        switch ($method) {
            case 'GET':
                // Retorna todas as configurações
                $stmt = $this->pdo->query("SELECT key_name, key_value FROM app_settings");
                $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                echo json_encode($settings);
                break;
            case 'POST':
            case 'PUT':
                $this->saveSettings($input);
                break;
        }
    }
    
    private function saveSettings($input) {
        if (empty($input)) {
            http_response_code(400); echo json_encode(["error" => "Dados de configuração ausentes"]);
            exit;
        }
        
        $this->pdo->beginTransaction();
        try {
            // Remove proposalObs antes de salvar, pois foi movido para o QuoteController
            if (isset($input['proposalObs'])) {
                unset($input['proposalObs']);
            }
            
            foreach ($input as $key => $value) {
                $stmt = $this->pdo->prepare("INSERT INTO app_settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
            $this->pdo->commit();
            echo json_encode(["message" => "Configurações salvas com sucesso"]);
        } catch (Exception $e) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            http_response_code(500); echo json_encode(["error" => $e->getMessage()]);
        }
    }
}
?>