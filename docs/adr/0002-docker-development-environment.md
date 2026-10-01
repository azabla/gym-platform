# ADR 0002: Custom Docker Compose development environment

- **Status:** Accepted
- **Date:** 2026-09-27

## Context

Every developer needs the same PHP version, extensions, PostgreSQL, Redis, mail catcher and object storage, without installing them on their own machine. "It works on my machine" bugs cost far more than the setup.

Options considered:

1. **Local installs** (Homebrew, apt, XAMPP). Rejected: versions drift between machines and from production.
2. **Laravel Sail.** Quick to start, but its Dockerfile is generic and hidden in `vendor/`, and it is designed for development only. Its MinIO option also no longer works (see below).
3. **Our own Dockerfile and Compose file.** A little more work up front, but every line is ours and understood, and the same Dockerfile grows a production stage later.

## Decision

Use our own `docker/php/Dockerfile` (multi-stage: `base`, `dev`, later `prod`) and `compose.yaml`, with a `Makefile` for common commands.

- PHP 8.4 FPM with only the extensions we need, installed via `install-php-extensions`.
- Separate containers for web (`app` + `nginx`), `queue` and `scheduler`, mirroring how production runs them, all from the same image.
- The container user matches the host UID/GID, so generated files are not owned by root.
- Tests run against a separate PostgreSQL database, `gym_testing`, never SQLite.
- Ports bind to `127.0.0.1` only.
- Compose reads Laravel's `.env`, so credentials are defined once.
- Object storage is **SeaweedFS** (Apache-2.0, S3-compatible), not MinIO.

### Why not MinIO

The original architecture specified MinIO for local S3. MinIO stopped publishing community Docker images in October 2025 and archived its community repository in 2026. In September 2026 the `minio/minio` images were removed from Docker Hub entirely. Pinned old images would receive no security fixes.

Because Laravel talks to storage through the S3 API, the choice only affects configuration: production can use Cloudflare R2, AWS S3 or any S3-compatible service with no code changes.

## Consequences

- One command (`make up`) starts an environment close to production.
- We own the Dockerfile, so we must maintain it: update the PHP and service image versions deliberately.
- Image tags are pinned to a major version (`postgres:17`, `redis:7`, `nginx:1.27`). SeaweedFS starts on `latest` and must be pinned to a concrete version after the first pull.
- Windows developers need WSL2.
