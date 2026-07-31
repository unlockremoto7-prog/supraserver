Deployment guide — Docker + GitHub Actions

Overview
- This project contains a PHP/Apache app in the `api/` folder. The repository includes a `Dockerfile`, `docker-compose.yml` for local testing, and a GitHub Actions workflow `.github/workflows/deploy-container.yml` that builds a container image and can deploy to Render or Fly.io.

1) Local testing with Docker Compose
- Requirements: Docker and Docker Compose installed.
- Start services:

```bash
docker compose up --build
```

- The web app will be available at http://localhost:8080

2) Prepare GitHub repository
- Push your code to a GitHub repo and ensure the `Dockerfile` and `.github/workflows/deploy-container.yml` are in the repository root.

3) Supabase & Render (recommended setup)

- Supabase (Postgres)
  - Create a project on Supabase and open the SQL Editor.
  - Run the SQL in `sql/supabase_schema.sql` (or the SQL shown below) to create `usuarios` and `pedidos` tables.
  - From Project → Settings → Database → Connection string, copy host, port, database name, user and password.

- Render (backend container)
  - We'll deploy the built image from `ghcr.io/<your-github-username>/supraserver-api:latest`.
  - In Render, create a new Web Service → Select "Private Docker Registries" → Enter the GHCR image URL and choose the branch to auto-deploy (main).
  - In the Render service settings, set Environment → Add Environment Variables using these names:
    - `DB_HOST` — Supabase DB host
    - `DB_PORT` — 5432
    - `DB_NAME` — database name
    - `DB_USER` — database user
    - `DB_PASSWORD` — database password
  - Optionally configure health checks and scale settings. Deploy.

Notes on GHCR
- The workflow builds and pushes the image to GHCR at `ghcr.io/${{ github.repository_owner }}/supraserver-api:latest`. You do not need additional registry secrets; Actions uses `GITHUB_TOKEN` for authentication.

4) Deploy flow (Supabase + Render)
- Push to `main` → Actions builds image and publishes to GHCR.
- On Render, configure a Web Service to pull the GHCR image above, add `DB_*` env vars and deploy.
- After deploy, retrieve the public IP or hostname for the Render service and add it to the provider panel (gsm-imei.com → Profile → API Access).

5) SQL to create tables (run in Supabase SQL Editor)
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

6) Local quick test
- Use `docker compose up --build` to run locally and test `http://localhost:8080`.

7) After IP authorization
- Reopen `tools/debug_api.php` on the deployed host to verify Dhru Fusion API access.

Security note
- Never commit production secrets. Use Render's Environment settings (or GitHub Secrets for CI) to store credentials.


4) Deploy flow
- On push to `main` or `master`, GitHub Actions builds the image and pushes it to Docker Hub. If Render or Fly secrets are set, subsequent deploy steps run.

5) After deploy
- Obtain the public IP or hostname of the deployed service (Render/Fly will provide). Add that IP to gsm-imei.com → Profile → API Access.

Notes and next steps
- If you prefer not to use Docker Hub, modify the workflow to push directly to Render's private registry or to GitHub Container Registry (`ghcr.io`).
- For production use, secure environment variables and update `config.php` to use production DB credentials.

*** End Patch