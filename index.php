<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

require 'config.php';

if (!isset($conn) || $conn->connect_error) {
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2>⚠️ Erro de Conexão</h2><p>Verifique o banco de dados.</p></div>");
}

// CRIAÇÃO BLINDADA DE COLUNAS GERAIS
$colunas_necessarias = [
    'data_inscricao' => "DATETIME DEFAULT CURRENT_TIMESTAMP",
    'data_nascimento' => "DATE DEFAULT NULL",
    'observacoes' => "TEXT DEFAULT NULL",
    'termo_regras' => "TINYINT(1) DEFAULT 1",
    'termo_imagem' => "TINYINT(1) DEFAULT 1",
    'tamanho_camisa' => "VARCHAR(50) DEFAULT 'Não informado'",
    'telefone' => "VARCHAR(20) DEFAULT ''"
];
foreach ($colunas_necessarias as $coluna => $tipo) {
    $check_col = $conn->query("SHOW COLUMNS FROM inscritos LIKE '$coluna'");
    if ($check_col && $check_col->num_rows == 0) { 
        $conn->query("ALTER TABLE inscritos ADD COLUMN $coluna $tipo"); 
    }
}

// CRIAÇÃO BLINDADA DA COLUNA DE STATUS DO EVENTO (Para ligar/desligar)
$check_status = $conn->query("SHOW COLUMNS FROM configuracao_evento LIKE 'status_inscricoes'");
if ($check_status && $check_status->num_rows == 0) { 
    $conn->query("ALTER TABLE configuracao_evento ADD COLUMN status_inscricoes TINYINT(1) DEFAULT 1"); 
}

$msg = '';
$tipo_msg = '';
$sucesso = false;

if (isset($_GET['sucesso']) && $_GET['sucesso'] == 1) {
    $sucesso = true;
    $msg = "🎉 Inscrição confirmada com sucesso! Prepare-se para o que Deus vai fazer.";
    $tipo_msg = "success";
}

$resultado_evento = $conn->query("SELECT * FROM configuracao_evento WHERE id = 1");
$evento = $resultado_evento ? $resultado_evento->fetch_assoc() : null;

// Verifica se o admin fechou as inscrições
$inscricoes_abertas = ($evento && isset($evento['status_inscricoes'])) ? (int)$evento['status_inscricoes'] : 1;

$p_nome = htmlspecialchars($_POST['nome'] ?? '');
$p_telefone = htmlspecialchars($_POST['telefone'] ?? '');
$p_email = htmlspecialchars($_POST['email'] ?? '');
$p_doc_raw = htmlspecialchars($_POST['documento'] ?? '');
$p_igreja = htmlspecialchars($_POST['igreja'] ?? '');
$p_pastor = htmlspecialchars($_POST['pastor'] ?? '');
$p_como = htmlspecialchars($_POST['como_conheceu'] ?? '');
$p_missao = htmlspecialchars($_POST['missao_anterior'] ?? '');
$p_min = htmlspecialchars($_POST['ministerio'] ?? '');
$p_acomp = htmlspecialchars($_POST['acompanhante'] ?? '');
$p_outro_tam = htmlspecialchars($_POST['tamanho_outro'] ?? '');
$p_gen = $_POST['genero'] ?? '';
$p_avanco = $_POST['avanco_anterior'] ?? '';
$p_pag = $_POST['forma_pagamento'] ?? '';
$p_tam = $_POST['tamanho_camisa'] ?? '';
$p_nasc = htmlspecialchars($_POST['data_nascimento'] ?? '');
$p_obs = htmlspecialchars($_POST['observacoes'] ?? '');

