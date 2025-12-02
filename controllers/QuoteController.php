<?php
// controllers/QuoteController.php (v16 - REVISÃO GERAL DO GET E OTIMIZAÇÃO DE SUBQUERY)

class QuoteController extends BaseController {
    public function __construct(PDO $pdo) {
        parent::__construct($pdo, 'quotes', 'Orçamento');
    }

    public function handleRequest($method, $id, $input, $resource) {
        switch ($method) {
            case 'GET':
                $this->getQuotes();
                break;
            case 'POST':
            case 'PUT':
                $this->saveQuote($id, $input);
                break;
            case 'DELETE':
                $this->deleteQuote($id);
                break;
        }
    }
    
    private function getQuotes() {
        if (!isset($_SESSION['user_id'])) { 
            http_response_code(401); 
            echo json_encode(["error" => "Usuário não autenticado."]); 
            exit;
        }
        $companyId = $_SESSION['user_id'];
        
        // Query principal com filtro de segurança e clareza de colunas
        $sql = "SELECT 
            q.id, q.client_id, q.title, q.description, q.status, q.execution_date, 
            q.validity_days, q.discount_percent, q.down_payment_percent, q.installments_count, 
            q.proposal_obs, q.company_id, q.created_at
            FROM quotes q 
            WHERE q.company_id = :companyId OR q.company_id IS NULL
            ORDER BY q.created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':companyId', $companyId);
        $stmt->execute();
        
        $quotes = [];
        
        // CORREÇÃO v16: Prepara a subquery de serviços APENAS UMA VEZ para estabilidade e performance
        $stmtServices = $this->pdo->prepare("SELECT * FROM quote_services WHERE quote_id=?");

