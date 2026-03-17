# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

NPMplus 导航页 — a single-page PHP dashboard that displays proxy hosts from NPMplus and documentation files. Uses NPMplus REST API as primary data source, with SQLite as fallback.

## Commands

```bash
# Build Docker image
docker compose build

# Start container (detached)
docker compose up -d

# View logs
docker compose logs -f

# Restart after editing index.php (no rebuild needed — file is volume-mounted)
docker compose restart
```

No test framework is configured. The app runs at `http://127.0.0.1:8085` locally.

## Configuration

Before first run, create the required config files:

```bash
cp config.php.example config.php   # fill in NPMplus API credentials
cp .env.example .env               # set NPM_DATA_PATH and GDOCS_PATH
```

- `config.php` — NPMplus API `api_url`, `email`, `password`
- `.env` — volume mount paths: `NPM_DATA_PATH` (NPMplus data dir), `GDOCS_PATH` (Google Drive Docs dir)

Both files are gitignored.

## Architecture

**Single-file app**: all logic is in `index.php`. No framework, no build step.

**Data flow**:
1. PHP fetches proxy hosts from NPMplus API (`/api/nginx/proxy-hosts`) using cURL + JWT auth
2. On API failure, falls back to direct SQLite query (`/data/npmplus/database.sqlite`)
3. Enabled hosts are filtered, sorted by ID, and rendered as service cards
4. A second tab scans `/docs/*.html` (Apache alias → `GDOCS_PATH`) and lists docs by mtime

**Docker setup** (`compose.yml` + `Dockerfile`):
- Base: `php:8.3-apache` with `mod_alias` enabled
- `index.php` and `config.php` are volume-mounted (edits take effect without rebuild)
- `/data` → NPMplus data dir (read-only), `/docs` → Google Drive Docs (read-only)
- `docs.conf` configures the Apache `/docs` alias inside the container

**Frontend**: vanilla HTML/CSS/JS inline in `index.php`. Shanghai timezone clock rendered via JS.
