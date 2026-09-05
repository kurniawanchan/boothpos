-- Mounted at MySQL's /docker-entrypoint-initdb.d/. Only runs on first
-- container start against an empty data volume (research.md R4).
-- Matches the two databases this app already uses natively:
--   boothpos      -> .env (dev)
--   boothpos_test -> .env.testing (test, wiped by RefreshDatabase)
CREATE DATABASE IF NOT EXISTS boothpos;
CREATE DATABASE IF NOT EXISTS boothpos_test;
