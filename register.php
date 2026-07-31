<?php
session_start();
require_once __DIR__ . '/db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim(filter_input(INPUT_POST, 'usuario', FILTER_SANITIZE_STRING));
    $senha = trim(filter_input(INPUT_POST, 'senha', FILTER_UNSAFE_RAW));
    $saldo = filter_input(INPUT_POST, 'saldo', FILTER_VALIDATE_FLOAT);

    if ($usuario === '' || $senha === '') {
        $error = 'Informe usuário e senha.';
    } elseif (mb_strlen($usuario) > 100) {
        $error = 'O usuário deve ter no máximo 100 caracteres.';
    } elseif ($saldo === false || $saldo < 0) {
        $error = 'Saldo inválido.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ?');
        $stmt->execute([$usuario]);

        if ($stmt->fetch()) {
            $error = 'Esse usuário já existe.';
        } else {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $insert = $pdo->prepare('INSERT INTO usuarios (usuario, senha, saldo_cliente) VALUES (?, ?, ?)');
            $insert->execute([$usuario, $hash, $saldo !== null ? $saldo : 0.00]);
            $success = 'Cadastro realizado com sucesso. Faça login agora.';
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supraserver - Painel de IMEI e Desbloqueio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h1 class="h4 mb-4 text-center">Cadastrar-se na Supraserver</h1>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>

                    <form method="post" novalidate>
                        <div class="mb-3">
                            <label for="usuario" class="form-label">Usuário</label>
                            <input type="text" class="form-control" id="usuario" name="usuario" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label for="senha" class="form-label">Senha</label>
                            <input type="password" class="form-control" id="senha" name="senha" required>
                        </div>
                        <div class="mb-3">
                            <label for="saldo" class="form-label">Saldo inicial</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="saldo" name="saldo" value="0.00">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Registrar</button>
                    </form>

                    <div class="mt-4 text-center">
                        <a href="login.php" class="link-secondary">Já tenho conta</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
