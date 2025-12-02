<?php
// controllers/PaymentController.php (v7 - CORREÇÃO DE BOTOES)

class PaymentController extends BaseController {
    public function __construct(PDO $pdo) {
        parent::__construct($pdo);
    }

    public function handleRequest($method, $id, $input, $resource) {
        switch ($resource) {
            case 'payments':
                $this->handleIndividualPayment($method, $id, $input);
                break;
            case 'payments_group':
                $this->handleGroupedPayment($method, $id, $input);
                break;
        }
    }
    
    // --- Lógica de Pagamentos Individuais (clientPayments, operationalPayments) ---
    private function handleIndividualPayment($method, $id, $input) {
        $paymentId = isset($_GET['payment_id']) ?
        $_GET['payment_id'] : $id;
        
        if ($method === 'POST' && $input) {
            $t = $input['arrayName'] === 'clientPayments' ?
            'client_payments' : 'operational_payments';
            $d = $input['paymentData'];
            $pid = $input['projectId'];
            $receipt = $d['receiptLink'] ?? null;
            $desc = $d['description'] ?? '';
            if ($t === 'client_payments') {
                $stmtCheck = $this->pdo->prepare("SELECT grouped_payment_id FROM projects WHERE id=?");
                $stmtCheck->execute([$pid]);
                if ($stmtCheck->fetchColumn() !== null) {
                    http_response_code(400);
                    echo json_encode(["error" => "Este projeto foi pago em grupo e não aceita pagamentos individuais."]);
                    exit;
                }
                $this->pdo->prepare("INSERT INTO $t (project_id, amount, date, method, description, installments, receipt_link) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$pid, $d['amount'], $d['date'], $d['method'], $desc, $d['installments'], $receipt]);
            } else {
                $this->pdo->prepare("INSERT INTO $t (project_id, supplier_id, amount, date, method, description, receipt_link) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$pid, $d['supplierId'], $d['amount'], $d['date'], $d['method'], $desc, $receipt]);
            }
            echo json_encode(["message" => "Registrado"]);
        } 
        elseif ($method === 'PUT' && $input && $paymentId) {
            $t = $input['arrayName'] === 'clientPayments' ?
            'client_payments' : 'operational_payments';
            $d = $input['paymentData'];
            $desc = $d['description'] ?? '';
            $receipt = $d['receiptLink'] ?? null;
            if ($t === 'client_payments') {
                $stmtProj = $this->pdo->prepare("SELECT project_id FROM client_payments WHERE id=?");
                $stmtProj->execute([$paymentId]);
                $pid = $stmtProj->fetchColumn();
                if ($pid) {
                    $stmtCheck = $this->pdo->prepare("SELECT grouped_payment_id FROM projects WHERE id=?");
                    $stmtCheck->execute([$pid]);
                    if ($stmtCheck->fetchColumn() !== null) {
                         http_response_code(400);
                         echo json_encode(["error" => "Este projeto foi pago em grupo. O pagamento individual não pode ser editado."]);
                         exit;
                    }
                }
            }

            $sql = "";
            $params = [];
            if ($t === 'client_payments') {
                $sql = "UPDATE $t SET amount=?, date=?, method=?, description=?, installments=?";
                $params = [$d['amount'], $d['date'], $d['method'], $desc, $d['installments']];
            } else {
                $sql = "UPDATE $t SET supplier_id=?, amount=?, date=?, method=?, description=?";
                $params = [$d['supplierId'], $d['amount'], $d['date'], $d['method'], $desc];
            }

            if (array_key_exists('receiptLink', $d)) { $sql .= ", receipt_link=?";
            $params[] = $receipt; }
            $sql .= " WHERE id=?";
            $params[] = $paymentId;

            $this->pdo->prepare($sql)->execute($params);
            echo json_encode(["message" => "Atualizado com sucesso"]);
        }
        elseif ($method === 'DELETE' && isset($_GET['payment_id']) && isset($_GET['type'])) {
            $t = $_GET['type'] === 'clientPayments' ?
            'client_payments' : 'operational_payments';
            $paymentId = $_GET['payment_id'];
            
            if ($t === 'client_payments') {
                $stmtProj = $this->pdo->prepare("SELECT p.grouped_payment_id FROM client_payments cp JOIN projects p ON cp.project_id = p.id WHERE cp.id=?");
                $stmtProj->execute([$paymentId]);
                if ($stmtProj->fetchColumn() !== null) {
                     http_response_code(400);
                     echo json_encode(["error" => "Este pagamento individual não pode ser excluído pois o projeto foi pago em grupo."]);
                     exit;
                }
            }
            
            $this->pdo->prepare("DELETE FROM $t WHERE id=?")->execute([$paymentId]);
            echo json_encode(["message" => "Excluído"]);
        }
    }
    
    // --- Lógica de Pagamentos Consolidado ---
    private function handleGroupedPayment($method, $id, $input) {
        $groupedPaymentId = $id;
        if ($method === 'POST' && $input) {
            if (empty($input['projectIds']) || empty($input['amount']) || empty($input['clientId'])) {
                http_response_code(400);
                echo json_encode(["error" => "Dados obrigatórios (projetos, valor, cliente) ausentes."]); exit;
            }
            
            $this->pdo->beginTransaction();
            try {
                $d = $input;
                $ids = $d['projectIds'];
                $receipt = $d['receiptLink'] ?? null;
                $invoice = $d['invoiceFile'] ?? null;
                
                $groupName = $d['groupName'] ?? "Pagamento Consolidado";
                $optionalDescription = $d['description'] ?? ''; // CORREÇÃO 3A: Pega a descrição opcional
                
                // 1. Cria o registro de pagamento consolidado
                $stmtGroup = $this->pdo->prepare("INSERT INTO grouped_payments (client_id, amount, date, method, description, group_name, invoice_file, receipt_link, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                // CORREÇÃO 3A: Insere groupName e description separadamente
                $stmtGroup->execute([$d['clientId'], (float)$d['amount'], $d['date'], $d['method'], $optionalDescription, $groupName, $invoice, $receipt]);
                $groupedPaymentId = $this->pdo->lastInsertId();
                
                // 2. Vincula os projetos e marca eles como consolidados
                $stmtLink = $this->pdo->prepare("INSERT INTO grouped_payment_projects (grouped_payment_id, project_id) VALUES (?, ?)");
                $stmtUpdateProject = $this->pdo->prepare("UPDATE projects SET grouped_payment_id=?, status='Concluído', requires_invoice=1, invoice_issued=1, invoice_file=? WHERE id=?");
                foreach ($ids as $projectId) {
                    $stmtLink->execute([$groupedPaymentId, $projectId]);
                    $stmtUpdateProject->execute([$groupedPaymentId, $invoice, $projectId]);
                    // Deleta pagamentos individuais, se houver
                    $this->pdo->prepare("DELETE FROM client_payments WHERE project_id=?")->execute([$projectId]);
                }
                
                $this->pdo->commit();
                echo json_encode(["message" => "Pagamento Consolidado registrado!", "groupedPaymentId" => (string)$groupedPaymentId]);
            } catch (Exception $e) {
                if($this->pdo->inTransaction()) $this->pdo->rollBack();
                http_response_code(500); echo json_encode(["error" => $e->getMessage()]);
            }
        }
        elseif ($method === 'PUT' && $input && $groupedPaymentId) {
             // CORREÇÃO 3: Implementação do PUT para Edição do Agrupamento
             // CORREÇÃO 1 (v7): Removida a exigência de projectIds no PUT.
            if (empty($input['amount']) || empty($input['groupName'])) {
                http_response_code(400);
                echo json_encode(["error" => "Dados obrigatórios (valor e nome do agrupamento) ausentes."]); exit;
            }

            $this->pdo->beginTransaction();
            try {
                $d = $input;
                // 1. Obtém links de arquivo antigos para não sobrescrever se não houver novo upload
                $stmtOld = $this->pdo->prepare("SELECT invoice_file, receipt_link FROM grouped_payments WHERE id = ?");
                $stmtOld->execute([$groupedPaymentId]);
                $oldFiles = $stmtOld->fetch();

                $receipt = $d['receiptLink'] ?? $oldFiles['receipt_link']; 
                $invoice = $d['invoiceFile'] ?? $oldFiles['invoice_file']; 

                // Ajusta se o frontend enviou NULL explicitamente para remover o arquivo
                if (array_key_exists('receiptLink', $d) && $d['receiptLink'] === null) $receipt = null;
                if (array_key_exists('invoiceFile', $d) && $d['invoiceFile'] === null) $invoice = null;

                $groupName = $d['groupName'];
                $optionalDescription = $d['description'] ?? '';
                
                // 2. Atualiza o registro de pagamento consolidado
                $stmtGroup = $this->pdo->prepare("UPDATE grouped_payments SET amount=?, date=?, method=?, description=?, group_name=?, invoice_file=?, receipt_link=? WHERE id=?");
                $stmtGroup->execute([
                    (float)$d['amount'], 
                    $d['date'], 
                    $d['method'], 
                    $optionalDescription, 
                    $groupName, 
                    $invoice,
                    $receipt,
                    $groupedPaymentId
                ]);
                
                // 3. Atualiza os projetos associados com a nova NF (se houver mudança)
                // Se o invoice for alterado (novo upload ou setado para NULL), atualiza os projetos vinculados.
                if (array_key_exists('invoiceFile', $d)) {
                   $stmtUpdateProject = $this->pdo->prepare("UPDATE projects SET invoice_file=? WHERE grouped_payment_id=?");
                   $stmtUpdateProject->execute([$invoice, $groupedPaymentId]);
                }

                $this->pdo->commit();
                echo json_encode(["message" => "Pagamento Consolidado atualizado!", "groupedPaymentId" => (string)$groupedPaymentId]);

            } catch (Exception $e) {
                if($this->pdo->inTransaction()) $this->pdo->rollBack();
                http_response_code(500); echo json_encode(["error" => $e->getMessage()]);
            }
        }
        elseif ($method === 'DELETE' && $groupedPaymentId) {
             $this->pdo->beginTransaction();
             try {
                 // 1. Remove o grouped_payment_id dos projetos afetados
                 $this->pdo->prepare("UPDATE projects SET grouped_payment_id=NULL, status='Aguardando Pagamento', requires_invoice=0, invoice_issued=0, invoice_file=NULL WHERE grouped_payment_id=?")->execute([$groupedPaymentId]);
                 // 2. Deleta o pagamento consolidado
                 $this->pdo->prepare("DELETE FROM grouped_payments WHERE id=?")->execute([$groupedPaymentId]);
                 $this->pdo->commit();
                 echo json_encode(["message" => "Pagamento consolidado desfeito. Projetos voltaram para 'Aguardando Pagamento'."]);
             } catch (Exception $e) {
                 if($this->pdo->inTransaction()) $this->pdo->rollBack();
                 http_response_code(500); echo json_encode(["error" => $e->getMessage()]);
             }
        }
    }
}
?>