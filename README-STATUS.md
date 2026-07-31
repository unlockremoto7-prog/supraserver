README-STATUS

Este arquivo foi criado para documentar o estado exato do projeto e permitir que o trabalho continue em outro computador.

1) O que já foi alterado no código
- `db.php`: migrado de MySQL para PostgreSQL usando PDO e variáveis de ambiente.
  - Agora usa `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`.
  - O erro de conexão não é impresso na saída, apenas registrado, para não quebrar `session_start()`.
- Scripts de pedido ajustados para PostgreSQL:
  - `place_imei_order.php`
  - `place_server_order.php`
  - `place_remote_order.php`
  - Essas rotinas agora usam `INSERT ... RETURNING id` e `fetchColumn()` em vez de `lastInsertId()`.
- `index.php`: adicionada busca de serviços, dados de preço/quantidade nos cartões, campo de quantidade e cálculo de depósito dinâmico no formulário de pedido.
- `Dockerfile` e `docker-compose.yml`: criados para containerizar a aplicação PHP e um banco MySQL local para testes.
- `docker-compose.override.yml`: adicionado `APACHE_DOCUMENT_ROOT=/var/www/html/api` para rodar a aplicação a partir da pasta `/api/`.
- `.env.example`: gerado para mapear variáveis de ambiente necessárias.
- `.github/workflows/deploy-container.yml`: criado workflow para build do container e deploy opcional, agora configurado para enviar a imagem para GHCR (`ghcr.io/<usuario>/supraserver-api:latest`).
- `README-DEPLOY.md`: atualizado com instruções específicas para Supabase + Render, incluindo o SQL de criação das tabelas e o fluxo de deploy.
- `tools/debug_api.php`: criado endpoint de debug para verificar respostas da Dhru Fusion API e registrar logs.

2) Estado atual exato
- O código está pronto localmente, mas não foi enviado para o GitHub nesta máquina porque o Git não está instalado.
- A etapa travou antes do `git push origin main`.
- O GHCR ainda não recebeu a imagem porque o workflow não foi disparado via GitHub Actions.
- O Supabase ainda não recebeu o SQL para criar as tabelas `usuarios` e `pedidos`.
- O Render ainda não foi configurado com a imagem do GHCR e as variáveis de ambiente do Supabase.
- O IP/hostname do serviço ainda não foi liberado no painel da gsm-imei.

3) Roteiro passo a passo no novo computador

A) Preparar o repositório e enviar para o GitHub
1. Abra o VS Code na pasta do projeto.
2. No terminal do VS Code, execute:

```powershell
cd <caminho-da-pasta-do-projeto>
git init
git add .
git commit -m "garantindo sincronizacao total de arquivos para o deploy"
git branch -M main
git remote add origin https://github.com/unlockremoto7-prog/supraserver.git
git push -u origin main
```

- Se o repositório já estiver inicializado, pule `git init` e `git remote add origin`.

B) Verificar o GitHub Action
1. No GitHub, abra o repositório e vá em Actions.
2. Verifique o workflow `Build and Deploy container`.
3. Confirme que a etapa `Build and push image` foi concluída com sucesso.
4. Confirme que a imagem foi publicada em `ghcr.io/<seu-usuario>/supraserver-api:latest`.

C) Criar o banco de dados no Supabase
1. No Supabase, abra o projeto e vá para o SQL Editor.
2. Cole e execute o SQL:

```sql
CREATE TABLE IF NOT EXISTS usuarios (
  id serial PRIMARY KEY,
  usuario text NOT NULL UNIQUE,
  senha text NOT NULL,
  saldo_cliente numeric(15,2) NOT NULL DEFAULT 0.00,
  criado_em timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS pedidos (
  id serial PRIMARY KEY,
  usuario_id integer NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  imei text,
  servico_id text NOT NULL,
  referencia text,
  status text NOT NULL,
  resposta_api jsonb NOT NULL,
  data_pedido timestamptz NOT NULL DEFAULT now()
);
```

3. Copie os dados de conexão do Supabase: host, port, database, user, password.

D) Configurar Render
1. No Render, crie um novo Web Service.
2. Escolha Deploy com Docker e selecione a imagem GHCR: `ghcr.io/<seu-usuario>/supraserver-api:latest`.
3. No painel do serviço, adicione as variáveis de ambiente:
   - `DB_HOST`
   - `DB_PORT` = `5432`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASSWORD`
4. Deploy e aguarde a conclusão.

E) Liberar o IP/hostname em gsm-imei
1. Pegue o hostname público do serviço Render.
2. Se precisar do IP, use no terminal:

```powershell
nslookup <hostname-do-render>
```

3. Adicione o IP (ou IPs) em gsm-imei.com → Profile → API Access.

F) Testar o deploy e a API
1. Acesse no navegador:

```
https://<hostname-do-render>/api/tools/debug_api.php
```

2. Verifique se responde com JSON da Dhru Fusion, sem erro de IP.

G) Ajuste final
- Se o host estiver autorizado e a API respondendo, você pode executar ou validar `tools/extract_services.php` para mapear serviços.

Observação final
- O arquivo `README-DEPLOY.md` já contém instruções detalhadas. Use este `README-STATUS.md` apenas como o guia rápido e de transição para o novo computador.

---

Quando você abrir este projeto no outro computador, comece exatamente pelos comandos Git acima e me avise assim que o push estiver feito. A partir daí eu guio a verificação do workflow e do deploy no Render.
