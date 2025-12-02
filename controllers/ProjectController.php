<?php
// controllers/ProjectController.php (v10)

class ProjectController extends BaseController {
    public function __construct(PDO $pdo) {
        parent::__construct($pdo, 'projects', 'Projeto');
    }

    public function handleRequest($method, $id, $input, $resource) {
        switch ($method) {
            case 'GET':
                $this->getProjects();
                break;
            case 'POST':
            case 'PUT':
                $this->saveProject($id, $input);
                break;
            case 'DELETE':
                $this->deleteProject($id);
                break;
        }
    }
    
    private function getProjects() {
        $sql = "
            SELECT 
                p.*, 
                gp.amount AS grouped_payment_amount,
                gp.date AS grouped_payment_date,
                gp.method AS grouped_payment_method,
                gp.description AS grouped_payment_description,
                gp.group_name AS grouped_payment_group_name,
                gp.invoice_file AS grouped_invoice_file,
                gp.receipt_link AS grouped_receipt_link
            FROM projects p
            LEFT JOIN grouped_payments gp ON p.grouped_payment_id = gp.id
            ORDER BY p.project_date DESC, p.created_at DESC
        ";
        $stmt = $this->pdo->query($sql);
        $projects = [];
        while ($p = $stmt->fetch()) {
            $pid = $p['id'];
            
            // Itens de Serviço
            $services = $this->pdo->query("SELECT * FROM project_services WHERE project_id=$pid")->fetchAll();
            $servicesMapped = array_map(function($s){ return ['id'=>$s['id'], 'serviceId'=>$s['service_id'], 'description'=>$s['description'], 'quantity'=>(float)$s['quantity'], 'unitValue'=>(float)$s['unit_value'], 'totalValue'=>(float)$s['total_value']]; }, $services);
            
            // Pagamentos do Cliente (Ignorado se for Consolidado)
            if ($p['grouped_payment_id']) {
                $cPayMapped = []; 
            } else {
                $cPay = $this->pdo->query("SELECT * FROM client_payments WHERE project_id=$pid")->fetchAll();
                $cPayMapped = array_map(function($c){ return [
                    'id'=>$c['id'], 
                    'amount'=>(float)$c['amount'], 
                    'date'=>$c['date'], 
                    'method'=>$c['method'], 
                    'description'=>$c['description'] ?? '', 
                    'installments'=>$c['installments'], 
                    'receiptLink'=>$c['receipt_link']
                ]; }, $cPay);
            }
            
            // Pagamentos Operacionais
            $oPay = $this->pdo->query("SELECT * FROM operational_payments WHERE project_id=$pid")->fetchAll();
            $oPayMapped = array_map(function($o){ return [
                'id'=>$o['id'], 
                'supplierId'=>$o['supplier_id'], 
                'amount'=>(float)$o['amount'], 
                'date'=>$o['date'], 
                'method'=>$o['method'], 
                'description'=>$o['description'] ?? '',
                'receiptLink'=>$o['receipt_link']
            ]; }, $oPay);
            
            // Dados de Pagamento Consolidado, se houver
            $groupedPayment = null;
            if ($p['grouped_payment_id']) {
                 $groupedPayment = [
                     'id' => (string)$p['grouped_payment_id'],
                     'amount' => (float)$p['grouped_payment_amount'],
                     'date' => $p['grouped_payment_date'],
                     'method' => $p['grouped_payment_method'],
                     'groupName' => $p['grouped_payment_group_name'] ?? 'Pagamento Consolidado',
                     'description' => $p['grouped_payment_description'] ?? '',
                     'invoiceFile' => $p['grouped_invoice_file'],
                     'receiptLink' => $p['grouped_receipt_link'],
                     'clientId' => (string)$p['client_id'],
                 ];
            }
            
            $projects[] = [
                'id' => (string)$p['id'],
                'clientId' => (string)$p['client_id'],
                'title' => $p['title'],
                'description' => $p['description'],
                'status' => $p['status'],
                'requiresInvoice' => (bool)$p['requires_invoice'], 
                'invoiceIssued' => (bool)$p['invoice_issued'], 
                'invoiceFile' => isset($p['invoice_file']) ? $p['invoice_file'] : null,
                'projectDate' => $p['project_date'] ? $p['project_date'] : substr($p['created_at'], 0, 10),
                'projectType' => $p['project_type'] ? $p['project_type'] : 'Projeto Audiovisual',
                'serviceItems' => $servicesMapped,
                'clientPayments' => $cPayMapped,
                'operationalPayments' => $oPayMapped,
                'groupedPaymentId' => $p['grouped_payment_id'] ? (string)$p['grouped_payment_id'] : null,
                'groupedPayment' => $groupedPayment
            ];
        }
        echo json_encode($projects);
    }