        while ($q = $stmt->fetch()) {
            $qid = $q['id'];
            
            // Executa a subquery com prepared statement
            $stmtServices->execute([$qid]);
            $services = $stmtServices->fetchAll();
            
            $servicesMapped = array_map(function($s){ return ['id'=>$s['id'], 'serviceId'=>$s['service_id'], 'description'=>$s['description'], 'quantity'=>(float)$s['quantity'], 'unitValue'=>(float)$s['unit_value'], 'totalValue'=>(float)$s['total_value']]; }, $services);
            
            $quotes[] = [
                'id' => (string)$q['id'],
                'clientId' => (string)$q['client_id'],
                'title' => $q['title'],
                'description' => $q['description'],
                'status' => $q['status'],
                'executionDate' => isset($q['execution_date']) ? $q['execution_date'] : null,
                'serviceItems' => $servicesMapped,
                'validityDays' => (int)($q['validity_days'] ?? 15),
                'discountPercent' => (float)($q['discount_percent'] ?? 10.00),
                'downPaymentPercent' => (float)($q['down_payment_percent'] ?? 40.00),
                'installmentsCount' => (int)($q['installments_count'] ?? 12),
                'proposalObs' => $q['proposal_obs'] ?? '',
                'companyId' => (string)$q['company_id'], 
            ];
        }
        echo json_encode($quotes);
    }
    
    private function saveQuote($id, $input) {
        if (!isset($_SESSION['user_id'])) { 
            http_response_code(401); 
            echo json_encode(["error" => "Usuário não autenticado."]); 
            exit;
        }
        $companyId = $_SESSION['user_id'];

        $this->pdo->beginTransaction();
        try {
            $d = $input;
            $qid = $id;
            
            $execDate = isset($d['executionDate']) ? $d['executionDate'] : null;
            $status = isset($d['status']) ? $d['status'] : 'Rascunho';
            $clientId = isset($d['clientId']) ? $d['clientId'] : null;
            $title = isset($d['title']) ? $d['title'] : '';
            $description = isset($d['description']) ? $d['description'] : '';
            
            $validityDays = isset($d['validityDays']) ? (int)$d['validityDays'] : 15;
            $discountPercent = isset($d['discountPercent']) ? (float)$d['discountPercent'] : 10.00;
            $downPaymentPercent = isset($d['downPaymentPercent']) ? (float)$d['downPaymentPercent'] : 40.00;
            $installmentsCount = isset($d['installmentsCount']) ? (int)$d['installmentsCount'] : 12;
            $proposalObs = isset($d['proposalObs']) ? $d['proposalObs'] : '';


            if (!$qid) {
                $sql = "INSERT INTO quotes (client_id, title, description, status, execution_date, validity_days, discount_percent, down_payment_percent, installments_count, proposal_obs, company_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    $clientId, $title, $description, $status, $execDate, 
                    $validityDays, $discountPercent, $downPaymentPercent, $installmentsCount, $proposalObs,
                    $companyId
                ]);
                $qid = $this->pdo->lastInsertId();
            } else {
                if (!isset($d['serviceItems'])) {
                    $stmt_current = $this->pdo->prepare("SELECT * FROM quotes WHERE id=?");
                    $stmt_current->execute([$qid]);
                    $current = $stmt_current->fetch();

                    $clientId = isset($d['clientId']) ? $d['clientId'] : $current['client_id'];
                    $title = isset($d['title']) ? $d['title'] : $current['title'];
                    $description = isset($d['description']) ? $d['description'] : $current['description'];
                    $execDate = isset($d['executionDate']) ? $d['executionDate'] : $current['execution_date'];
                    $status = isset($d['status']) ? $d['status'] : $current['status'];
                    
                    $validityDays = isset($d['validityDays']) ? (int)$d['validityDays'] : (int)($current['validity_days'] ?? 15);
                    $discountPercent = isset($d['discountPercent']) ? (float)$d['discountPercent'] : (float)($current['discount_percent'] ?? 10.00);
                    $downPaymentPercent = isset($d['downPaymentPercent']) ? (float)$d['downPaymentPercent'] : (float)($current['down_payment_percent'] ?? 40.00);
                    $installmentsCount = isset($d['installmentsCount']) ? (int)$d['installmentsCount'] : (int)($current['installments_count'] ?? 12);
                    $proposalObs = isset($d['proposalObs']) ? $d['proposalObs'] : ($current['proposal_obs'] ?? '');
                }

                $sql = "UPDATE quotes SET client_id=?, title=?, description=?, status=?, execution_date=?, validity_days=?, discount_percent=?, down_payment_percent=?, installments_count=?, proposal_obs=? WHERE id=?";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    $clientId, $title, $description, $status, $execDate, 
                    $validityDays, $discountPercent, $downPaymentPercent, $installmentsCount, $proposalObs,
                    $qid
                ]);

                if (isset($d['serviceItems'])) {
                    $this->pdo->prepare("DELETE FROM quote_services WHERE quote_id=?")->execute([$qid]);
                }
            }
            
            if (isset($d['serviceItems'])) {
                $stmtS = $this->pdo->prepare("INSERT INTO quote_services (quote_id, service_id, description, quantity, unit_value, total_value) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($d['serviceItems'] as $i) {
                    $sid = (!empty($i['serviceId']) && $i['serviceId']!=0) ? $i['serviceId'] : null;
                    $stmtS->execute([$qid, $sid, $i['description'], (float)$i['quantity'], (float)$i['unitValue'], (float)$i['totalValue']]);
                }
            }
            
            $this->pdo->commit();
            echo json_encode(["id" => (string)$qid, "message" => "Orçamento salvo"]);
        } catch (Exception $e) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            http_response_code(500); echo json_encode(["error" => $e->getMessage()]);
        }
    }
    
    private function deleteQuote($id) {
        if (!$id) { http_response_code(400); echo json_encode(["error" => "ID necessário"]); return; }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM quote_services WHERE quote_id=?")->execute([$id]);
            $this->pdo->prepare("DELETE FROM quotes WHERE id=?")->execute([$id]);
            $this->pdo->commit();
            echo json_encode(["message" => "Orçamento Excluído"]);
        } catch (Exception $e) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            http_response_code(500); echo json_encode(["error" => $e->getMessage()]);
        }
    }
}
?>