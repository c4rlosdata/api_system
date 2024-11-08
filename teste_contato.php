<?php
// Configurações de banco de dados
$dbHost = 'mysql.railway.internal';
$dbUser = 'root';
$dbPass = 'zJoulsdBIXJDaSpvJEuNVJinTmIRijjh';
$dbName = 'railway';

// Conectando ao banco de dados MySQL
$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

// Verificar a conexão
if ($conn->connect_error) {
    die("Falha na conexão com o banco de dados: " . $conn->connect_error);
} else {
    echo "Conexão bem-sucedida!<br>";
}

// Executar uma consulta para listar os dados da tabela contatos
$sql = "SELECT * FROM contatos";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    // Exibir os dados da tabela
    while ($row = $result->fetch_assoc()) {
        echo "ID: " . $row["codigo_contato"] . " - Cliente: " . $row["cliente"] . "<br>";
    }
} else {
    echo "Nenhum dado encontrado na tabela 'contatos'.";
}

$conn->close();