// BLOQUEIA O POST SE AS INSCRIÇÕES ESTIVEREM FECHADAS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'inscrever' && $inscricoes_abertas == 1) {
    
    $nome = trim($_POST['nome'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $documento_raw = $_POST['documento'] ?? '';
    $documento = preg_replace('/[^0-9]/', '', $documento_raw); 
    $genero = $_POST['genero'] ?? '';
    $data_nascimento = !empty($_POST['data_nascimento']) ? trim($_POST['data_nascimento']) : null;
    $igreja = trim($_POST['igreja'] ?? '');
    $pastor = trim($_POST['pastor'] ?? '');
    $como_conheceu = trim($_POST['como_conheceu'] ?? '');
    $avanco_anterior = $_POST['avanco_anterior'] ?? '';
    $missao_anterior = trim($_POST['missao_anterior'] ?? '');
    $ministerio = trim($_POST['ministerio'] ?? '');
    $acompanhante = trim($_POST['acompanhante'] ?? '');
    $observacoes = trim($_POST['observacoes'] ?? '');
    $forma_pagamento = $_POST['forma_pagamento'] ?? '';
    $termo_regras = isset($_POST['termo_regras']) ? 1 : 0;
    $termo_imagem = isset($_POST['termo_imagem']) ? 1 : 0;

    $tamanho_camisa = $_POST['tamanho_camisa'] ?? '';
    if ($tamanho_camisa === 'Outro' && !empty($_POST['tamanho_outro'])) {
        $tamanho_camisa = trim($_POST['tamanho_outro']);
    }

    if (empty($documento)) {
        $msg = "O CPF é obrigatório."; $tipo_msg = "error";
    } elseif (strlen($documento) < 11) {
        $msg = "⚠️ O CPF digitado é inválido. Verifique os números."; $tipo_msg = "error";
    } elseif (empty($data_nascimento)) {
        $msg = "⚠️ Por favor, informe sua Data de Nascimento."; $tipo_msg = "error";
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM inscritos WHERE documento = ?");
        if ($stmt_check) {
            $stmt_check->bind_param("s", $documento);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                $msg = "⚠️ Este CPF já está cadastrado no sistema! Se acha que é um erro, procure a diretoria.";
                $tipo_msg = "error";
            }
            $stmt_check->close();
        }
    }

    $nome_foto = null;
    if(empty($msg)) {
        if (!isset($_FILES['foto_inscrito']) || $_FILES['foto_inscrito']['error'] === UPLOAD_ERR_NO_FILE) {
            $msg = "📸 Por favor, anexe uma foto antes de prosseguir."; 
            $tipo_msg = "error";
        } elseif ($_FILES['foto_inscrito']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['foto_inscrito']['name'], PATHINFO_EXTENSION));
            if(in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $nome_foto = 'foto_' . time() . '_' . rand(1000,9999) . '.' . $ext;
                if(!is_dir('uploads')) { mkdir('uploads', 0755, true); }
                if (!move_uploaded_file($_FILES['foto_inscrito']['tmp_name'], 'uploads/' . $nome_foto)) {
                    $msg = "Erro ao salvar a foto no servidor."; $tipo_msg = "error";
                }
            } else {
                $msg = "⚠️ Formato inválido. Use imagens JPG ou PNG."; $tipo_msg = "error";
            }
        } else {
            $msg = "Erro ao carregar o arquivo de imagem (Código: " . $_FILES['foto_inscrito']['error'] . ")."; $tipo_msg = "error";
        }
    }

    if(empty($msg) && $nome_foto) {
        $stmt = $conn->prepare("INSERT INTO inscritos (nome, email, telefone, documento, genero, data_nascimento, igreja, pastor, como_conheceu, avanco_anterior, missao_anterior, ministerio, acompanhante, observacoes, forma_pagamento, tamanho_camisa, foto, termo_regras, termo_imagem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sssssssssssssssssii", $nome, $email, $telefone, $documento, $genero, $data_nascimento, $igreja, $pastor, $como_conheceu, $avanco_anterior, $missao_anterior, $ministerio, $acompanhante, $observacoes, $forma_pagamento, $tamanho_camisa, $nome_foto, $termo_regras, $termo_imagem);
            if($stmt->execute()) {
                echo "<script>window.location.replace('index.php?sucesso=1');</script>";
                exit;
            } else {
                $msg = "Erro interno ao gravar no banco de dados: " . $stmt->error; $tipo_msg = "error";
            }
            $stmt->close();
        } else {
            $msg = "Erro fatal na estrutura do banco: " . $conn->error; $tipo_msg = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Inscrição - <?= htmlspecialchars($evento['titulo'] ?? 'UNEC') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { 
            --brand: #2563eb; 
            --brand-dark: #1e40af;
            --dark: #0f172a; 
            --bg: #f8fafc; 
            --surface: #ffffff; 
            --text-main: #334155; 
            --text-muted: #64748b; 
            --border: #e2e8f0; 
            --success: #10b981;
            --success-bg: #d1fae5;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; -webkit-tap-highlight-color: transparent; }
        body { background-color: var(--bg); color: var(--text-main); padding-bottom: 60px; line-height: 1.5; }
        .hero { background: linear-gradient(135deg, var(--dark) 0%, #1e293b 100%); color: white; padding: 40px 15px 70px 15px; text-align: center; position: relative; overflow: hidden; }
        .hero::after { content: ''; position: absolute; bottom: -20px; left: 0; width: 100%; height: 40px; background: var(--bg); transform: skewY(-2deg); }
        .hero img.logo { max-width: 120px; margin-bottom: 15px; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.3)); }
        .hero h1 { font-size: 24px; font-weight: 800; margin-bottom: 8px; line-height: 1.2; }
        .hero p { color: #94a3b8; font-size: 14px; font-weight: 500; max-width: 400px; margin: 0 auto; }
        .container { max-width: 600px; width: 100%; margin: -30px auto 0 auto; position: relative; z-index: 10; padding: 0 15px; }
        .event-card { background: var(--surface); border-radius: 16px; padding: 18px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); border: 1px solid rgba(255,255,255,0.8); margin-bottom: 20px; display: grid; gap: 12px; }
        .event-detail { display: flex; align-items: flex-start; gap: 12px; font-size: 14px; color: var(--text-main); font-weight: 600; }
        .event-icon { background: #eff6ff; color: var(--brand); width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; margin-top: 2px;}
        .form-section { background: var(--surface); border-radius: 16px; padding: 20px 15px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); border: 1px solid var(--border); margin-bottom: 20px; }
        .section-header { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
        .section-header h2 { font-size: 16px; font-weight: 700; color: var(--dark); }
        .section-icon { font-size: 18px; }
        .form-group { margin-bottom: 18px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--text-main); }
        .form-control { width: 100%; padding: 14px; border: 1.5px solid var(--border); border-radius: 12px; font-size: 16px; background: var(--bg); color: var(--dark); outline: none; transition: all 0.3s ease; }
        .form-control:focus { background: var(--surface); border-color: var(--brand); box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1); }
        select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/200svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; background-size: 16px; padding-right: 40px; }
        .upload-area { position: relative; width: 100%; }
        .upload-input { opacity: 0; position: absolute; z-index: -1; width: 0.1px; height: 0.1px; }
        .upload-label { display: flex; flex-direction: column; align-items: center; justify-content: center; background: #eff6ff; color: var(--brand); padding: 20px 15px; border-radius: 12px; border: 2px dashed #93c5fd; cursor: pointer; transition: 0.3s; text-align: center; gap: 8px; }
        .badge-foto { display: none; margin-top: 10px; background: var(--success-bg); color: var(--success); padding: 10px; border-radius: 8px; font-size: 13px; font-weight: 700; text-align: center; border: 1px solid #6ee7b7; align-items: center; justify-content: center; gap: 6px; }
        .camisa-preview-container { background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; padding: 15px; text-align: center; margin-bottom: 15px; }
        .camisa-img { max-width: 100%; height: auto; border-radius: 8px; border: 1px solid #cbd5e1; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .btn-submit { width: 100%; background: var(--brand); color: white; border: none; padding: 16px; font-size: 16px; font-weight: 700; border-radius: 14px; cursor: pointer; transition: all 0.3s ease; display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 10px;}
        .alert { padding: 15px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 10px; line-height: 1.4; }
        .alert.success { background: var(--success-bg); color: #065f46; border: 1px solid #34d399; }
        .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .hidden-field { max-height: 0; overflow: hidden; opacity: 0; transition: all 0.3s ease-in-out; }
        .hidden-field.show { max-height: 100px; opacity: 1; margin-top: 15px; }
        .terms-scroll-box { max-height: 180px; overflow-y: auto; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 12px; margin-bottom: 12px; font-size: 12px; color: var(--text-main); line-height: 1.6; }
        .terms-scroll-box ul { padding-left: 16px; }
        .term-checkbox { display: flex; align-items: flex-start; gap: 12px; background: var(--bg); padding: 12px; border-radius: 10px; border: 1px solid var(--border); margin-bottom: 12px; }
        .term-checkbox input[type="checkbox"] { width: 20px; height: 20px; margin-top: 2px; accent-color: var(--brand); cursor: pointer; }
        .term-checkbox label { margin: 0; font-size: 13px; font-weight: 500; line-height: 1.4; color: var(--text-main); cursor: pointer; }
        .btn-copy-pix { background: #f1f5f9; color: var(--brand); border: 1px solid #cbd5e1; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 4px;}
        
        /* Estilo da tela de bloqueio UX */
        .closed-screen { background: var(--surface); border-radius: 16px; padding: 40px 20px; text-align: center; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); border: 1px solid var(--border); margin-bottom: 20px;}
        .closed-icon { font-size: 60px; margin-bottom: 15px; }
        .closed-title { color: var(--dark); font-size: 22px; font-weight: 800; margin-bottom: 10px; }
        .closed-text { color: var(--text-muted); font-size: 15px; line-height: 1.6; }
    </style>
</head>
<body>

    <div class="hero">
        <img src="LOGO UNEC.png" alt="Logo UNEC" class="logo" onerror="this.style.display='none'">
        <h1><?= htmlspecialchars($evento['titulo'] ?? 'Inscrição Voluntários') ?></h1>
        <p>Aliste-se para a missão. Preencha seus dados oficiais abaixo.</p>
    </div>

    <div class="container">
        
        <?php if($msg): ?>
            <div class="alert <?= $tipo_msg ?>">
                <span style="font-size: 20px;"><?= $tipo_msg == 'success' ? '✅' : '⚠️' ?></span>
                <div><?= $msg ?></div>
            </div>
            <?php if($tipo_msg == 'success'): ?>
                <!-- Botão condicional dependendo se as inscrições estão abertas ou fechadas -->
                <?php if($inscricoes_abertas == 1): ?>
                    <button type="button" class="btn-submit" onclick="window.location.href='index.php'">Fazer Nova Inscrição</button>
                <?php else: ?>
                    <button type="button" class="btn-submit" style="background:var(--dark);" onclick="window.location.href='index.php'">Voltar ao Início</button>
                <?php endif; ?>
                <style>form, .event-card { display: none; }</style>
            <?php endif; ?>
        <?php endif; ?>

        <?php if($evento && !$sucesso): ?>
            <div class="event-card">
                <div class="event-detail"><div class="event-icon">📍</div> <div><?= htmlspecialchars($evento['local']) ?></div></div>
                <div class="event-detail"><div class="event-icon">📅</div> <div><?= htmlspecialchars($evento['datas']) ?></div></div>
                <div class="event-detail">
                    <div class="event-icon">💳</div> 
                    <div>
                        R$ <?= htmlspecialchars($evento['valor']) ?> 
                        <span style="font-size:12px; color:var(--text-muted); display:flex; align-items:center; flex-wrap:wrap; gap:6px; font-weight:500; margin-top:4px;">
                            PIX: <strong id="chave-pix" style="color:var(--dark);"><?= htmlspecialchars($evento['pix']) ?></strong>
                            <button type="button" class="btn-copy-pix" id="btnCopyPix" onclick="copiarPix()">📋 Copiar</button>
                        </span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if($inscricoes_abertas == 0 && !$sucesso): ?>
            <!-- TELA DE INSCRIÇÕES ENCERRADAS (UX) -->
            <div class="closed-screen">
                <div class="closed-icon">🔒</div>
                <h2 class="closed-title">Inscrições Encerradas</h2>
                <p class="closed-text">As vagas para este Avanço Missionário foram totalmente preenchidas ou o prazo chegou ao fim. Fique atento às nossas redes oficiais para a abertura do próximo evento!</p>
            </div>
        <?php elseif(!$sucesso): ?>
            <!-- FORMULÁRIO DE INSCRIÇÃO (SÓ APARECE SE ESTIVER ABERTO) -->
            <form action="index.php" method="POST" enctype="multipart/form-data" id="formInscricao" onsubmit="return verificarEnvio()">
                <input type="hidden" name="acao" value="inscrever">

                <div class="form-section">
                    <div class="section-header"><div class="section-icon">📸</div><h2>Identificação Visual</h2></div>
                    <div class="form-group">
                        <label>Sua Foto (Para Crachá e Ônibus)</label>
                        <div class="upload-area">
                            <input type="file" id="foto_inscrito" name="foto_inscrito" accept="image/*" class="upload-input" onchange="comprimirEInserirFoto(this)" required>
                            <label for="foto_inscrito" class="upload-label" id="box-foto-label">
                                <span class="upload-icon">📷</span>
                                <span class="upload-text">Tirar Foto ou Abrir Galeria</span>
                                <span class="upload-sub">Obrigatório • Rosto visível e claro</span>
                            </label>
                        </div>
                        <div id="badge-foto" class="badge-foto">
                            <span id="icone-badge">✅</span> <span id="texto-nome-foto">Foto carregada!</span>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-header"><div class="section-icon">👤</div><h2>Dados Pessoais</h2></div>
                    <div class="form-group">
                        <label>Nome Completo</label>
                        <input type="text" name="nome" class="form-control" required placeholder="Digite como está no RG" value="<?= $p_nome ?>">
                    </div>
                    <div class="form-group">
                        <label>WhatsApp / Telefone</label>
                        <input type="tel" name="telefone" class="form-control" required placeholder="(11) 90000-0000" maxlength="15" oninput="mascaraTelefone(this)" value="<?= $p_telefone ?>">
                    </div>
                    <div class="form-group">
                        <label>Documento (CPF)</label>
                        <input type="text" name="documento" class="form-control" required placeholder="000.000.000-00" inputmode="numeric" maxlength="14" oninput="mascaraCPF(this)" value="<?= $p_doc_raw ?>">
                    </div>
                    <div class="form-group">
                        <label>E-mail Oficial</label>
                        <input type="email" name="email" class="form-control" required placeholder="exemplo@email.com" value="<?= $p_email ?>">
                    </div>
                    <div class="form-group">
                        <label>Gênero</label>
                        <select name="genero" class="form-control" required>
                            <option value="">Selecione para logística...</option>
                            <option value="Masculino" <?= ($p_gen == 'Masculino') ? 'selected' : '' ?>>Masculino</option>
                            <option value="Feminino" <?= ($p_gen == 'Feminino') ? 'selected' : '' ?>>Feminino</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Data de Nascimento</label>
                        <input type="date" name="data_nascimento" class="form-control" required value="<?= $p_nasc ?>">
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-header"><div class="section-icon">⛪</div><h2>Vida Espiritual</h2></div>
                    <div class="form-group">
                        <label>Sua Igreja</label>
                        <input type="text" name="igreja" class="form-control" required placeholder="Onde você congrega?" value="<?= $p_igreja ?>">
                    </div>
                    <div class="form-group">
                        <label>Nome do seu Pastor</label>
                        <input type="text" name="pastor" class="form-control" required placeholder="Pr. Nome" value="<?= $p_pastor ?>">
                    </div>
                    <div class="form-group">
                        <label>Qual o seu Ministério?</label>
                        <input type="text" name="ministerio" class="form-control" required placeholder="Ex: Louvor, Recepção, Jovens..." value="<?= $p_min ?>">
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-header"><div class="section-icon">🛤️</div><h2>Experiência</h2></div>
                    <div class="form-group">
                        <label>Como conheceu o projeto?</label>
                        <input type="text" name="como_conheceu" class="form-control" placeholder="Instagram, Amigo, Culto..." value="<?= $p_como ?>">
                    </div>
                    <div class="form-group">
                        <label>Já foi em um Avanço UNEC?</label>
                        <select name="avanco_anterior" class="form-control" required>
                            <option value="">Selecione...</option>
                            <option value="Sim" <?= ($p_avanco == 'Sim') ? 'selected' : '' ?>>Sim, já participei</option>
                            <option value="Não" <?= ($p_avanco == 'Não') ? 'selected' : '' ?>>Não, é minha primeira vez</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Participou de alguma Missão Anterior?</label>
                        <input type="text" name="missao_anterior" class="form-control" placeholder="Se sim, escreva qual foi" value="<?= $p_missao ?>">
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-header"><div class="section-icon">📦</div><h2>Logística e Pagamento</h2></div>
                    <div class="form-group">
                        <label>Quer ir no ônibus perto de alguém? (Acompanhante)</label>
                        <input type="text" name="acompanhante" class="form-control" placeholder="Nome do amigo(a) ou cônjuge" value="<?= $p_acomp ?>">
                    </div>
                    <div class="form-group">
                        <label>Forma de Pagamento Desejada</label>
                        <select name="forma_pagamento" class="form-control" required>
                            <option value="">Como pretende pagar?</option>
                            <option value="PIX à vista" <?= ($p_pag == 'PIX à vista') ? 'selected' : '' ?>>PIX à vista</option>
                            <option value="Parcelado no PIX" <?= ($p_pag == 'Parcelado no PIX') ? 'selected' : '' ?>>Parcelado no PIX (Mensal)</option>
                            <option value="Cartão de Crédito" <?= ($p_pag == 'Cartão de Crédito') ? 'selected' : '' ?>>Cartão de Crédito</option>
                            <option value="Dinheiro" <?= ($p_pag == 'Dinheiro') ? 'selected' : '' ?>>Dinheiro Físico</option>
                        </select>
                    </div>
                    <div style="margin-top: 30px; border-top: 1px solid var(--border); padding-top: 20px;">
                        <?php if(!empty($evento['imagem_camisa'])): ?>
                        <div class="camisa-preview-container">
                            <p>Modelo Oficial da Camiseta</p>
                            <img src="uploads/<?= htmlspecialchars($evento['imagem_camisa']) ?>" alt="Modelo Camiseta" class="camisa-img">
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label>Tamanho da Camiseta</label>
                            <select name="tamanho_camisa" id="tamanho_camisa" class="form-control" required onchange="toggleOutroTamanho(this.value)">
                                <option value="">Selecione o tamanho...</option>
                                <option value="Já possuo a camiseta" <?= ($p_tam == 'Já possuo a camiseta') ? 'selected' : '' ?>>✅ Já possuo a camiseta</option>
                                <option value="P" <?= ($p_tam == 'P') ? 'selected' : '' ?>>Tamanho P</option>
                                <option value="M" <?= ($p_tam == 'M') ? 'selected' : '' ?>>Tamanho M</option>
                                <option value="G" <?= ($p_tam == 'G') ? 'selected' : '' ?>>Tamanho G</option>
                                <option value="GG" <?= ($p_tam == 'GG') ? 'selected' : '' ?>>Tamanho GG</option>
                                <option value="XG" <?= ($p_tam == 'XG') ? 'selected' : '' ?>>Tamanho XG</option>
                                <option value="Outro" <?= ($p_tam == 'Outro') ? 'selected' : '' ?>>Outro (Vou digitar)</option>
                            </select>
                            <div id="box-outro-tamanho" class="hidden-field <?= ($p_tam == 'Outro') ? 'show' : '' ?>">
                                <input type="text" name="tamanho_outro" id="tamanho_outro" class="form-control" placeholder="Digite o tamanho exato (Ex: EXG, Infantil 12)" style="border-color: var(--brand);" value="<?= $p_outro_tam ?>">
                            </div>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top: 20px; border-top: 1px solid var(--border); padding-top: 15px;">
                        <label>Observações / Restrições (Opcional)</label>
                        <textarea name="observacoes" class="form-control" rows="3" placeholder="Restrições alimentares, alergias, remédios contínuos, cuidados médicos especiais ou recados importantes para a equipe..."><?= $p_obs ?></textarea>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-header"><div class="section-icon">📋</div><h2>Pré-requisitos e Orientações</h2></div>
                    <div class="terms-scroll-box">
                        <ul>
                            <li>Ser evangélico e membro ativo de uma igreja cristã.</li>
                            <li>No ônibus, homens e mulheres não devem sentar juntos (exceto casados).</li>
                            <li>Aconselhamos que as crianças permaneçam junto aos seus responsáveis durante todo o avanço.</li>
                            <li>Respeitar todos os horários e participar da programação completa.</li>
                            <li>Usar roupas adequadas (evitar shorts, leggings e regatas).</li>
                            <li>Não circular pela base missionária com roupas de dormir.</li>
                            <li>Evitar desperdício durante as refeições.</li>
                            <li>Colaborar com a limpeza e organização dos ambientes.</li>
                            <li>Evitar distrações com fotos/vídeos durante as atividades (deixe para a equipe de mídia).</li>
                            <li>Saídas em horários livres apenas com autorização da diretoria.</li>
                            <li><strong>Pagamento:</strong> 50% no ato da inscrição.</li>
                            <li><strong>Reembolso:</strong> Valor não reembolsável em caso de desistência.</li>
                            <li><strong>Confirmação:</strong> Apenas após pagamento total + participação no treinamento de evangelismo.</li>
                        </ul>
                    </div>
                    <div class="term-checkbox">
                        <input type="checkbox" name="termo_regras" id="termo_regras" value="1" required>
                        <label for="termo_regras">Li e concordo com todos os pré-requisitos, orientações e regras estabelecidas acima para o Avanço Missionário.</label>
                    </div>
                    <div class="term-checkbox" style="margin-bottom: 0;">
                        <input type="checkbox" name="termo_imagem" id="termo_imagem" value="1" required>
                        <label for="termo_imagem">Autorizo de forma gratuita o uso da minha imagem em fotos e vídeos do evento para fins de arquivo e divulgação oficial da UNEC.</label>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="btn-finalizar">Concluir Inscrição 🚀</button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        let fotoProntaParaEnvio = false;

        function mascaraCPF(input) { let v = input.value.replace(/\D/g, ''); if (v.length <= 11) { v = v.replace(/(\d{3})(\d)/, '$1.$2'); v = v.replace(/(\d{3})(\d)/, '$1.$2'); v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2'); } input.value = v; }
        function mascaraTelefone(input) { let v = input.value.replace(/\D/g, ''); if (v.length <= 11) { v = v.replace(/^(\d{2})(\d)/g, '($1) $2'); v = v.replace(/(\d)(\d{4})$/, '$1-$2'); } input.value = v; }

        function copiarPix() {
            var chave = document.getElementById('chave-pix').innerText;
            var btn = document.getElementById('btnCopyPix');
            navigator.clipboard.writeText(chave).then(function() {
                var textoOriginal = btn.innerHTML;
                btn.innerHTML = '✅ Copiado!'; btn.classList.add('copiado');
                setTimeout(function() { btn.innerHTML = textoOriginal; btn.classList.remove('copiado'); }, 2000);
            });
        }

        function toggleOutroTamanho(valor) {
            var boxOutro = document.getElementById('box-outro-tamanho');
            var inputOutro = document.getElementById('tamanho_outro');
            if (valor === 'Outro') {
                boxOutro.classList.add('show');
                inputOutro.required = true;
                setTimeout(() => inputOutro.focus(), 300);
            } else {
                boxOutro.classList.remove('show');
                inputOutro.required = false;
                inputOutro.value = '';
            }
        }

        function comprimirEInserirFoto(input) {
            var badge = document.getElementById('badge-foto');
            var txt = document.getElementById('texto-nome-foto');
            var label = document.getElementById('box-foto-label');
            var icone = document.getElementById('icone-badge');
            
            if (input.files && input.files[0]) {
                var file = input.files[0];
                icone.innerText = '⏳'; txt.innerHTML = 'Otimizando imagem para o iOS...';
                badge.style.display = 'flex'; label.style.borderColor = '#fbbf24'; label.style.background = '#fef3c7';
                fotoProntaParaEnvio = false;

                var reader = new FileReader();
                reader.onload = function(e) {
                    var img = new Image();
                    img.onload = function() {
                        var canvas = document.createElement('canvas'); var ctx = canvas.getContext('2d');
                        var maxW = 800; var maxH = 800; var width = img.width; var height = img.height;
                        if (width > height) { if (width > maxW) { height *= maxW / width; width = maxW; } } else { if (height > maxH) { width *= maxH / height; height = maxH; } }
                        canvas.width = width; canvas.height = height; ctx.drawImage(img, 0, 0, width, height);

                        canvas.toBlob(function(blob) {
                            var arquivoComprimido = new File([blob], "foto_perfil.jpg", { type: "image/jpeg", lastModified: new Date().getTime() });
                            var dataTransfer = new DataTransfer(); dataTransfer.items.add(arquivoComprimido);
                            input.files = dataTransfer.files;

                            icone.innerText = '✅'; txt.innerHTML = 'Foto pronta e otimizada!';
                            label.style.borderColor = '#10b981'; label.style.background = '#f0fdf4';
                            fotoProntaParaEnvio = true;
                        }, 'image/jpeg', 0.75);
                    }
                    img.src = e.target.result;
                }
                reader.readAsDataURL(file);
            } else {
                badge.style.display = 'none'; label.style.borderColor = '#93c5fd'; label.style.background = '#eff6ff';
                fotoProntaParaEnvio = false;
            }
        }

        function verificarEnvio() {
            var form = document.getElementById('formInscricao');
            if (!form.checkValidity()) { return true; }
            if (!fotoProntaParaEnvio) { alert("Aguarde um instante. A sua foto ainda está a ser otimizada pelo navegador."); return false; }
            var btn = document.getElementById('btn-finalizar');
            btn.innerHTML = 'Enviando Dados...'; btn.style.opacity = '0.7'; btn.style.pointerEvents = 'none';
            return true;
        }
    </script>
</body>
</html>