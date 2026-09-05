# Phase 1 Data Model: Dockerize Local Development Environment

No business/domain entities are introduced, changed, or persisted differently by this feature.
It is purely development tooling — container images, a Compose network, and configuration
files. The two existing databases this feature touches (`boothpos`, `boothpos_test`) already
exist under the native setup; this feature only reproduces their creation inside a MySQL
container instead of requiring a contributor to create them by hand (see research.md R4).

## Key Entities (spec.md cross-reference)

- **Development environment configuration**: `docker-compose.yml`, `docker/php/Dockerfile`,
  `docker/php/entrypoint.sh`, `docker/node/Dockerfile`, `docker/mysql/init.sql`,
  `.env.docker.example`, `.dockerignore` — all new, purely additive files. None represent or
  store business data; none are read by the application at runtime (they configure the
  environment the application runs *in*).
