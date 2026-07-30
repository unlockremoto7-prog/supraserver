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

3) Secrets to set in GitHub repository
- `GITHUB_TOKEN` — GitHub Actions token (automatically available, no extra secret needed for registry login)

Optional (choose one provider)
- For Render:
  - `RENDER_SERVICE_ID` — Render service id
  - `RENDER_API_KEY` — Render API key

- For Fly.io:
  - `FLY_ORG` — Fly organization (optional)
  - `FLY_API_TOKEN` — Fly API token

4) Deploy flow
- On push to `main` or `master`, GitHub Actions builds the image and pushes it to Docker Hub. If Render or Fly secrets are set, subsequent deploy steps run.

5) After deploy
- Obtain the public IP or hostname of the deployed service (Render/Fly will provide). Add that IP to gsm-imei.com → Profile → API Access.

Notes and next steps
- If you prefer not to use Docker Hub, modify the workflow to push directly to Render's private registry or to GitHub Container Registry (`ghcr.io`).
- For production use, secure environment variables and update `config.php` to use production DB credentials.

*** End Patch