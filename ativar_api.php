<?php
// ativar_api.php
// Script temporário para forçar o envio via navegador do IP real do usuário ao fornecedor.
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ativar API - Supraserver</title>
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f5f7; color: #212529; }
        .container { display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 20px; }
        .card { width: 100%; max-width: 620px; background: #ffffff; border-radius: 14px; box-shadow: 0 18px 40px rgba(0,0,0,0.08); padding: 30px; }
        h1 { margin-top: 0; font-size: 1.8rem; }
        p { line-height: 1.6; }
        .status { margin-top: 18px; font-weight: 600; }
        button { display: inline-flex; align-items: center; justify-content: center; padding: 12px 18px; border: none; border-radius: 8px; background: #0d6efd; color: #fff; font-size: 1rem; cursor: pointer; }
        button:hover { background: #0b5ed7; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>Ativar API</h1>
        <p>Este script gera uma requisição POST através do seu navegador para <strong>https://gsm-imei.com</strong>. Assim, o fornecedor verá o seu IP real e a auto-atribuição poderá ser acionada.</p>
        <p class="status" id="status">Aguardando envio...</p>

        <form id="activateForm" action="https://gsm-imei.com/api/index.php" method="post" style="display:none;">
            <input type="hidden" name="username" value="samuel.fiel2012@gmail.com">
            <input type="hidden" name="apiaccesskey" value="53J-WJQ-UFK-MUL-XA1-K5M-8AF-B4W">
            <input type="hidden" name="action" value="getaccountbalance">
            <input type="hidden" name="requestformat" value="JSON">
            <input type="hidden" name="parameters" value="<PARAMETERS></PARAMETERS>">
        </form>

        <script>
            const status = document.getElementById('status');
            status.textContent = 'Preparando requisição...';
            window.addEventListener('load', function () {
                const form = document.getElementById('activateForm');
                if (!form) {
                    status.textContent = 'Formulário não encontrado.';
                    return;
                }
                status.textContent = 'Requisição pronta. Enviando em instantes...';
                setTimeout(function () {
                    form.submit();
                }, 1000);
            });
        </script>

        <noscript>
            <p>Seu navegador precisa suportar JavaScript para envio automático. Caso não funcione, use o botão abaixo.</p>
            <form action="https://gsm-imei.com/api/index.php" method="post">
                <input type="hidden" name="username" value="samuel.fiel2012@gmail.com">
                <input type="hidden" name="apiaccesskey" value="53J-WJQ-UFK-MUL-XA1-K5M-8AF-B4W">
                <input type="hidden" name="action" value="getaccountbalance">
                <input type="hidden" name="requestformat" value="JSON">
                <input type="hidden" name="parameters" value="<PARAMETERS></PARAMETERS>">
                <button type="submit">Enviar requisição manualmente</button>
            </form>
        </noscript>
    </div>
</div>
</body>
</html>
