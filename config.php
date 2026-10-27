<?php
$host = 'localhost';
$usuario = 'brun5614_brunoformulario';
$senha = 'B1b2b3b4@bruno'; // Altere caso sua senha do MySQL seja diferente
$banco = 'brun5614_formulario_unec'; // Nome da base de dados criada para o projeto


// Criação da conexão utilizando MySQLi
$conn = new mysqli($host, $usuario, $senha, $banco, $porta);

// Verifica se ocorreu algum erro de conexão
if ($conn->connect_error) {
    die("Erro na conexão com o banco de dados: " . $conn->connect_error);
}

// Define o charset padrão para utf8mb4 para suportar acentuação corretamente
$conn->set_charset("utf8mb4");
?>