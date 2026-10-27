<?php
session_start();
require 'config.php';

// Segurança: Garanta que a coluna de status existe
$check_status = $conn->query("SHOW COLUMNS FROM configuracao_evento LIKE 'status_inscricoes'");
if ($check_status && $check_status->num_rows == 0) { 
    $conn->query("ALTER TABLE configuracao_evento ADD COLUMN status_inscricoes TINYINT(1) DEFAULT 1"); 
}

$msg = '';
$tipo_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    
    // AÇÃO 1: ABRIR/FECHAR INSCRIÇÕES
    if ($_POST['acao'] == 'toggle_status') {
        $novo_status = (int)$_POST['novo_status'];
        $conn->query("UPDATE configuracao_evento SET status_inscricoes = $novo_status WHERE id = 1");
        $msg = $novo_status == 1 ? "Inscrições ABERTAS com sucesso!" : "Inscrições ENCERRADAS com sucesso!";
        $tipo_msg = "success";
    }
    
    // AÇÃO 2: RESETAR TUDO (Zerar Inscrições e Fotos)
    if ($_POST['acao'] == 'reset_evento') {
        // 1. Apaga todas as fotos do servidor para não lotar o disco
        $arquivos = glob('uploads/foto_*');
        foreach($arquivos as $arquivo){
            if(is_file($arquivo)) {
                unlink($arquivo);
            }
        }
        
        // 2. Limpa e reseta os IDs do banco de dados (TRUNCATE)
        if ($conn->query("TRUNCATE TABLE inscritos")) {
            // 3. Garante que as inscrições abrem novamente
            $conn->query("UPDATE configuracao_evento SET status_inscricoes = 1 WHERE id = 1");
            $msg = "Avanço Resetado! Banco de dados limpo, fotos apagadas e formulário aberto para novo evento.";
            $tipo_msg = "success";
        } else {
            $msg = "Erro ao limpar o banco: " . $conn->error;
            $tipo_msg = "error";
        }
    }
}

$resultado_evento = $conn->query("SELECT * FROM configuracao_evento WHERE id = 1");
$evento = $resultado_evento ? $resultado_evento->fetch_assoc() : null;
$inscricoes_abertas = ($evento && isset($evento['status_inscricoes'])) ? (int)$evento['status_inscricoes'] : 1;

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão do Ciclo do Evento</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f1f5f9; padding: 30px 15px; color: #334155; }
        .container { max-width: 600px; margin: 0 auto; }
        h1 { font-size: 24px; color: #0f172a; margin-bottom: 20px; }
        
        .card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .card h3 { margin-top: 0; font-size: 18px; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;}
        .card p { font-size: 14px; color: #64748b; line-height: 1.5; margin-bottom: 20px; }
        
        .status-badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 13px; font-weight: bold; margin-bottom: 20px; }
        .status-open { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        .status-closed { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        
        .btn { padding: 12px 20px; border: none; border-radius: 8px; font-size: 14px; font-weight: bold; cursor: pointer; transition: 0.2s; width: 100%; display: block; text-align: center; }
        .btn-blue { background: #2563eb; color: white; }
        .btn-blue:hover { background: #1d4ed8; }
        .btn-gray { background: #64748b; color: white; }
        .btn-gray:hover { background: #475569; }
        
        .card-danger { border: 1px solid #fca5a5; background: #fef2f2; }
        .card-danger h3 { color: #b91c1c; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: bold; }
        .alert.success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚙️ Gestão de Ciclo UNEC</h1>
        
        <?php if($msg): ?>
            <div class="alert <?= $tipo_msg ?>"><?= $msg ?></div>
        <?php endif; ?>

        <!-- PAINEL DE CONTROLE DE STATUS -->
        <div class="card">
            <h3>Controle de Formulário</h3>
            <p>Ative ou desative o recebimento de novas inscrições na página principal.</p>
            
            <div class="status-badge <?= $inscricoes_abertas == 1 ? 'status-open' : 'status-closed' ?>">
                Status Atual: <?= $inscricoes_abertas == 1 ? '🟢 ABERTAS (Aceitando)' : '🔴 ENCERRADAS (Bloqueado)' ?>
            </div>
            
            <form method="POST">
                <input type="hidden" name="acao" value="toggle_status">
                <input type="hidden" name="novo_status" value="<?= $inscricoes_abertas == 1 ? '0' : '1' ?>">
                
                <?php if($inscricoes_abertas == 1): ?>
                    <button type="submit" class="btn btn-gray">🔒 Fechar Inscrições Agora</button>
                <?php else: ?>
                    <button type="submit" class="btn btn-blue">🔓 Reabrir Inscrições</button>
                <?php endif; ?>
            </form>
        </div>

        <!-- ZONA DE PERIGO: RESETAR EVENTO -->
        <div class="card card-danger">
            <h3>⚠️ Novo Avanço Missionário</h3>
            <p>Esta ação irá <strong>apagar definitivamente</strong> todos os inscritos atuais da base de dados e excluir todas as fotos antigas do servidor para libertar espaço. As inscrições serão automaticamente reabertas e o sistema começará do zero.</p>
            
            <form method="POST" onsubmit="return confirm('ATENÇÃO: Tem a certeza absoluta? Todos os dados atuais de inscritos serão apagados permanentemente e não poderão ser recuperados. Deseja iniciar um Novo Avanço?');">
                <input type="hidden" name="acao" value="reset_evento">
                <button type="submit" class="btn btn-danger">🗑️ Limpar Tudo e Iniciar Novo Evento</button>
            </form>
        </div>
        
    </div>
</body>
</html>