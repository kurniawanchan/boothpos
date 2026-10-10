-- Mounted at MySQL's /docker-entrypoint-initdb.d/. Only runs on first
-- container start against an empty data volume (research.md R4).
-- Matches the two databases this app already uses natively:
--   warkop_wjm      -> .env (dev)
--   warkop_wjm_test -> .env.testing (test, wiped by RefreshDatabase)
-- Sengaja BUKAN boothpos/boothpos_test: salinan proyek ini hidup terpisah
-- dari /Users/chan/www/boothpos dan tidak boleh menyentuh databasenya.
-- Tanpa baris CREATE DATABASE ini, volume baru gagal migrate dengan
-- "Unknown database 'warkop_wjm'".
CREATE DATABASE IF NOT EXISTS warkop_wjm;
CREATE DATABASE IF NOT EXISTS warkop_wjm_test;
