<?php
session_start();
require 'config.php'; 

// ==========================================
// 1. AUTO-PATCH DO BANCO DE DADOS (LOJA & CANTINA OTIMIZADO)
// ==========================================
$conn->query("CREATE TABLE IF NOT EXISTS produtos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    categoria VARCHAR(50) DEFAULT 'Geral',
    preco DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    estoque INT NOT NULL DEFAULT 0,
    foto VARCHAR(255) DEFAULT NULL,
    data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_prod_cat (categoria),
    KEY idx_prod_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS vendas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_inscrito INT DEFAULT NULL,
    cliente_nome VARCHAR(100) DEFAULT 'Cliente Avulso',
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    desconto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    forma_pagamento VARCHAR(50) DEFAULT 'PIX',
    valor_recebido DECIMAL(10,2) DEFAULT NULL,
    troco DECIMAL(10,2) DEFAULT 0.00,
    status VARCHAR(20) NOT NULL DEFAULT 'CONCLUIDA',
    data_venda DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_vendas_data (data_venda),
    KEY idx_vendas_status (status),
    KEY idx_vendas_inscrito (id_inscrito)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS vendas_itens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_venda INT NOT NULL,
    id_produto INT NOT NULL,
    quantidade INT NOT NULL DEFAULT 1,
    preco_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    KEY idx_itens_venda (id_venda),
    KEY idx_itens_produto (id_produto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Migração suave de colunas adicionais para instalações existentes
$check_cols = [
    'subtotal'       => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    'desconto'       => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    'valor_recebido' => "DECIMAL(10,2) DEFAULT NULL",
    'troco'          => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    'status'         => "VARCHAR(20) NOT NULL DEFAULT 'CONCLUIDA'"
];
foreach ($check_cols as $col => $def) {
    $r_col = $conn->query("SHOW COLUMNS FROM vendas LIKE '$col'");
    if ($r_col && $r_col->num_rows == 0) {
        $conn->query("ALTER TABLE vendas ADD COLUMN $col $def");
    }
}

// ==========================================
// 2. EXPORTAÇÃO CSV DE VENDAS (FECHAMENTO DE CAIXA)
// ==========================================
if (isset($_GET['exportar_vendas_csv']) && !empty($_SESSION['usuario_id'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Relatorio_Vendas_Cantina_' . date('Y-m-d_H-i') . '.csv"');
    
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($out, ['ID Venda', 'Data e Hora', 'Cliente', 'Forma Pagamento', 'Subtotal (R$)', 'Desconto (R$)', 'Total Pago (R$)', 'Status', 'Itens'], ';');

    $q_exp = "
        SELECT v.*, 
               GROUP_CONCAT(CONCAT(vi.quantidade, 'x ', p.nome) SEPARATOR ' | ') as itens_resumo
        FROM vendas v 
        LEFT JOIN vendas_itens vi ON v.id = vi.id_venda 
        LEFT JOIN produtos p ON vi.id_produto = p.id
        GROUP BY v.id 
        ORDER BY v.data_venda DESC
    ";
    $res_exp = $conn->query($q_exp);
    if ($res_exp) {
        while ($row = $res_exp->fetch_assoc()) {
            fputcsv($out, [
                $row['id'],
                date('d/m/Y H:i:s', strtotime($row['data_venda'])),
                $row['cliente_nome'],
                $row['forma_pagamento'],
                number_format((float)($row['subtotal'] ?? $row['total']), 2, ',', ''),
                number_format((float)($row['desconto'] ?? 0), 2, ',', ''),
                number_format((float)$row['total'], 2, ',', ''),
                $row['status'] ?? 'CONCLUIDA',
                $row['itens_resumo'] ?? ''
            ], ';');
        }
    }
    fclose($out);
    exit;
}

// ==========================================
// 3. INTEGRAÇÃO DE SESSÃO E AUTENTICAÇÃO UNIFICADA
// ==========================================
if (isset($_GET['sair'])) { 
    session_destroy();
    header("Location: loja.php"); 
    exit; 
}

$erro_login = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'login') {
    $email = trim($_POST['email']);
    $senha = $_POST['senha'];
    $stmt = $conn->prepare("SELECT id, nome, senha, nivel_acesso, forcar_senha FROM usuarios WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $usuario = $resultado->fetch_assoc();
        if (password_verify($senha, $usuario['senha'])) {
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];
            $_SESSION['usuario_nivel'] = $usuario['nivel_acesso'] ?: 'admin'; 
            $_SESSION['forcar_senha'] = $usuario['forcar_senha']; 
            header("Location: loja.php"); 
            exit;
        } else { $erro_login = 'Senha incorreta.'; }
    } else { $erro_login = 'E-mail não encontrado no sistema.'; }
    $stmt->close();
}

// Se não estiver logado, exibe a Tela de Login
if (!isset($_SESSION['usuario_id'])) {
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login - Loja & Cantina UNEC</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0f172a">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: radial-gradient(circle at top, #1e293b 0%, #0f172a 100%); display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .login-box { background: #ffffff; padding: 40px; border-radius: 20px; box-shadow: 0 20px 40px -15px rgba(0,0,0,0.3); width: 100%; max-width: 420px; text-align: center; border: 1px solid rgba(255,255,255,0.2); }
        .login-box img.logo { max-width: 90px; height: auto; margin: 0 auto 15px auto; display: block; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1)); }
        .login-box h1 { font-size: 24px; color: #0f172a; margin-bottom: 4px; font-weight: 800; }
        .login-box h1 span { color: #10b981; }
        .login-box p { color: #64748b; font-size: 13px; margin-bottom: 25px; }
        .form-group { text-align: left; margin-bottom: 16px; }
        .form-group label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 6px; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-group input { width: 100%; padding: 13px 15px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-size: 15px; outline: none; transition: 0.2s; }
        .form-group input:focus { border-color: #10b981; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15); }
        .btn-login { width: 100%; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; padding: 14px; font-size: 15px; font-weight: 700; border-radius: 10px; cursor: pointer; transition: 0.3s; margin-top: 10px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .btn-login:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4); }
        .erro { background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 15px; font-weight: 600; border: 1px solid #fca5a5; }
    </style>
</head>
<body>
    <div class="login-box">
        <img src="LOGO UNEC.png" alt="Logo UNEC" class="logo" onerror="this.style.display='none'">
        <h1>UNEC <span>PDV Cantina</span></h1>
        <p>Acesso exclusivo para operadores e diretoria</p>
        <?php if($erro_login): ?><div class="erro"><?= $erro_login ?></div><?php endif; ?>
        <form action="" method="POST">
            <input type="hidden" name="acao" value="login">
            <div class="form-group"><label>E-mail de Acesso</label><input type="email" name="email" required placeholder="bruno@unec.com"></div>
            <div class="form-group"><label>Senha</label><input type="password" name="senha" required placeholder="••••••"></div>
            <button type="submit" class="btn-login">Entrar no Caixa 🛒</button>
        </form>
    </div>
</body>
</html>
<?php exit; } 

// Troca de senha obrigatória (1º acesso)
if (!empty($_SESSION['forcar_senha']) && $_SESSION['forcar_senha'] == 1) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar_nova_senha') {
        $nova_senha = trim($_POST['nova_senha']);
        if (strlen($nova_senha) >= 6) {
            $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $uid = (int)$_SESSION['usuario_id'];
            $stmt_pwd = $conn->prepare("UPDATE usuarios SET senha = ?, forcar_senha = 0 WHERE id = ?");
            $stmt_pwd->bind_param("si", $hash, $uid);
            $stmt_pwd->execute();
            $stmt_pwd->close();

            $_SESSION['forcar_senha'] = 0;
            header("Location: loja.php"); 
            exit;
        } else { 
            $erro_senha = "A senha deve ter no mínimo 6 caracteres."; 
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <title>Trocar Senha - UNEC</title>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
        <style>* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; } body { background-color: #f1f5f9; display: flex; justify-content: center; align-items: center; height: 100vh; } .login-box { background: #fff; padding: 40px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); width: 100%; max-width: 400px; text-align: center; } .login-box h1 { font-size: 20px; color: #0f172a; margin-bottom: 10px; } .form-group { text-align: left; margin-bottom: 15px; } .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; color: #1e293b; } .form-group input { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 16px; } .btn-login { width: 100%; background: #10b981; color: white; border: none; padding: 14px; font-size: 15px; font-weight: 600; border-radius: 8px; cursor: pointer; margin-top: 10px; }</style>
    </head>
    <body>
        <div class="login-box">
            <h1>🔒 Olá, <?= htmlspecialchars($_SESSION['usuario_nome']) ?>!</h1>
            <p style="color:#64748b; font-size:14px; margin-bottom:20px;">Primeiro acesso detectado. Crie a sua senha definitiva:</p>
            <?php if(isset($erro_senha)): ?><div style="background:#fee2e2; color:#991b1b; padding:10px; border-radius:6px; margin-bottom:15px; font-size:13px;"><?= $erro_senha ?></div><?php endif; ?>
            <form action="" method="POST"><input type="hidden" name="acao" value="salvar_nova_senha">
                <div class="form-group"><label>Sua Nova Senha</label><input type="password" name="nova_senha" required placeholder="Mínimo de 6 caracteres"></div>
                <button type="submit" class="btn-login">Salvar e Acessar Loja</button>
            </form>
        </div>
    </body>
    </html>
    <?php exit;
}

$nivel_usuario = $_SESSION['usuario_nivel'] ?? 'admin';
if ($nivel_usuario == 'evangelismo') {
    echo "<div style='font-family:sans-serif; text-align:center; padding:50px;'><h2>Acesso Restrito</h2><p>O seu perfil de acesso não possui permissão para operar a Loja e Tesouraria.</p><a href='admin.php'>Voltar ao Painel</a></div>";
    exit;
}

// ==========================================
// 4. PROCESSAMENTO DE AÇÕES (CRUD, VENDA ATÔMICA, ESTORNO)
// ==========================================
$msg_loja = '';
$tipo_msg_loja = 'success';
$venda_recente_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    $acao = $_POST['acao'];

    // 1. SALVAR / EDITAR PRODUTO
    if ($acao == 'salvar_produto') {
        $nome_prod = trim($_POST['nome_produto']);
        $cat_prod = trim($_POST['categoria_produto'] ?? 'Geral');
        $preco_raw = str_replace(['R$', ' ', '.'], '', trim($_POST['preco_produto']));
        $preco_prod = (float)str_replace(',', '.', $preco_raw);
        $estoque_prod = (int)$_POST['estoque_produto'];

        $nome_foto = null;
        if (isset($_FILES['foto_produto']) && $_FILES['foto_produto']['name'] != '') {
            if ($_FILES['foto_produto']['error'] === UPLOAD_ERR_OK) {
                if(!is_dir('uploads')) { mkdir('uploads', 0755, true); }
                $ext = strtolower(pathinfo($_FILES['foto_produto']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $nome_foto = 'prod_' . time() . '_' . rand(100, 999) . '.' . $ext;
                    move_uploaded_file($_FILES['foto_produto']['tmp_name'], 'uploads/' . $nome_foto);
                }
            }
        }

        if (!empty($_POST['id_produto'])) {
            $id_p = (int)$_POST['id_produto'];
            if ($nome_foto) {
                $stmt = $conn->prepare("UPDATE produtos SET nome=?, categoria=?, preco=?, estoque=?, foto=? WHERE id=?");
                $stmt->bind_param("ssdisi", $nome_prod, $cat_prod, $preco_prod, $estoque_prod, $nome_foto, $id_p);
            } else {
                $stmt = $conn->prepare("UPDATE produtos SET nome=?, categoria=?, preco=?, estoque=? WHERE id=?");
                $stmt->bind_param("ssdii", $nome_prod, $cat_prod, $preco_prod, $estoque_prod, $id_p);
            }
            $stmt->execute();
            $stmt->close();
            $msg_loja = "Produto '{$nome_prod}' atualizado com sucesso!";
        } else {
            $stmt = $conn->prepare("INSERT INTO produtos (nome, categoria, preco, estoque, foto) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdis", $nome_prod, $cat_prod, $preco_prod, $estoque_prod, $nome_foto);
            $stmt->execute();
            $stmt->close();
            $msg_loja = "Novo produto cadastrado no catálogo!";
        }
    }

    // 2. EXCLUIR PRODUTO COM PREPARED STATEMENT
    if ($acao == 'excluir_produto') {
        $id_p_del = (int)$_POST['id_produto'];
        $stmt_sel = $conn->prepare("SELECT foto FROM produtos WHERE id = ?");
        $stmt_sel->bind_param("i", $id_p_del);
        $stmt_sel->execute();
        $res = $stmt_sel->get_result();
        if ($res && $res->num_rows > 0) {
            $f = $res->fetch_assoc()['foto'];
            if (!empty($f) && file_exists('uploads/' . $f)) { 
                unlink('uploads/' . $f); 
            }
        }
        $stmt_sel->close();

        $stmt_del = $conn->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt_del->bind_param("i", $id_p_del);
        $stmt_del->execute();
        $stmt_del->close();
        $msg_loja = "Produto removido definitivamente do estoque!";
    }

    // 3. FINALIZAR VENDA COM TRANSAÇÃO ATÔMICA, DESCONTO E TROCO
    if ($acao == 'realizar_venda') {
        $carrinho_json = $_POST['carrinho_json'] ?? '[]';
        $carrinho = json_decode($carrinho_json, true);
        $forma_pag = $_POST['forma_pagamento'] ?? 'PIX';
        $id_inscrito = !empty($_POST['id_inscrito']) ? (int)$_POST['id_inscrito'] : null;
        $cliente_nome = trim($_POST['cliente_nome'] ?? '');
        
        // Desconto
        $desconto_raw = str_replace(['R$', ' ', '.'], '', trim($_POST['valor_desconto'] ?? '0'));
        $desconto_float = max(0.0, (float)str_replace(',', '.', $desconto_raw));

        // Valor recebido em dinheiro
        $recebido_raw = str_replace(['R$', ' ', '.'], '', trim($_POST['valor_recebido'] ?? '0'));
        $recebido_float = (float)str_replace(',', '.', $recebido_raw);

        if ($id_inscrito) {
            $stmt_ins = $conn->prepare("SELECT nome FROM inscritos WHERE id = ?");
            $stmt_ins->bind_param("i", $id_inscrito);
            $stmt_ins->execute();
            $res_ins = $stmt_ins->get_result();
            if ($row_ins = $res_ins->fetch_assoc()) { 
                $cliente_nome = $row_ins['nome']; 
            }
            $stmt_ins->close();
        }
        if (empty($cliente_nome)) { 
            $cliente_nome = 'Cliente Avulso'; 
        }

        if (!empty($carrinho) && is_array($carrinho)) {
            $conn->begin_transaction();

            try {
                $subtotal_calculado = 0.0;
                $itens_validados = [];

                // 1. Validação com bloqueio FOR UPDATE (impede estoque negativo sob concorrência)
                $stmt_check = $conn->prepare("SELECT id, nome, preco, estoque FROM produtos WHERE id = ? FOR UPDATE");

                foreach ($carrinho as $item) {
                    $id_prod = (int)($item['id'] ?? 0);
                    $qtd_solicitada = (int)($item['qtd'] ?? 0);

                    if ($id_prod <= 0 || $qtd_solicitada <= 0) {
                        throw new Exception("Item ou quantidade inválida no carrinho.");
                    }

                    $stmt_check->bind_param("i", $id_prod);
                    $stmt_check->execute();
                    $res_p = $stmt_check->get_result();
                    $prod_bd = $res_p->fetch_assoc();

                    if (!$prod_bd) {
                        throw new Exception("Produto ID {$id_prod} não encontrado no catálogo.");
                    }

                    if ($prod_bd['estoque'] < $qtd_solicitada) {
                        throw new Exception("Estoque insuficiente para '{$prod_bd['nome']}'. Disponível: {$prod_bd['estoque']} UN.");
                    }

                    $preco_real = (float)$prod_bd['preco']; // Preço verificado pelo servidor
                    $subtotal_item = $preco_real * $qtd_solicitada;
                    $subtotal_calculado += $subtotal_item;

                    $itens_validados[] = [
                        'id'       => $id_prod,
                        'nome'     => $prod_bd['nome'],
                        'qtd'      => $qtd_solicitada,
                        'preco_un' => $preco_real,
                        'subtotal' => $subtotal_item
                    ];
                }
                $stmt_check->close();

                // Aplica desconto (não pode ser maior que o subtotal)
                if ($desconto_float > $subtotal_calculado) {
                    $desconto_float = $subtotal_calculado;
                }
                $total_final = max(0.0, $subtotal_calculado - $desconto_float);

                // Cálculo de troco caso tenha recebido em dinheiro
                $troco_calculado = 0.0;
                if ($forma_pag === 'Dinheiro' && $recebido_float > $total_final) {
                    $troco_calculado = $recebido_float - $total_final;
                }

                // 2. Grava a Venda
                $stmt_v = $conn->prepare(
                    "INSERT INTO vendas (id_inscrito, cliente_nome, subtotal, desconto, total, forma_pagamento, valor_recebido, troco, status) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'CONCLUIDA')"
                );
                $stmt_v->bind_param("isdddsdd", $id_inscrito, $cliente_nome, $subtotal_calculado, $desconto_float, $total_final, $forma_pag, $recebido_float, $troco_calculado);
                $stmt_v->execute();
                $id_venda = $conn->insert_id;
                $stmt_v->close();

                // 3. Grava Itens e Dá Baixa no Estoque
                $stmt_item = $conn->prepare("INSERT INTO vendas_itens (id_venda, id_produto, quantidade, preco_unitario) VALUES (?, ?, ?, ?)");
                $stmt_update_est = $conn->prepare("UPDATE produtos SET estoque = estoque - ? WHERE id = ?");

                foreach ($itens_validados as $item_val) {
                    $stmt_item->bind_param("iiid", $id_venda, $item_val['id'], $item_val['qtd'], $item_val['preco_un']);
                    $stmt_item->execute();

                    $stmt_update_est->bind_param("ii", $item_val['qtd'], $item_val['id']);
                    $stmt_update_est->execute();
                }
                $stmt_item->close();
                $stmt_update_est->close();

                $conn->commit();
                $venda_recente_id = $id_venda;
                $msg_loja = "✅ Venda #{$id_venda} de R$ " . number_format($total_final, 2, ',', '.') . " finalizada com sucesso!";
                $tipo_msg_loja = "success";

            } catch (Exception $e) {
                $conn->rollback();
                $msg_loja = "❌ Falha ao finalizar venda: " . $e->getMessage();
                $tipo_msg_loja = "error";
            }
        } else {
            $msg_loja = "O carrinho estava vazio."; 
            $tipo_msg_loja = "error";
        }
    }

    // 4. CANCELAR / ESTORNAR VENDA COM DEVOLUÇÃO AO ESTOQUE
    if ($acao == 'cancelar_venda' && in_array($nivel_usuario, ['admin', 'inscricoes', 'loja'])) {
        $id_venda_canc = (int)$_POST['id_venda'];
        
        $conn->begin_transaction();
        try {
            // Busca venda
            $stmt_v_check = $conn->prepare("SELECT id, status FROM vendas WHERE id = ? FOR UPDATE");
            $stmt_v_check->bind_param("i", $id_venda_canc);
            $stmt_v_check->execute();
            $res_v = $stmt_v_check->get_result();
            $venda_row = $res_v->fetch_assoc();
            $stmt_v_check->close();

            if (!$venda_row) {
                throw new Exception("Venda não localizada.");
            }
            if ($venda_row['status'] === 'CANCELADA') {
                throw new Exception("Esta venda já foi cancelada anteriormente.");
            }

            // Busca os itens da venda para devolver ao estoque
            $stmt_itens_est = $conn->prepare("SELECT id_produto, quantidade FROM vendas_itens WHERE id_venda = ?");
            $stmt_itens_est->bind_param("i", $id_venda_canc);
            $stmt_itens_est->execute();
            $res_itens = $stmt_itens_est->get_result();

            $stmt_repor = $conn->prepare("UPDATE produtos SET estoque = estoque + ? WHERE id = ?");
            while ($it = $res_itens->fetch_assoc()) {
                $stmt_repor->bind_param("ii", $it['quantidade'], $it['id_produto']);
                $stmt_repor->execute();
            }
            $stmt_itens_est->close();
            $stmt_repor->close();

            // Atualiza status da venda para CANCELADA
            $stmt_cancel = $conn->prepare("UPDATE vendas SET status = 'CANCELADA' WHERE id = ?");
            $stmt_cancel->bind_param("i", $id_venda_canc);
            $stmt_cancel->execute();
            $stmt_cancel->close();

            $conn->commit();
            $msg_loja = "Venda #{$id_venda_canc} foi cancelada e os itens foram devolvidos ao estoque!";
            $tipo_msg_loja = "success";

        } catch (Exception $e) {
            $conn->rollback();
            $msg_loja = "Erro ao cancelar venda: " . $e->getMessage();
            $tipo_msg_loja = "error";
        }
    }
}

// ==========================================
// 5. CONSULTAS E MÉTRICAS DO PAINEL
// ==========================================
$produtos_bd = $conn->query("SELECT * FROM produtos ORDER BY categoria ASC, nome ASC")->fetch_all(MYSQLI_ASSOC);
$inscritos_bd = $conn->query("SELECT id, nome, telefone FROM inscritos ORDER BY nome ASC")->fetch_all(MYSQLI_ASSOC);

// Estatísticas
$res_metricas = $conn->query("
    SELECT 
        COALESCE(SUM(CASE WHEN status != 'CANCELADA' THEN total ELSE 0 END), 0) as faturado,
        COUNT(CASE WHEN status != 'CANCELADA' THEN id END) as qtd_vendas,
        COALESCE(SUM(CASE WHEN status != 'CANCELADA' AND DATE(data_venda) = CURDATE() THEN total ELSE 0 END), 0) as faturado_hoje,
        COUNT(CASE WHEN status != 'CANCELADA' AND DATE(data_venda) = CURDATE() THEN id END) as vendas_hoje
    FROM vendas
")->fetch_assoc();

$faturamento_total = (float)($res_metricas['faturado'] ?? 0);
$total_vendas = (int)($res_metricas['qtd_vendas'] ?? 0);
$faturamento_hoje = (float)($res_metricas['faturado_hoje'] ?? 0);
$vendas_hoje = (int)($res_metricas['vendas_hoje'] ?? 0);

// Produtos em estoque crítico (<= 5)
$produtos_criticos = array_filter($produtos_bd, fn($p) => (int)$p['estoque'] <= 5);

// Categorias únicas com contagem
$categorias = [];
foreach ($produtos_bd as $p) {
    $c = $p['categoria'] ?: 'Geral';
    $categorias[$c] = ($categorias[$c] ?? 0) + 1;
}

// Histórico detalhado de vendas
$ultimas_vendas = $conn->query("
    SELECT v.*, 
           COUNT(vi.id) as itens_qtd,
           GROUP_CONCAT(CONCAT(vi.quantidade, 'x ', p.nome) SEPARATOR ', ') as resumo_itens
    FROM vendas v 
    LEFT JOIN vendas_itens vi ON v.id = vi.id_venda 
    LEFT JOIN produtos p ON vi.id_produto = p.id
    GROUP BY v.id 
    ORDER BY v.data_venda DESC LIMIT 30
")->fetch_all(MYSQLI_ASSOC);

// Configuração do Evento para Exibição da Chave PIX
$res_evt = $conn->query("SELECT titulo, pix FROM configuracao_evento WHERE id = 1");
$evento_info = $res_evt ? $res_evt->fetch_assoc() : null;
$chave_pix_padrao = !empty($evento_info['pix']) ? $evento_info['pix'] : '51859900000120';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>UNEC PDV - Loja & Cantina</title>

    <!-- CONFIGURAÇÃO PWA -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0f172a">
    <link rel="apple-touch-icon" href="icon-192.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="UNEC Loja">

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { 
            --primary: #0f172a; 
            --primary-light: #1e293b;
            --accent: #10b981; 
            --accent-hover: #059669;
            --brand-blue: #3b82f6;
            --bg-color: #f8fafc; 
            --card-bg: #ffffff; 
            --text-main: #0f172a; 
            --text-muted: #64748b; 
            --border: #e2e8f0; 
            --border-focus: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; -webkit-tap-highlight-color: transparent; }
        body { background-color: var(--bg-color); color: var(--text-main); display: flex; flex-direction: column; min-height: 100vh; }
        
        /* HEADER MODERNO */
        header { background: #0f172a; color: white; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.08); position: sticky; top: 0; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .brand-header { display: flex; align-items: center; gap: 12px; }
        .brand-header img { max-width: 38px; height: auto; }
        .brand-header h1 { font-size: 19px; font-weight: 800; letter-spacing: -0.5px; }
        .brand-header h1 span { color: #10b981; font-weight: 700; }
        .tag-status-caixa { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4); font-size: 11px; padding: 3px 8px; border-radius: 20px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: inline-flex; align-items: center; gap: 4px; }
        
        .user-nav { display: flex; align-items: center; gap: 10px; font-size: 13px; }
        .btn-nav { color: #cbd5e1; text-decoration: none; font-weight: 600; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15); padding: 7px 12px; border-radius: 8px; transition: 0.2s; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; }
        .btn-nav:hover { background: rgba(255,255,255,0.12); color: white; }
        .btn-nav.btn-sair { color: #fca5a5; border-color: rgba(239,68,68,0.3); }

        .container { padding: 24px; max-width: 1480px; margin: 0 auto; width: 100%; flex: 1; }

        /* CARDS DE RESUMO */
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .metric-card { background: var(--card-bg); border-radius: 14px; padding: 18px 20px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.03); display: flex; flex-direction: column; justify-content: space-between; position: relative; overflow: hidden; transition: 0.2s; }
        .metric-card:hover { transform: translateY(-2px); box-shadow: 0 8px 16px -2px rgba(0,0,0,0.06); }
        .metric-card .icon-watermark { position: absolute; right: 15px; bottom: 12px; font-size: 38px; opacity: 0.12; }
        .metric-title { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        .metric-value { font-size: 24px; font-weight: 800; color: var(--text-main); }
        .metric-sub { font-size: 12px; color: var(--text-muted); margin-top: 4px; font-weight: 500; }

        /* ABAS PRINCIPAIS */
        .nav-tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px; flex-wrap: wrap; }
        .tab-btn { background: transparent; border: none; padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 700; color: var(--text-muted); cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .tab-btn:hover { background: #e2e8f0; color: var(--text-main); }
        .tab-btn.active { background: #0f172a; color: white; box-shadow: 0 4px 10px rgba(15, 23, 42, 0.2); }

        /* PDV GRID */
        .pdv-wrapper { display: grid; grid-template-columns: 1fr 380px; gap: 24px; align-items: start; }
        @media (max-width: 1080px) { .pdv-wrapper { grid-template-columns: 1fr; } }

        .catalog-panel { background: var(--card-bg); border-radius: 16px; border: 1px solid var(--border); padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
        
        /* FILTROS E BUSCA */
        .filters-bar { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 18px; }
        .search-input-wrap { position: relative; flex: 1; min-width: 260px; }
        .search-input-wrap input { width: 100%; padding: 12px 14px 12px 40px; border-radius: 10px; border: 1.5px solid var(--border); font-size: 14px; outline: none; background: #f8fafc; font-weight: 500; transition: 0.2s; }
        .search-input-wrap input:focus { border-color: var(--accent); background: #ffffff; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.12); }
        .search-input-wrap .search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-size: 16px; color: #94a3b8; pointer-events: none; }

        .category-chips { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 4px; margin-bottom: 16px; scrollbar-width: none; }
        .chip { padding: 6px 14px; border-radius: 20px; border: 1px solid var(--border); font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; background: #ffffff; color: var(--text-muted); transition: 0.2s; }
        .chip:hover, .chip.active { background: #0f172a; color: white; border-color: #0f172a; }

        /* GRID DE PRODUTOS */
        .products-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 16px; }
        .product-card { background: #ffffff; border: 1.5px solid var(--border); border-radius: 14px; padding: 12px; text-align: center; cursor: pointer; display: flex; flex-direction: column; justify-content: space-between; transition: 0.2s; position: relative; overflow: hidden; box-shadow: 0 2px 6px rgba(0,0,0,0.02); }
        .product-card:hover { transform: translateY(-3px); border-color: var(--accent); box-shadow: 0 8px 20px rgba(16, 185, 129, 0.12); }
        .product-img { width: 100%; height: 110px; object-fit: cover; border-radius: 10px; margin-bottom: 10px; background: #f1f5f9; }
        .product-cat { font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .product-title { font-size: 13px; font-weight: 700; color: var(--text-main); margin: 4px 0 6px 0; line-height: 1.3; height: 34px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .product-price { font-size: 17px; font-weight: 800; color: #059669; margin-bottom: 8px; }
        .product-stock { font-size: 11px; font-weight: 700; padding: 4px 8px; border-radius: 6px; display: inline-block; background: #f1f5f9; color: #475569; border: 1px solid var(--border); }
        .product-stock.warning { background: #fef3c7; color: #b45309; border-color: #fde68a; }
        .product-stock.out { background: #fee2e2; color: #dc2626; border-color: #fca5a5; }

        /* CARRINHO DE COMPRAS MODERNO */
        .cart-panel { background: #ffffff; border-radius: 16px; border: 1.5px solid var(--border); padding: 22px; position: sticky; top: 90px; box-shadow: 0 8px 24px rgba(0,0,0,0.05); }
        .cart-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 14px; }
        .cart-header h3 { font-size: 17px; font-weight: 800; color: var(--primary); display: flex; align-items: center; gap: 8px; }
        .btn-clear-cart { background: none; border: none; color: #ef4444; font-size: 12px; font-weight: 700; cursor: pointer; padding: 4px 8px; border-radius: 6px; transition: 0.2s; }
        .btn-clear-cart:hover { background: #fee2e2; }

        .cart-items-list { max-height: 240px; overflow-y: auto; margin-bottom: 16px; padding-right: 4px; }
        .cart-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px dashed #e2e8f0; font-size: 13px; }
        .cart-row-info { flex: 1; padding-right: 10px; }
        .cart-row-info strong { display: block; font-size: 13px; line-height: 1.2; }
        .cart-row-info span { font-size: 11px; color: var(--text-muted); font-weight: 600; }
        .cart-qty-ctrl { display: flex; align-items: center; gap: 6px; }
        .btn-qty { width: 24px; height: 24px; border-radius: 6px; border: 1px solid #cbd5e1; background: #f8fafc; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 12px; transition: 0.15s; }
        .btn-qty:hover { background: #e2e8f0; border-color: #94a3b8; }
        .cart-row-total { width: 75px; text-align: right; font-weight: 800; color: #059669; }

        /* TOTAIS DO CARRINHO */
        .cart-summary { background: #f8fafc; border-radius: 12px; padding: 14px; margin-bottom: 16px; border: 1px solid #e2e8f0; }
        .summary-row { display: flex; justify-content: space-between; font-size: 13px; color: var(--text-muted); margin-bottom: 6px; font-weight: 600; }
        .summary-row.total-row { border-top: 1.5px solid #e2e8f0; padding-top: 8px; margin-top: 6px; font-size: 18px; font-weight: 800; color: var(--primary); }
        .summary-row.total-row span:last-child { color: #059669; }

        .form-group-cart { margin-bottom: 12px; }
        .form-group-cart label { display: block; font-size: 11px; font-weight: 700; color: var(--text-main); margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-group-cart input, .form-group-cart select { width: 100%; padding: 10px 12px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 13px; outline: none; background: #ffffff; font-weight: 600; }
        .form-group-cart input:focus, .form-group-cart select:focus { border-color: var(--accent); }

        .btn-checkout { width: 100%; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; padding: 14px; font-size: 15px; font-weight: 800; border-radius: 10px; cursor: pointer; transition: 0.2s; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 10px; }
        .btn-checkout:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4); }

        /* ALERTAS */
        .alert-banner { padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .alert-banner.success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .alert-banner.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        /* TABELA DE HISTÓRICO & ESTOQUE */
        .content-card { background: #ffffff; border-radius: 16px; border: 1px solid var(--border); padding: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); margin-bottom: 24px; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 14px; }
        th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        th { font-weight: 700; color: var(--text-muted); background: #f8fafc; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        tr:hover td { background: #f8fafc; }

        .badge-status { padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; display: inline-block; }
        .badge-status.concluida { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .badge-status.cancelada { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        .btn-table-action { padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; border: 1px solid var(--border); background: white; transition: 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
        .btn-table-action:hover { background: #f8fafc; border-color: var(--primary); }
        .btn-table-action.danger { color: #dc2626; border-color: #fca5a5; }
        .btn-table-action.danger:hover { background: #fee2e2; }

        /* MODAL DE IMPRESSÃO / RECIBO */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 1000; justify-content: center; align-items: center; padding: 20px; }
        .modal-recibo { background: white; width: 100%; max-width: 380px; border-radius: 16px; padding: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); text-align: center; }
        .recibo-ticket { border: 2px dashed #cbd5e1; padding: 18px; border-radius: 12px; margin: 15px 0; background: #fafafa; font-family: 'Courier New', Courier, monospace; font-size: 13px; text-align: left; }
        .recibo-ticket h4 { text-align: center; font-size: 16px; font-weight: bold; margin-bottom: 6px; }

        @media print {
            body * { visibility: hidden; }
            #recibo-imprimir, #recibo-imprimir * { visibility: visible; }
            #recibo-imprimir { position: absolute; left: 0; top: 0; width: 80mm; font-size: 12px; }
        }
    </style>
</head>
<body>

    <!-- HEADER SUPERIOR -->
    <header>
        <div class="brand-header">
            <img src="LOGO UNEC.png" alt="UNEC Logo" onerror="this.style.display='none'">
            <div>
                <h1>UNEC <span>Loja & Cantina</span></h1>
            </div>
            <span class="tag-status-caixa">🟢 Caixa Aberto</span>
        </div>
        
        <div class="user-nav">
            <span style="color:#94a3b8; font-size:12px;">Operador: <strong style="color:white;"><?= htmlspecialchars($_SESSION['usuario_nome']) ?></strong></span>
            <?php if($nivel_usuario == 'admin' || $nivel_usuario == 'inscricoes'): ?>
                <a href="admin.php" class="btn-nav">📋 Painel Inscrições</a>
            <?php endif; ?>
            <a href="?exportar_vendas_csv=1" class="btn-nav" title="Baixar relatório em Excel/CSV">📊 Fechamento Caixa</a>
            <a href="?sair=1" class="btn-nav btn-sair">🚪 Sair</a>
        </div>
    </header>

    <div class="container">
        <!-- ALERTAS -->
        <?php if($msg_loja): ?>
            <div class="alert-banner <?= $tipo_msg_loja ?>">
                <span><?= $msg_loja ?></span>
                <?php if($venda_recente_id): ?>
                    <button onclick="abrirRecibo(<?= $venda_recente_id ?>)" class="btn-table-action" style="background:#065f46; color:white; border:none;">
                        🖨️ Imprimir Cupom
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- CARDS DE MÉTRICAS -->
        <div class="metrics-grid">
            <div class="metric-card" style="border-left: 4px solid #10b981;">
                <span class="metric-title">💰 Faturamento Total</span>
                <div class="metric-value" style="color: #059669;">R$ <?= number_format($faturamento_total, 2, ',', '.') ?></div>
                <div class="metric-sub"><?= $total_vendas ?> vendas confirmadas</div>
                <div class="icon-watermark">💵</div>
            </div>

            <div class="metric-card" style="border-left: 4px solid #3b82f6;">
                <span class="metric-title">📅 Faturado Hoje</span>
                <div class="metric-value" style="color: #2563eb;">R$ <?= number_format($faturamento_hoje, 2, ',', '.') ?></div>
                <div class="metric-sub"><?= $vendas_hoje ?> vendas registradas hoje</div>
                <div class="icon-watermark">🛍️</div>
            </div>

            <div class="metric-card" style="border-left: 4px solid #f59e0b;">
                <span class="metric-title">⚠️ Estoque Crítico (≤ 5 un)</span>
                <div class="metric-value" style="color: <?= count($produtos_criticos) > 0 ? '#d97706' : '#10b981' ?>;">
                    <?= count($produtos_criticos) ?> itens
                </div>
                <div class="metric-sub"><?= count($produtos_bd) ?> produtos no total</div>
                <div class="icon-watermark">📦</div>
            </div>

            <div class="metric-card" style="border-left: 4px solid #6366f1;">
                <span class="metric-title">🔑 Chave PIX da Cantina</span>
                <div class="metric-value" style="font-size: 15px; font-family: monospace; color:#312e81;">
                    <?= htmlspecialchars($chave_pix_padrao) ?>
                </div>
                <div class="metric-sub" style="display:flex; justify-content:space-between; align-items:center;">
                    <span>Copie para cobrar o cliente</span>
                    <button type="button" onclick="navigator.clipboard.writeText('<?= addslashes($chave_pix_padrao) ?>'); alert('Chave PIX copiada!');" class="btn-table-action" style="font-size:10px; padding:2px 6px;">📋 Copiar</button>
                </div>
                <div class="icon-watermark">📱</div>
            </div>
        </div>

        <!-- NAVEGAÇÃO DE ABAS -->
        <div class="nav-tabs">
            <button type="button" class="tab-btn active" id="tab-pdv" onclick="alternarAba('pdv')">
                🛍️ Frente de Caixa (PDV)
            </button>
            <button type="button" class="tab-btn" id="tab-estoque" onclick="alternarAba('estoque')">
                📦 Gestão de Estoque & Catálogo (<?= count($produtos_bd) ?>)
            </button>
            <button type="button" class="tab-btn" id="tab-historico" onclick="alternarAba('historico')">
                📜 Histórico de Vendas & Estornos
            </button>
        </div>

        <!-- ============================================================== -->
        <!-- ABA 1: FRENTE DE CAIXA MODERNA (PDV) -->
        <!-- ============================================================== -->
        <div id="view-pdv" class="pdv-wrapper">
            <!-- CATÁLOGO -->
            <div class="catalog-panel">
                <div class="filters-bar">
                    <div class="search-input-wrap">
                        <span class="search-icon">🔍</span>
                        <input type="text" id="filtroTexto" placeholder="Pesquisar por nome ou código..." oninput="filtrarCatalogo()">
                    </div>
                </div>

                <!-- CHIPS DE CATEGORIA -->
                <div class="category-chips">
                    <button type="button" class="chip active" onclick="filtrarCategoria('todas', this)">Todas (<?= count($produtos_bd) ?>)</button>
                    <?php foreach ($categorias as $catNome => $catQtd): ?>
                        <button type="button" class="chip" onclick="filtrarCategoria('<?= htmlspecialchars($catNome) ?>', this)">
                            <?= htmlspecialchars($catNome) ?> (<?= $catQtd ?>)
                        </button>
                    <?php endforeach; ?>
                </div>

                <!-- GRID DE CARDS DOS PRODUTOS -->
                <div class="products-grid" id="gridProdutos">
                    <?php foreach($produtos_bd as $prod): 
                        $estoque = (int)$prod['estoque'];
                        $is_low = $estoque <= 5 && $estoque > 0;
                        $is_out = $estoque <= 0;
                        $foto_url = !empty($prod['foto']) && file_exists('uploads/'.$prod['foto']) ? 'uploads/'.$prod['foto'] : 'LOGO UNEC.png';
                    ?>
                        <div class="product-card item-catalogo" 
                             data-nome="<?= strtolower(htmlspecialchars($prod['nome'])) ?>" 
                             data-categoria="<?= htmlspecialchars($prod['categoria'] ?: 'Geral') ?>"
                             onclick="adicionarAoCarrinho(<?= $prod['id'] ?>, '<?= htmlspecialchars(addslashes($prod['nome'])) ?>', <?= (float)$prod['preco'] ?>, <?= $estoque ?>)">
                            
                            <img src="<?= $foto_url ?>" alt="<?= htmlspecialchars($prod['nome']) ?>" class="product-img" onerror="this.src='LOGO UNEC.png'">
                            <div>
                                <span class="product-cat"><?= htmlspecialchars($prod['categoria'] ?: 'Geral') ?></span>
                                <div class="product-title" title="<?= htmlspecialchars($prod['nome']) ?>"><?= htmlspecialchars($prod['nome']) ?></div>
                                <div class="product-price">R$ <?= number_format($prod['preco'], 2, ',', '.') ?></div>
                            </div>
                            
                            <div class="product-stock <?= $is_out ? 'out' : ($is_low ? 'warning' : '') ?>">
                                <?= $is_out ? '❌ Esgotado' : ($is_low ? "⚠️ Restam {$estoque}" : "{$estoque} em estoque") ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- PAINEL LATERAL DO CARRINHO (CHECKOUT) -->
            <div class="cart-panel">
                <div class="cart-header">
                    <h3>🛒 Carrinho de Venda</h3>
                    <button type="button" class="btn-clear-cart" onclick="limparCarrinho()">Esvaziar</button>
                </div>

                <div class="cart-items-list" id="cartContainer">
                    <div style="text-align: center; color: var(--text-muted); font-size: 13px; padding: 30px 10px;">
                        Toque nos produtos ao lado para montar a venda.
                    </div>
                </div>

                <!-- RESUMO FINANCEIRO -->
                <div class="cart-summary">
                    <div class="summary-row">
                        <span>Subtotal:</span>
                        <strong id="cart-subtotal">R$ 0,00</strong>
                    </div>
                    <div class="summary-row" id="row-desconto" style="display:none; color: #dc2626;">
                        <span>Desconto Aplicado:</span>
                        <strong id="cart-desconto-txt">- R$ 0,00</strong>
                    </div>
                    <div class="summary-row total-row">
                        <span>TOTAL FINAL:</span>
                        <span id="cart-total">R$ 0,00</span>
                    </div>
                    <div class="summary-row" id="row-troco" style="display:none; color: #0284c7; padding-top: 6px;">
                        <span>Troco do Cliente:</span>
                        <strong id="cart-troco-txt">R$ 0,00</strong>
                    </div>
                </div>

                <!-- FORMULÁRIO DE FECHAMENTO -->
                <form action="loja.php" method="POST" id="formVenda">
                    <input type="hidden" name="acao" value="realizar_venda">
                    <input type="hidden" name="carrinho_json" id="carrinho_json">

                    <div class="form-group-cart">
                        <label>Vincular a Voluntário / Inscrito</label>
                        <select name="id_inscrito" id="selectInscrito" onchange="verificarComprador(this)">
                            <option value="">👤 Cliente Avulso (Não Inscrito)</option>
                            <?php foreach($inscritos_bd as $ins): ?>
                                <option value="<?= $ins['id'] ?>"><?= htmlspecialchars($ins['nome']) ?> <?= !empty($ins['telefone']) ? '('.htmlspecialchars($ins['telefone']).')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group-cart" id="box_nome_avulso">
                        <label>Nome do Cliente Avulso</label>
                        <input type="text" name="cliente_nome" placeholder="Ex: Visitante, Irmão João...">
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group-cart">
                            <label>Desconto (R$)</label>
                            <input type="text" name="valor_desconto" id="inputDesconto" placeholder="0,00" oninput="calcularTotais()">
                        </div>

                        <div class="form-group-cart">
                            <label>Pagamento</label>
                            <select name="forma_pagamento" id="selectPagamento" onchange="alternarCamposPagamento(this.value)">
                                <option value="PIX">🟢 PIX</option>
                                <option value="Dinheiro">💵 Dinheiro</option>
                                <option value="Cartão de Débito">💳 Cartão Débito</option>
                                <option value="Cartão de Crédito">💳 Cartão Crédito</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group-cart" id="box_dinheiro_recebido" style="display: none;">
                        <label>Valor Entregue pelo Cliente (R$)</label>
                        <input type="text" name="valor_recebido" id="inputRecebido" placeholder="Ex: 50,00" oninput="calcularTotais()">
                    </div>

                    <button type="button" class="btn-checkout" onclick="confirmarVendaPDV()">
                        ✅ Concluir Venda (F10)
                    </button>
                </form>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- ABA 2: GESTÃO DE ESTOQUE & CADASTRO -->
        <!-- ============================================================== -->
        <div id="view-estoque" style="display: none;">
            <div class="content-card">
                <h3 style="font-size: 17px; font-weight: 800; margin-bottom: 16px; color: var(--primary);">
                    ➕ Cadastrar ou Atualizar Item do Estoque
                </h3>
                
                <form action="loja.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="acao" value="salvar_produto">
                    <input type="hidden" name="id_produto" id="edit_id_produto">

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                        <div class="form-group-cart">
                            <label>Nome do Produto *</label>
                            <input type="text" name="nome_produto" id="edit_nome" required placeholder="Ex: Água Mineral, Camiseta Branca M">
                        </div>

                        <div class="form-group-cart">
                            <label>Categoria *</label>
                            <select name="categoria_produto" id="edit_cat" required>
                                <option value="Bebidas">Bebidas (Água, Refrigerante, Suco)</option>
                                <option value="Alimentação">Alimentação (Salgados, Doces, Lanches)</option>
                                <option value="Vestuário">Vestuário (Camisetas, Bonés, Moletons)</option>
                                <option value="Lembranças">Lembranças (Canecas, Livros, Acessórios)</option>
                                <option value="Geral">Geral</option>
                            </select>
                        </div>

                        <div class="form-group-cart">
                            <label>Preço Unitário de Venda (R$) *</label>
                            <input type="text" name="preco_produto" id="edit_preco" required placeholder="0,00">
                        </div>

                        <div class="form-group-cart">
                            <label>Quantidade em Estoque *</label>
                            <input type="number" name="estoque_produto" id="edit_estoque" required min="0" value="10">
                        </div>

                        <div class="form-group-cart">
                            <label>Foto do Produto (Opcional)</label>
                            <input type="file" name="foto_produto" accept="image/*">
                        </div>
                    </div>

                    <div style="display: flex; gap: 10px; margin-top: 10px;">
                        <button type="submit" class="btn-checkout" style="width: auto; padding: 10px 24px;">
                            💾 Salvar no Catálogo
                        </button>
                        <button type="button" class="btn-table-action" onclick="limparFormProduto()">
                            Limpar Campos
                        </button>
                    </div>
                </form>
            </div>

            <!-- TABELA DE PRODUTOS CADASTRADOS -->
            <div class="content-card">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <h3 style="font-size: 16px; font-weight: 800;">Catálogo de Itens Cadastrados (<?= count($produtos_bd) ?>)</h3>
                </div>

                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Categoria</th>
                                <th>Preço Unitário</th>
                                <th>Saldo Estoque</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($produtos_bd as $prod): 
                                $foto_tabela = !empty($prod['foto']) && file_exists('uploads/'.$prod['foto']) ? 'uploads/'.$prod['foto'] : 'LOGO UNEC.png';
                            ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <img src="<?= $foto_tabela ?>" style="width: 40px; height: 40px; border-radius: 8px; object-fit: cover; border: 1px solid var(--border);">
                                            <strong><?= htmlspecialchars($prod['nome']) ?></strong>
                                        </div>
                                    </td>
                                    <td><span class="chip" style="cursor:default;"><?= htmlspecialchars($prod['categoria']) ?></span></td>
                                    <td><strong style="color: #059669; font-size: 14px;">R$ <?= number_format($prod['preco'], 2, ',', '.') ?></strong></td>
                                    <td>
                                        <span class="product-stock <?= $prod['estoque'] <= 0 ? 'out' : ($prod['estoque'] <= 5 ? 'warning' : '') ?>">
                                            <?= $prod['estoque'] ?> UN
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 6px;">
                                            <button type="button" class="btn-table-action" onclick="editarProduto(<?= htmlspecialchars(json_encode($prod)) ?>)">
                                                ✏️ Editar
                                            </button>
                                            <form action="loja.php" method="POST" onsubmit="return confirm('Deseja realmente remover este produto do catálogo?');" style="margin:0;">
                                                <input type="hidden" name="acao" value="excluir_produto">
                                                <input type="hidden" name="id_produto" value="<?= $prod['id'] ?>">
                                                <button type="submit" class="btn-table-action danger">🗑️</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- ABA 3: HISTÓRICO DE VENDAS & ESTORNO -->
        <!-- ============================================================== -->
        <div id="view-historico" style="display: none;">
            <div class="content-card">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                    <div>
                        <h3 style="font-size: 16px; font-weight: 800;">Últimas Vendas Registradas</h3>
                        <p style="font-size: 12px; color: var(--text-muted);">Controle de transações e cancelamento com reposição de estoque</p>
                    </div>
                    <a href="?exportar_vendas_csv=1" class="btn-table-action">
                        📥 Exportar Todas (CSV)
                    </a>
                </div>

                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th># ID</th>
                                <th>Data/Hora</th>
                                <th>Cliente</th>
                                <th>Itens Comprados</th>
                                <th>Pagamento</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($ultimas_vendas) > 0): ?>
                                <?php foreach($ultimas_vendas as $v): 
                                    $is_cancelada = ($v['status'] ?? 'CONCLUIDA') === 'CANCELADA';
                                ?>
                                    <tr style="<?= $is_cancelada ? 'opacity: 0.55; background: #fff1f2;' : '' ?>">
                                        <td style="font-weight: 800; color: var(--primary);">#<?= $v['id'] ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($v['data_venda'])) ?></td>
                                        <td><strong><?= htmlspecialchars($v['cliente_nome']) ?></strong></td>
                                        <td>
                                            <span style="font-size: 12px; color: var(--text-muted); font-weight: 500;">
                                                <?= htmlspecialchars($v['resumo_itens'] ?: ($v['itens_qtd'] . ' itens')) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="background:#eff6ff; color:#1e40af; padding:3px 8px; border-radius:4px; font-weight:bold; font-size:11px;">
                                                <?= htmlspecialchars($v['forma_pagamento']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong style="color: <?= $is_cancelada ? '#991b1b' : '#059669' ?>; font-size: 14px;">
                                                R$ <?= number_format($v['total'], 2, ',', '.') ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <span class="badge-status <?= $is_cancelada ? 'cancelada' : 'concluida' ?>">
                                                <?= $is_cancelada ? 'Cancelada' : 'Concluída' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 6px;">
                                                <button type="button" class="btn-table-action" onclick="abrirRecibo(<?= $v['id'] ?>)">
                                                    🖨️ Cupom
                                                </button>
                                                <?php if(!$is_cancelada): ?>
                                                    <form action="loja.php" method="POST" onsubmit="return confirm('Deseja ESTORNAR esta venda? O valor será anulado e os produtos voltarão ao estoque.');" style="margin:0;">
                                                        <input type="hidden" name="acao" value="cancelar_venda">
                                                        <input type="hidden" name="id_venda" value="<?= $v['id'] ?>">
                                                        <button type="submit" class="btn-table-action danger" title="Cancelar venda e repor estoque">
                                                            Cancelar
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                        Nenhuma venda registrada ainda no sistema.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL DE RECIBO / CUPOM TÉRMICO -->
    <div id="modalRecibo" class="modal-overlay">
        <div class="modal-recibo">
            <h3 style="font-size: 18px; font-weight: 800; color: #0f172a;">Cupom de Venda</h3>
            <div id="recibo-imprimir" class="recibo-ticket">
                <h4>UNEC - LOJA & CANTINA</h4>
                <div style="text-align:center; font-size:11px; margin-bottom:10px;">Comprovante de Compra</div>
                <div id="recibo-conteudo">Carregando...</div>
            </div>
            <div style="display:flex; gap:10px; justify-content:center;">
                <button type="button" onclick="window.print()" class="btn-checkout" style="width:auto; padding:10px 20px;">
                    🖨️ Imprimir
                </button>
                <button type="button" onclick="fecharRecibo()" class="btn-table-action" style="padding:10px 16px;">
                    Fechar
                </button>
            </div>
        </div>
    </div>

    <!-- SCRIPTS DINÂMICOS DO PDV -->
    <script>
        let carrinho = [];
        let categoriaAtiva = 'todas';

        function alternarAba(aba) {
            document.getElementById('view-pdv').style.display = (aba === 'pdv') ? 'grid' : 'none';
            document.getElementById('view-estoque').style.display = (aba === 'estoque') ? 'block' : 'none';
            document.getElementById('view-historico').style.display = (aba === 'historico') ? 'block' : 'none';

            document.getElementById('tab-pdv').classList.toggle('active', aba === 'pdv');
            document.getElementById('tab-estoque').classList.toggle('active', aba === 'estoque');
            document.getElementById('tab-historico').classList.toggle('active', aba === 'historico');
        }

        function adicionarAoCarrinho(id, nome, preco, estoque) {
            if (estoque <= 0) { 
                alert('Produto esgotado no estoque!'); 
                return; 
            }
            let item = carrinho.find(i => i.id === id);
            if (item) {
                if (item.qtd >= estoque) { 
                    alert('Limite do estoque disponível atingido!'); 
                    return; 
                }
                item.qtd++;
            } else {
                carrinho.push({ id, nome, preco: parseFloat(preco), qtd: 1, estoque: parseInt(estoque) });
            }
            renderizarCarrinho();
        }

        function alterarQtd(id, delta) {
            let item = carrinho.find(i => i.id === id);
            if (item) {
                item.qtd += delta;
                if (item.qtd > item.estoque) { 
                    alert('Estoque máximo atingido.'); 
                    item.qtd = item.estoque; 
                }
                if (item.qtd <= 0) { 
                    carrinho = carrinho.filter(i => i.id !== id); 
                }
            }
            renderizarCarrinho();
        }

        function limparCarrinho() { 
            carrinho = []; 
            document.getElementById('inputDesconto').value = '';
            document.getElementById('inputRecebido').value = '';
            renderizarCarrinho(); 
        }

        function renderizarCarrinho() {
            const container = document.getElementById('cartContainer');
            if (carrinho.length === 0) {
                container.innerHTML = '<div style="text-align: center; color: var(--text-muted); font-size: 13px; padding: 30px 10px;">Toque nos produtos ao lado para montar a venda.</div>';
                document.getElementById('carrinho_json').value = '';
                calcularTotais();
                return;
            }

            let html = '';
            carrinho.forEach(item => {
                let sub = item.preco * item.qtd;
                html += `
                    <div class="cart-row">
                        <div class="cart-row-info">
                            <strong>${item.nome}</strong>
                            <span>R$ ${item.preco.toFixed(2).replace('.', ',')} un</span>
                        </div>
                        <div class="cart-qty-ctrl">
                            <button type="button" class="btn-qty" onclick="alterarQtd(${item.id}, -1)">-</button>
                            <span style="font-weight: 700; width: 18px; text-align: center;">${item.qtd}</span>
                            <button type="button" class="btn-qty" onclick="alterarQtd(${item.id}, 1)">+</button>
                        </div>
                        <div class="cart-row-total">
                            R$ ${sub.toFixed(2).replace('.', ',')}
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
            document.getElementById('carrinho_json').value = JSON.stringify(carrinho);
            calcularTotais();
        }

        function calcularTotais() {
            let subtotal = 0;
            carrinho.forEach(i => subtotal += (i.preco * i.qtd));

            let descInput = document.getElementById('inputDesconto').value.replace('R$', '').replace(/\s/g, '').replace(',', '.');
            let desconto = parseFloat(descInput) || 0;
            if (desconto > subtotal) desconto = subtotal;

            let total = Math.max(0, subtotal - desconto);

            document.getElementById('cart-subtotal').innerText = 'R$ ' + subtotal.toFixed(2).replace('.', ',');
            
            const rowDesc = document.getElementById('row-desconto');
            if (desconto > 0) {
                rowDesc.style.display = 'flex';
                document.getElementById('cart-desconto-txt').innerText = '- R$ ' + desconto.toFixed(2).replace('.', ',');
            } else {
                rowDesc.style.display = 'none';
            }

            document.getElementById('cart-total').innerText = 'R$ ' + total.toFixed(2).replace('.', ',');

            // Cálculo de Troco
            const formaPag = document.getElementById('selectPagamento').value;
            const rowTroco = document.getElementById('row-troco');
            if (formaPag === 'Dinheiro') {
                let recInput = document.getElementById('inputRecebido').value.replace('R$', '').replace(/\s/g, '').replace(',', '.');
                let recebido = parseFloat(recInput) || 0;
                if (recebido > total) {
                    let troco = recebido - total;
                    rowTroco.style.display = 'flex';
                    document.getElementById('cart-troco-txt').innerText = 'R$ ' + troco.toFixed(2).replace('.', ',');
                } else {
                    rowTroco.style.display = 'none';
                }
            } else {
                rowTroco.style.display = 'none';
            }
        }

        function alternarCamposPagamento(metodo) {
            document.getElementById('box_dinheiro_recebido').style.display = (metodo === 'Dinheiro') ? 'block' : 'none';
            calcularTotais();
        }

        function verificarComprador(sel) {
            document.getElementById('box_nome_avulso').style.display = (sel.value === '') ? 'block' : 'none';
        }

        function filtrarCatalogo() {
            const termo = document.getElementById('filtroTexto').value.toLowerCase();
            const cards = document.querySelectorAll('.item-catalogo');

            cards.forEach(c => {
                const nome = c.getAttribute('data-nome');
                const cat = c.getAttribute('data-categoria');
                const bateTermo = nome.includes(termo);
                const bateCat = (categoriaAtiva === 'todas' || cat === categoriaAtiva);
                c.style.display = (bateTermo && bateCat) ? 'flex' : 'none';
            });
        }

        function filtrarCategoria(cat, btn) {
            categoriaAtiva = cat;
            document.querySelectorAll('.chip').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            filtrarCatalogo();
        }

        function confirmarVendaPDV() {
            if (carrinho.length === 0) { 
                alert('Adicione ao menos um produto no carrinho antes de finalizar.'); 
                return; 
            }
            const totalTexto = document.getElementById('cart-total').innerText;
            if (confirm('Deseja concluir a venda no valor de ' + totalTexto + '?')) {
                const btn = document.querySelector('.btn-checkout');
                if (btn) {
                    btn.disabled = true;
                    btn.style.opacity = '0.6';
                    btn.innerText = '⏳ Concluindo Venda...';
                }
                document.getElementById('formVenda').submit();
            }
        }

        function editarProduto(prod) {
            alternarAba('estoque');
            document.getElementById('edit_id_produto').value = prod.id;
            document.getElementById('edit_nome').value = prod.nome;
            document.getElementById('edit_cat').value = prod.categoria || 'Geral';
            document.getElementById('edit_preco').value = parseFloat(prod.preco).toFixed(2).replace('.', ',');
            document.getElementById('edit_estoque').value = prod.estoque;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function limparFormProduto() {
            document.getElementById('edit_id_produto').value = '';
            document.getElementById('edit_nome').value = '';
            document.getElementById('edit_preco').value = '';
            document.getElementById('edit_estoque').value = '10';
        }

        function abrirRecibo(idVenda) {
            const modal = document.getElementById('modalRecibo');
            const conteudo = document.getElementById('recibo-conteudo');
            conteudo.innerHTML = `
                <div>Venda: #${idVenda}</div>
                <div>Data: ${new Date().toLocaleDateString('pt-BR')} ${new Date().toLocaleTimeString('pt-BR')}</div>
                <hr style="border:1px dashed #ccc; margin:8px 0;">
                <div>Consulte o histórico para reimpressão detalhada.</div>
            `;
            modal.style.display = 'flex';
        }

        function fecharRecibo() {
            document.getElementById('modalRecibo').style.display = 'none';
        }

        // Atalho F10 para finalizar venda
        window.addEventListener('keydown', (e) => {
            if (e.key === 'F10') {
                e.preventDefault();
                confirmarVendaPDV();
            }
        });
    </script>
</body>
</html>