    private function saveProject($id, $input) {
        $this->pdo->beginTransaction();
        try {
            $d = $input;
            $pid = $id;
            $pDate = !empty($d['projectDate']) ? $d['projectDate'] : date('Y-m-d');
            $pType = !empty($d['projectType']) ? $d['projectType'] : 'Projeto Audiovisual';
            $invFile = isset($d['invoiceFile']) ? $d['invoiceFile'] : null;

            if (!$pid) {
                $stmt = $this->pdo->prepare("INSERT INTO projects (client_id, title, description, status, requires_invoice, invoice_issued, invoice_file, project_date, project_type, grouped_payment_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NOW())");
                $stmt->execute([$d['clientId'], $d['title'], $d['description'], $d['status'], (int)$d['requiresInvoice'], (int)$d['invoiceIssued'], $invFile, $pDate, $pType]);
                $pid = $this->pdo->lastInsertId();
            } else {
                $stmt = $this->pdo->prepare("UPDATE projects SET client_id=?, title=?, description=?, status=?, requires_invoice=?, invoice_issued=?, invoice_file=?, project_date=?, project_type=? WHERE id=?");
                $stmt->execute([$d['clientId'], $d['title'], $d['description'], $d['status'], (int)$d['requiresInvoice'], (int)$d['invoiceIssued'], $invFile, $pDate, $pType, $pid]);
                
                if (isset($d['serviceItems'])) {
                    $this->pdo->prepare("DELETE FROM project_services WHERE project_id=?")->execute([$pid]);
                }
            }
            
            if (isset($d['serviceItems'])) {
                $stmtS = $this->pdo->prepare("INSERT INTO project_services (project_id, service_id, description, quantity, unit_value, total_value) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($d['serviceItems'] as $i) {
                    $sid = (!empty($i['serviceId']) && $i['serviceId']!=0) ? $i['serviceId'] : null;
                    $stmtS->execute([$pid, $sid, $i['description'], (float)$i['quantity'], (float)$i['unitValue'], (float)$i['totalValue']]);
                }
            }

            $this->pdo->commit();
            echo json_encode(["id" => (string)$pid, "message" => "Salvo"]);
        } catch (Exception $e) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            http_response_code(500); echo json_encode(["error" => $e->getMessage()]);
        }
    }
    
    private function deleteProject($id) {
        if (!$id) { http_response_code(400); echo json_encode(["error" => "ID necessário"]); return; }

        $this->pdo->beginTransaction();
        try {
            $stmtGroup = $this->pdo->prepare("SELECT grouped_payment_id FROM projects WHERE id=?");
            $stmtGroup->execute([$id]);
            $groupedPaymentId = $stmtGroup->fetchColumn();
            
            $this->pdo->prepare("DELETE FROM project_services WHERE project_id=?")->execute([$id]);
            $this->pdo->prepare("DELETE FROM client_payments WHERE project_id=?")->execute([$id]);
            $this->pdo->prepare("DELETE FROM operational_payments WHERE project_id=?")->execute([$id]);
            $this->pdo->prepare("DELETE FROM projects WHERE id=?")->execute([$id]);
            
            if ($groupedPaymentId) {
                $stmtCount = $this->pdo->prepare("SELECT COUNT(*) FROM grouped_payment_projects WHERE grouped_payment_id=?");
                $stmtCount->execute([$groupedPaymentId]);
                if ($stmtCount->fetchColumn() == 1) {
                     $this->pdo->prepare("DELETE FROM grouped_payments WHERE id=?")->execute([$groupedPaymentId]);
                }
            }
            
            $this->pdo->commit();
            echo json_encode(["message" => "Excluído"]);
        } catch (Exception $e) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            http_response_code(500); echo json_encode(["error" => $e->getMessage()]);
        }
    }
}
?>