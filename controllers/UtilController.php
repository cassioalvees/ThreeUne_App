<?php
// controllers/UtilController.php (v16 - CORREÇÃO DE DUPLICAÇÃO no syncCalendar)

class UtilController extends BaseController {
    public function __construct(PDO $pdo) {
        parent::__construct($pdo);
    }

    public function handleRequest($method, $id, $input, $resource) {
        switch ($resource) {
            case 'upload':
                $this->handleUpload($method);
                break;
            case 'sync_calendar':
                $this->syncCalendar($method, $input);
                break;
            case 'convert_quote':
                $this->convertQuote($method, $input);
                break;
            case 'delete_file': // NOVO: Rota para exclusão de arquivos
                $this->deleteFile($method, $input);
                break;
        }
    }

    // --- Lógica de Upload ---
    private function handleUpload($method) {
        if ($method === 'POST' && isset($_FILES['file'])) {
            $uploadDir = 'uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $file = $_FILES['file'];
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $name = pathinfo($file['name'], PATHINFO_FILENAME);
            $cleanName = preg_replace('/[^a-zA-Z0-9]/', '_', $name);
            $filename = $cleanName . '_' . time() . '.' . $ext;
            $targetPath = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                echo json_encode(["url" => $targetPath]);
            } else {
                http_response_code(500); echo json_encode(["error" => "Falha ao mover arquivo"]);
            }
        } else {
            http_response_code(400); echo json_encode(["error" => "Nenhum arquivo enviado"]);
        }
    }
    
    // --- NOVO: Lógica de Exclusão de Arquivo ---
    private function deleteFile($method, $input) {
        if ($method === 'POST' && $input && isset($input['url'])) {
            $fileUrl = $input['url'];
            
            // Garante que o arquivo está na pasta "uploads/"
            if (strpos($fileUrl, 'uploads/') !== 0) {
                http_response_code(400); 
                echo json_encode(["error" => "Acesso negado: Não é permitido excluir arquivos fora de 'uploads/'."]); 
                exit;
            }
            
            if (file_exists($fileUrl) && is_file($fileUrl)) {
                if (unlink($fileUrl)) {
                    echo json_encode(["message" => "Arquivo excluído: " . $fileUrl]);
                } else {
                    http_response_code(500); 
                    echo json_encode(["error" => "Falha ao excluir o arquivo."]);
                }
            } else {
                // Não é considerado erro se o arquivo não existe, apenas registra a tentativa.
                echo json_encode(["message" => "Arquivo não encontrado, exclusão ignorada."]);
            }
        } else {
            http_response_code(400); 
            echo json_encode(["error" => "URL do arquivo ausente"]);
        }
    }

    // --- Lógica de Sync Calendar (Depende da função global fetchUrl) ---
    private function syncCalendar($method, $input) {
        if ($method === 'POST' && $input && isset($input['url'])) {
            
            // 1. Verifica autenticação e Obtém o company_id (user_id da sessão)
            if (!isset($_SESSION['user_id'])) { 
                http_response_code(401); 
                echo json_encode(["error" => "Usuário não autenticado."]); 
                exit;
            }
            $companyId = $_SESSION['user_id'];
            $icalUrl = $input['url'];
            
            if (!filter_var($icalUrl, FILTER_VALIDATE_URL)) { http_response_code(400); echo json_encode(["error" => "URL inválida"]); exit; }
            
            // CHAMA FUNÇÃO GLOBAL
            $icalData = fetchUrl($icalUrl); 
            
            if (!$icalData) { http_response_code(400); echo json_encode(["error" => "Falha ao acessar agenda."]); exit; }
            
            preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $icalData, $events);
            if (empty($events[1])) { echo json_encode(["message" => "Nenhum evento encontrado."]); exit; }
            
            // 2. Busca ou Cria o Cliente "Google Agenda" para esta empresa (companyId)
            $clientName = 'Google Agenda - Company #'.$companyId;
            $stmtC = $this->pdo->prepare("SELECT id FROM clients WHERE name = ?"); 
            $stmtC->execute([$clientName]); 
            $client = $stmtC->fetch();
            
            if (!$client) { 
                $this->pdo->prepare("INSERT INTO clients (name, created_at) VALUES (?, NOW())")->execute([$clientName]); 
                $clientId = $this->pdo->lastInsertId(); 
            } else { 
                $clientId = $client['id']; 
            }
            
            $count = 0;
            $this->pdo->beginTransaction();
            try {
                foreach ($events[1] as $event) {
                    preg_match('/SUMMARY:(.*)/', $event, $summary); 
                    preg_match('/DTSTART(?:;VALUE=DATE)?:(\d{8})/', $event, $dtstart);
                    
                    if (!empty($summary[1]) && !empty($dtstart[1])) {
                        $title = trim($summary[1]); 
                        $date = date('Y-m-d', strtotime($dtstart[1]));
                        
                        // 3. Verifica se o projeto já existe, usando TITLE, PROJECT_DATE e COMPANY_ID para evitar duplicidade
                        $check = $this->pdo->prepare("SELECT id FROM projects WHERE title = ? AND project_date = ? AND company_id = ?"); 
                        $check->execute([$title, $date, $companyId]);
                        
                        if (!$check->fetch()) { 
                            // 4. Insere o novo projeto (incluindo o company_id)
                            $this->pdo->prepare("INSERT INTO projects (client_id, title, status, project_date, project_type, grouped_payment_id, company_id, created_at) VALUES (?, ?, 'Pendente', ?, 'Freela', NULL, ?, NOW())")
                                       ->execute([$clientId, $title, $date, $companyId]);
                            $count++; 
                        }
                    }
                }
                $this->pdo->commit();
            } catch (Exception $e) {
                if($this->pdo->inTransaction()) $this->pdo->rollBack();
                http_response_code(500); 
                echo json_encode(["error" => "Erro ao processar eventos: " . $e->getMessage()]);
                exit;
            }

            echo json_encode(["message" => "$count projetos importados!"]);
        }
    }

    // --- Lógica de Conversão de Orçamento ---
    private function convertQuote($method, $input) {
        if ($method === 'POST' && $input && isset($input['quoteId'])) {
            $qid = (int)$input['quoteId'];
            
            // 1. Obter o company_id do usuário logado
            if (!isset($_SESSION['user_id'])) { 
                http_response_code(401); 
                echo json_encode(["error" => "Usuário não autenticado."]); 
                exit;
            }
            $companyId = $_SESSION['user_id'];
            
            $stmtQ = $this->pdo->prepare("SELECT * FROM quotes WHERE id=?");
            $stmtQ->execute([$qid]);
            $quote = $stmtQ->fetch();
            
            if (!$quote) { http_response_code(404); echo json_encode(["error" => "Orçamento não encontrado"]); exit; }
            if ($quote['status'] !== 'Aprovado') {
                 http_response_code(400); 
                 echo json_encode(["error" => "O orçamento deve estar 'Aprovado' para ser convertido."]); 
                 exit;
            }
            
            $this->pdo->beginTransaction();
            try {
                $projectDate = !empty($quote['execution_date']) ? $quote['execution_date'] : date('Y-m-d');

                // 2. Cria Projeto (INCLUINDO company_id - Correção do erro 1364)
                $stmtP = $this->pdo->prepare("INSERT INTO projects (client_id, title, description, status, project_date, project_type, requires_invoice, invoice_issued, grouped_payment_id, company_id, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, 0, NULL, ?, NOW())");
                $stmtP->execute([
                    $quote['client_id'], 
                    $quote['title'], 
                    "Projeto Gerado a partir do Orçamento #{$qid}. " . $quote['description'],
                    'Pendente',
                    $projectDate,
                    'Projeto Audiovisual',
                    $companyId // company_id INCLUÍDO AQUI
                ]);
                $newProjectId = $this->pdo->lastInsertId();
                
                // 3. Move Itens
                $stmtS = $this->pdo->prepare("SELECT * FROM quote_services WHERE quote_id=?");
                $stmtS->execute([$qid]);
                $services = $stmtS->fetchAll();
                
                $stmtPS = $this->pdo->prepare("INSERT INTO project_services (project_id, service_id, description, quantity, unit_value, total_value) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($services as $s) {
                    $stmtPS->execute([
                        $newProjectId, 
                        $s['service_id'], 
                        $s['description'], 
                        (float)$s['quantity'], 
                        (float)$s['unit_value'], 
                        (float)$s['total_value']
                    ]);
                }
                
                // 4. Exclui Orçamento Original
                $this->pdo->prepare("DELETE FROM quote_services WHERE quote_id=?")->execute([$qid]);
                $this->pdo->prepare("DELETE FROM quotes WHERE id=?")->execute([$qid]);
                
                $this->pdo->commit();
                echo json_encode(["message" => "Orçamento convertido para Projeto e excluído com sucesso!", "projectId" => (string)$newProjectId]);
            } catch (Exception $e) {
                if($this->pdo->inTransaction()) $this->pdo->rollBack();
                http_response_code(500); 
                echo json_encode(["error" => "Erro ao gerar projeto (DB): " . $e->getMessage()]);
            }
        }
    }
}
?>