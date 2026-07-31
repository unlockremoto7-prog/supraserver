<?php
session_start();

require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentUserId = (int) $_SESSION['user_id'];
if ($currentUserId !== 1) {
    http_response_code(403);
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Acesso negado</title></head><body><h1>Acesso negado</h1><p>Você não tem permissão para acessar esta página.</p></body></html>';
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_balance') {
    $targetUserId = filter_input(INPUT_POST, 'target_user_id', FILTER_VALIDATE_INT);
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);

    if ($targetUserId === false || $targetUserId <= 0) {
        $error = 'Usuário inválido.';
    } elseif ($amount === false || $amount <= 0) {
        $error = 'Informe um valor válido para adicionar ao saldo.';
    } else {
        $update = $pdo->prepare('UPDATE usuarios SET saldo_cliente = saldo_cliente + ? WHERE id = ?');
        if ($update->execute([$amount, $targetUserId])) {
            $message = 'Saldo atualizado com sucesso para o usuário selecionado.';
        } else {
            $error = 'Não foi possível atualizar o saldo. Tente novamente.';
        }
    }
}

$usersStmt = $pdo->prepare('SELECT id, usuario, saldo_cliente FROM usuarios ORDER BY id ASC');
$usersStmt->execute();
$users = $usersStmt->fetchAll();

$ordersStmt = $pdo->prepare('SELECT p.id, p.usuario_id, u.usuario AS usuario_nome, p.imei, p.servico_id, p.referencia, p.status, p.data_pedido, p.resposta_api FROM pedidos p LEFT JOIN usuarios u ON p.usuario_id = u.id ORDER BY p.data_pedido DESC LIMIT 200');
$ordersStmt->execute();
$orders = $ordersStmt->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supraserver - Administração</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="admin.php"><img src="logo.png" alt="" title="Supraserver" style="height: 40px; margin-right: 10px; vertical-align: middle;" onerror="this.style.display='none';">Supraserver Admin</a>
        <div class="d-flex align-items-center">
            <span class="navbar-text text-white me-3">Admin ID: <?php echo htmlspecialchars($currentUserId, ENT_QUOTES, 'UTF-8'); ?></span>
            <a href="index.php" class="btn btn-outline-light btn-sm me-2">Voltar ao painel</a>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Sair</a>
        </div>
    </div>
</nav>
<div class="container py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h1 class="h4 mb-3">Administração Supraserver</h1>
                    <p class="text-muted">Aqui você pode gerenciar usuários, ajustar saldos e acompanhar todos os pedidos de IMEI enviados.</p>
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-12">
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h5 mb-3">Usuários cadastrados</h2>
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Usuário</th>
                                    <th>Saldo</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr><td colspan="4" class="text-center text-muted">Nenhum usuário cadastrado.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($users as $userRow): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($userRow['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($userRow['usuario'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>R$ <?php echo number_format($userRow['saldo_cliente'], 2, ',', '.'); ?></td>
                                            <td>
                                                <form method="post" class="row gx-2 gy-2 align-items-center">
                                                    <input type="hidden" name="action" value="add_balance">
                                                    <input type="hidden" name="target_user_id" value="<?php echo htmlspecialchars($userRow['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <div class="col-auto" style="min-width: 170px;">
                                                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" placeholder="Valor a adicionar" required>
                                                    </div>
                                                    <div class="col-auto">
                                                        <button type="submit" class="btn btn-primary btn-sm">Adicionar Saldo</button>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-3">Todos os pedidos de IMEI</h2>
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Cliente</th>
                                    <th>IMEI</th>
                                    <th>Serviço</th>
                                    <th>Referência</th>
                                    <th>Status</th>
                                    <th>Data</th>
                                    <th>Resposta</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr><td colspan="8" class="text-center text-muted">Nenhum pedido registrado.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($order['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($order['usuario_nome'] ?: ('ID ' . $order['usuario_id']), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($order['imei'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($order['servico_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($order['referencia'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($order['data_pedido'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><pre class="mb-0"><?php echo htmlspecialchars($order['resposta_api'], ENT_QUOTES, 'UTF-8'); ?></pre></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
