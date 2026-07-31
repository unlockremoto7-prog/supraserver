<?php
session_start();
require_once __DIR__ . '/db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim(filter_input(INPUT_POST, 'usuario', FILTER_SANITIZE_STRING));
    $senha = trim(filter_input(INPUT_POST, 'senha', FILTER_UNSAFE_RAW));

    if ($usuario === '' || $senha === '') {
        $error = 'Informe usuário e senha.';
    } else {
        $stmt = $pdo->prepare('SELECT id, usuario, senha FROM usuarios WHERE usuario = ?');
        $stmt->execute([$usuario]);
        $user = $stmt->fetch();

        if ($user && password_verify($senha, $user['senha'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['usuario'] = $user['usuario'];
            header('Location: index.php');
            exit;
        }

        $error = 'Usuário ou senha inválidos.';
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
                    <h1 class="h4 mb-4 text-center">Acessar Supraserver</h1>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
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
                        <button type="submit" class="btn btn-primary w-100">Entrar</button>
                    </form>

                    <div class="mt-4 text-center">
                        <a href="register.php" class="link-secondary">Criar nova conta</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
