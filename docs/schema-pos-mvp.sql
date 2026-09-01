-- =====================================================================
-- KLASIFIKASI: INTERNAL
-- Skema Database MySQL — BoothPOS
-- Sistem POS Event-Based Multi-Artist untuk Toko Merchandise
-- Cakupan: MVP Oktober 2026 (termasuk pre-order dan pengiriman kurir)
-- Versi: v1.2
-- Tanggal: 30 Agustus 2026
--
-- ASSUMPTION: MySQL 8.0 atau lebih baru (dibutuhkan untuk CHECK constraint
-- dan collation utf8mb4_0900_ai_ci). Bila memakai MySQL 5.7 atau MariaDB,
-- ganti collation ke utf8mb4_unicode_ci dan pindahkan seluruh CHECK
-- constraint ke validasi aplikasi karena diabaikan diam-diam di 5.7.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- KEPUTUSAN DESAIN
-- =====================================================================
-- 1. Primary key memakai BIGINT UNSIGNED AUTO_INCREMENT, bukan UUID.
--    Sistem berjalan pada satu instalasi localhost sehingga tidak ada
--    risiko tabrakan ID antar perangkat. Bila kelak ada multi-perangkat,
--    kolom `local_ref` pada tabel transaksi menjadi jalur rekonsiliasi.
--
-- 2. Nilai uang memakai DECIMAL(14,2), bukan FLOAT. FLOAT menimbulkan
--    galat pembulatan yang tidak dapat diterima pada rekap hasil artist.
--
-- 3. Stok disimpan dua tempat: `product_variants.current_stock` sebagai
--    nilai cepat baca, dan `stock_movements` sebagai buku besar yang
--    menjadi sumber kebenaran. Setiap perubahan stok WAJIB menulis
--    stock_movements di dalam transaksi database yang sama.
--
-- 4. Kode 12 karakter adalah kode VARIAN, bukan kode produk. Produk
--    memegang prefix 8 karakter (artist 3 + kategori 2 + produk 3),
--    varian menambahkan 4 digit urutan. Ini menyelesaikan benturan
--    antara batas 12 karakter dan kebutuhan kode unik per varian.
--
-- 5. order_items menyimpan snapshot: artist_id, sku, nama, cost_price,
--    dan sell_price pada saat transaksi. Perubahan master data di
--    kemudian hari tidak boleh mengubah laporan historis.
--
-- 6. Soft delete (deleted_at) hanya pada master data. Tabel transaksi
--    tidak boleh dihapus, hanya dibatalkan lewat kolom status.
--
-- 7. Pembayaran memakai dua foreign key nullable (order_id, preorder_id)
--    dengan CHECK tepat satu terisi, bukan relasi polimorfik. Ini menjaga
--    integritas referensial tetap ditegakkan database.
-- =====================================================================


-- =====================================================================
-- BAGIAN 1 — PENGGUNA & KONFIGURASI
-- =====================================================================

CREATE TABLE users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(100)    NOT NULL,
  username          VARCHAR(50)     NOT NULL,
  password          VARCHAR(255)    NOT NULL COMMENT 'Hash bcrypt/argon2. Tidak pernah menyimpan kata sandi polos.',
  role              ENUM('owner','admin','cashier','inventory') NOT NULL DEFAULT 'cashier',
  is_active         TINYINT(1)      NOT NULL DEFAULT 1,
  last_login_at     TIMESTAMP       NULL,
  remember_token    VARCHAR(100)    NULL,
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  deleted_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_users_username (username),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE settings (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`             VARCHAR(100)    NOT NULL,
  value             TEXT            NULL,
  type              ENUM('string','integer','decimal','boolean','json') NOT NULL DEFAULT 'string',
  `group`           VARCHAR(50)     NOT NULL DEFAULT 'general' COMMENT 'general, receipt, product_code, storage',
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_settings_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
-- KEY LISENSI PENTING — 'multi_artist_enabled' (type=boolean, group=licensing).
-- Membedakan instalasi Pro (single-artist) vs Master (multi-artist).
-- TIDAK ada tabel/kolom terpisah untuk ini secara sengaja — satu baris
-- key-value ini yang membedakan dua tingkat harga BoothPOS, ditegakkan
-- di application layer (ArtistPolicy), bukan di skema. Lihat PRD 7.3 F3.6-F3.9.

-- Kanal pembayaran non-tunai: rekening bank dan QR e-wallet.
-- CATATAN KEAMANAN (area risiko: perlindungan data) — nomor rekening
-- adalah data sensitif. Tabel ini tidak boleh ikut dalam ekspor apa pun,
-- dan aksesnya dibatasi pada peran owner/admin.
CREATE TABLE payment_channels (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type              ENUM('bank_transfer','qr_ewallet') NOT NULL,
  provider          VARCHAR(50)     NOT NULL COMMENT 'Contoh: BCA, Mandiri, atau nama penyedia e-wallet',
  account_name      VARCHAR(100)    NOT NULL COMMENT 'Nama pemilik rekening',
  account_number    VARCHAR(50)     NULL     COMMENT 'Diisi untuk bank_transfer. Kosong untuk qr_ewallet.',
  qr_image_path     VARCHAR(255)    NULL     COMMENT 'Diisi untuk qr_ewallet. Path lokal, bukan URL publik.',
  display_order     SMALLINT        NOT NULL DEFAULT 0,
  is_active         TINYINT(1)      NOT NULL DEFAULT 1,
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  deleted_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  KEY idx_payment_channels_active (is_active, display_order),
  CONSTRAINT chk_payment_channel_detail CHECK (
    (type = 'bank_transfer' AND account_number IS NOT NULL) OR
    (type = 'qr_ewallet'    AND qr_image_path  IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE activity_logs (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           BIGINT UNSIGNED NULL,
  action            VARCHAR(50)     NOT NULL COMMENT 'created, updated, deleted, stock_adjusted, price_changed',
  entity_type       VARCHAR(50)     NOT NULL,
  entity_id         BIGINT UNSIGNED NULL,
  description       VARCHAR(255)    NULL,
  old_values        JSON            NULL,
  new_values        JSON            NULL,
  created_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  KEY idx_logs_entity (entity_type, entity_id),
  KEY idx_logs_user_time (user_id, created_at),
  CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- =====================================================================
-- BAGIAN 2 — MASTER DATA
-- =====================================================================

-- Artist: pemilik merchandise yang dijual. Menerima hasil penjualan.
CREATE TABLE artists (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code              CHAR(3)         NOT NULL COMMENT 'Segmen 1 kode varian. Huruf kapital. Permanen.',
  name              VARCHAR(100)    NOT NULL,
  contact_phone     VARCHAR(30)     NULL,
  contact_email     VARCHAR(100)    NULL,
  notes             TEXT            NULL,
  is_active         TINYINT(1)      NOT NULL DEFAULT 1,
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  deleted_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_artists_code (code),
  KEY idx_artists_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE categories (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code              CHAR(2)         NOT NULL COMMENT 'Segmen 2 kode varian. Contoh: KY untuk keychain.',
  name              VARCHAR(100)    NOT NULL,
  parent_id         BIGINT UNSIGNED NULL,
  display_order     SMALLINT        NOT NULL DEFAULT 0,
  is_active         TINYINT(1)      NOT NULL DEFAULT 1,
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  deleted_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_categories_code (code),
  KEY idx_categories_parent (parent_id),
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE products (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  artist_id         BIGINT UNSIGNED NOT NULL,
  category_id       BIGINT UNSIGNED NOT NULL,
  code_prefix       CHAR(8)         NOT NULL COMMENT 'artist.code(3) + category.code(2) + product_segment(3). Permanen.',
  product_segment   CHAR(3)         NOT NULL COMMENT 'Singkatan nama produk. Dapat disunting sebelum ada transaksi.',
  name              VARCHAR(150)    NOT NULL,
  description       TEXT            NULL,
  image_path        VARCHAR(255)    NULL,
  is_preorder       TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Produk dijual sebagai pre-order, bukan stok ready',
  preorder_eta      DATE            NULL,
  is_active         TINYINT(1)      NOT NULL DEFAULT 1,
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  deleted_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_products_prefix (code_prefix),
  KEY idx_products_artist (artist_id),
  KEY idx_products_category (category_id),
  KEY idx_products_active (is_active, is_preorder),
  CONSTRAINT fk_products_artist   FOREIGN KEY (artist_id)   REFERENCES artists(id)    ON DELETE RESTRICT,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE product_variants (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id        BIGINT UNSIGNED NOT NULL,
  sku               CHAR(12)        NOT NULL COMMENT 'code_prefix(8) + urutan 4 digit. Contoh: RYUKYSAK0007. Permanen.',
  variant_name      VARCHAR(100)    NOT NULL DEFAULT 'Standard' COMMENT 'Contoh: Sakura, Ukuran L, Warna Biru',
  cost_price        DECIMAL(14,2)   NOT NULL DEFAULT 0.00 COMMENT 'Harga modal per unit',
  sell_price        DECIMAL(14,2)   NOT NULL COMMENT 'Harga jual final, bukan formula markup',
  current_stock     INT             NOT NULL DEFAULT 0 COMMENT 'Nilai cepat baca. Sumber kebenaran ada di stock_movements.',
  low_stock_alert   INT             NULL COMMENT 'Ambang peringatan stok menipis',
  is_active         TINYINT(1)      NOT NULL DEFAULT 1,
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  deleted_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_variants_sku (sku),
  KEY idx_variants_product (product_id),
  KEY idx_variants_active (is_active),
  CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT chk_variants_price CHECK (sell_price >= 0 AND cost_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE customers (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(100)    NOT NULL,
  phone             VARCHAR(30)     NULL,
  email             VARCHAR(100)    NULL,
  social_handle     VARCHAR(100)    NULL COMMENT 'Akun media sosial, kanal kontak utama komunitas',
  notes             TEXT            NULL,
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  deleted_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  KEY idx_customers_phone (phone),
  KEY idx_customers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
-- CATATAN PERLINDUNGAN DATA — tabel ini memuat data pribadi. Kolom phone,
-- email, dan social_handle tidak boleh muncul pada laporan atau ekspor
-- yang diserahkan ke artist.


-- =====================================================================
-- BAGIAN 3 — EVENT & SESI KASIR
-- =====================================================================

CREATE TABLE events (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(150)    NOT NULL,
  location          VARCHAR(200)    NULL,
  start_date        DATE            NOT NULL,
  end_date          DATE            NOT NULL,
  status            ENUM('draft','active','closed','cancelled') NOT NULL DEFAULT 'draft',
  event_cost        DECIMAL(14,2)   NOT NULL DEFAULT 0.00 COMMENT 'Biaya booth, transport, dan lainnya',
  notes             TEXT            NULL,
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  deleted_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  KEY idx_events_status (status),
  KEY idx_events_dates (start_date, end_date),
  CONSTRAINT chk_events_dates CHECK (end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE cashier_sessions (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id          BIGINT UNSIGNED NOT NULL,
  user_id           BIGINT UNSIGNED NOT NULL,
  opened_at         TIMESTAMP       NOT NULL,
  closed_at         TIMESTAMP       NULL,
  opening_cash      DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  closing_cash      DECIMAL(14,2)   NULL COMMENT 'Kas fisik hasil hitung manual saat tutup',
  expected_cash     DECIMAL(14,2)   NULL COMMENT 'Kas seharusnya menurut sistem',
  cash_difference   DECIMAL(14,2)   NULL COMMENT 'closing_cash - expected_cash. Negatif berarti kurang.',
  status            ENUM('open','closed') NOT NULL DEFAULT 'open',
  notes             TEXT            NULL,
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_event (event_id),
  KEY idx_sessions_user_status (user_id, status),
  CONSTRAINT fk_sessions_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sessions_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
-- CATATAN — hanya boleh ada satu sesi berstatus 'open' per user pada satu
-- waktu. MySQL tidak dapat menegakkan ini lewat unique index parsial,
-- sehingga aturan ditegakkan di aplikasi sebelum membuat sesi baru.


-- =====================================================================
-- BAGIAN 4 — TRANSAKSI PENJUALAN
-- =====================================================================

CREATE TABLE orders (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_number      VARCHAR(30)     NOT NULL COMMENT 'Nomor transaksi untuk struk. Contoh: TRX-20261025-0001',
  event_id          BIGINT UNSIGNED NOT NULL,
  session_id        BIGINT UNSIGNED NOT NULL,
  customer_id       BIGINT UNSIGNED NULL COMMENT 'Kosong untuk pembeli walk-in tanpa pencatatan',
  user_id           BIGINT UNSIGNED NOT NULL COMMENT 'Kasir yang memproses',
  channel           ENUM('offline','online') NOT NULL DEFAULT 'offline',
  subtotal          DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  discount_amount   DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  total_amount      DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  total_cost        DECIMAL(14,2)   NOT NULL DEFAULT 0.00 COMMENT 'Total modal, snapshot untuk laporan laba',
  paid_amount       DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  change_amount     DECIMAL(14,2)   NOT NULL DEFAULT 0.00 COMMENT 'Kembalian untuk pembayaran tunai',
  status            ENUM('completed','voided') NOT NULL DEFAULT 'completed',
  void_reason       VARCHAR(255)    NULL,
  local_ref         CHAR(36)        NULL COMMENT 'Identitas unik dari perangkat, cadangan bila kelak multi-perangkat',
  notes             TEXT            NULL,
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_orders_number (order_number),
  UNIQUE KEY uk_orders_local_ref (local_ref),
  KEY idx_orders_event (event_id, status),
  KEY idx_orders_session (session_id),
  KEY idx_orders_customer (customer_id),
  KEY idx_orders_created (created_at),
  CONSTRAINT fk_orders_event    FOREIGN KEY (event_id)    REFERENCES events(id)           ON DELETE RESTRICT,
  CONSTRAINT fk_orders_session  FOREIGN KEY (session_id)  REFERENCES cashier_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id)        ON DELETE SET NULL,
  CONSTRAINT fk_orders_user     FOREIGN KEY (user_id)     REFERENCES users(id)            ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE order_items (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id          BIGINT UNSIGNED NOT NULL,
  variant_id        BIGINT UNSIGNED NOT NULL,
  artist_id         BIGINT UNSIGNED NOT NULL COMMENT 'Snapshot pemilik barang. Menjadi dasar rekap hasil artist.',
  sku_snapshot      CHAR(12)        NOT NULL,
  name_snapshot     VARCHAR(255)    NOT NULL COMMENT 'Nama produk dan varian saat transaksi',
  qty               INT             NOT NULL,
  cost_price        DECIMAL(14,2)   NOT NULL COMMENT 'Snapshot modal saat transaksi',
  sell_price        DECIMAL(14,2)   NOT NULL COMMENT 'Snapshot harga jual final saat transaksi',
  discount_amount   DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  line_total        DECIMAL(14,2)   NOT NULL COMMENT '(sell_price * qty) - discount_amount',
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  KEY idx_items_order (order_id),
  KEY idx_items_variant (variant_id),
  KEY idx_items_artist (artist_id),
  CONSTRAINT fk_items_order   FOREIGN KEY (order_id)   REFERENCES orders(id)           ON DELETE RESTRICT,
  CONSTRAINT fk_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
  CONSTRAINT fk_items_artist  FOREIGN KEY (artist_id)  REFERENCES artists(id)          ON DELETE RESTRICT,
  CONSTRAINT chk_items_qty CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- =====================================================================
-- BAGIAN 5 — PRE-ORDER & PENGIRIMAN
-- =====================================================================

CREATE TABLE preorders (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  preorder_number   VARCHAR(30)     NOT NULL COMMENT 'Contoh: PO-20261025-0001',
  event_id          BIGINT UNSIGNED NULL COMMENT 'Event tempat pre-order diterima',
  customer_id       BIGINT UNSIGNED NOT NULL COMMENT 'Wajib. Pre-order tidak bisa anonim.',
  user_id           BIGINT UNSIGNED NOT NULL,
  status            ENUM('ordered','dp_paid','arrived','settled','handed_over','cancelled')
                    NOT NULL DEFAULT 'ordered',
  fulfillment       ENUM('pickup','courier') NOT NULL DEFAULT 'pickup',
  subtotal          DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  shipping_cost     DECIMAL(14,2)   NOT NULL DEFAULT 0.00 COMMENT 'Bukan pendapatan penjualan produk',
  total_amount      DECIMAL(14,2)   NOT NULL DEFAULT 0.00 COMMENT 'subtotal + shipping_cost',
  paid_amount       DECIMAL(14,2)   NOT NULL DEFAULT 0.00 COMMENT 'Akumulasi DP dan pelunasan',
  expected_date     DATE            NULL,
  cancel_reason     VARCHAR(255)    NULL,
  notes             TEXT            NULL,
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_preorders_number (preorder_number),
  KEY idx_preorders_status (status),
  KEY idx_preorders_customer (customer_id),
  KEY idx_preorders_event (event_id),
  CONSTRAINT fk_preorders_event    FOREIGN KEY (event_id)    REFERENCES events(id)    ON DELETE SET NULL,
  CONSTRAINT fk_preorders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_preorders_user     FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
-- CATATAN — pre-order TIDAK mengurangi current_stock saat dipesan, karena
-- barangnya belum ada. Stok berkurang saat status berpindah ke
-- 'handed_over', melalui stock_movements bertipe 'preorder_handover'.

CREATE TABLE preorder_items (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  preorder_id       BIGINT UNSIGNED NOT NULL,
  variant_id        BIGINT UNSIGNED NOT NULL,
  artist_id         BIGINT UNSIGNED NOT NULL COMMENT 'Snapshot, sejalan dengan order_items',
  sku_snapshot      CHAR(12)        NOT NULL,
  name_snapshot     VARCHAR(255)    NOT NULL,
  qty               INT             NOT NULL,
  cost_price        DECIMAL(14,2)   NOT NULL,
  sell_price        DECIMAL(14,2)   NOT NULL,
  line_total        DECIMAL(14,2)   NOT NULL,
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  KEY idx_po_items_preorder (preorder_id),
  KEY idx_po_items_variant (variant_id),
  KEY idx_po_items_artist (artist_id),
  CONSTRAINT fk_po_items_preorder FOREIGN KEY (preorder_id) REFERENCES preorders(id)        ON DELETE RESTRICT,
  CONSTRAINT fk_po_items_variant  FOREIGN KEY (variant_id)  REFERENCES product_variants(id) ON DELETE RESTRICT,
  CONSTRAINT fk_po_items_artist   FOREIGN KEY (artist_id)   REFERENCES artists(id)          ON DELETE RESTRICT,
  CONSTRAINT chk_po_items_qty CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE shipments (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  preorder_id       BIGINT UNSIGNED NOT NULL,
  courier_name      VARCHAR(50)     NOT NULL COMMENT 'Diinput manual, tanpa integrasi API ekspedisi pada v1',
  tracking_number   VARCHAR(50)     NULL,
  shipping_cost     DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  recipient_name    VARCHAR(100)    NOT NULL,
  recipient_phone   VARCHAR(30)     NOT NULL,
  address_line      VARCHAR(255)    NOT NULL,
  city              VARCHAR(100)    NOT NULL,
  province          VARCHAR(100)    NULL,
  postal_code       VARCHAR(10)     NULL,
  status            ENUM('pending','packed','shipped','delivered') NOT NULL DEFAULT 'pending',
  shipped_at        TIMESTAMP       NULL,
  delivered_at      TIMESTAMP       NULL,
  notes             TEXT            NULL,
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_shipments_preorder (preorder_id) COMMENT 'Satu pre-order satu pengiriman pada v1',
  KEY idx_shipments_status (status),
  CONSTRAINT fk_shipments_preorder FOREIGN KEY (preorder_id) REFERENCES preorders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
-- CATATAN PERLINDUNGAN DATA — alamat pengiriman adalah data pribadi.
-- Tabel ini tidak boleh muncul pada ekspor untuk artist.


-- =====================================================================
-- BAGIAN 6 — PEMBAYARAN & BUKTI
-- =====================================================================

CREATE TABLE payments (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id          BIGINT UNSIGNED NULL,
  preorder_id       BIGINT UNSIGNED NULL,
  channel_id        BIGINT UNSIGNED NULL COMMENT 'Kosong untuk pembayaran tunai',
  method            ENUM('cash','bank_transfer','qr_ewallet') NOT NULL,
  purpose           ENUM('full','down_payment','settlement') NOT NULL DEFAULT 'full',
  amount            DECIMAL(14,2)   NOT NULL,
  verification      ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  verified_by       BIGINT UNSIGNED NULL,
  verified_at       TIMESTAMP       NULL,
  reject_reason     VARCHAR(255)    NULL,
  paid_at           TIMESTAMP       NOT NULL,
  notes             TEXT            NULL,
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  KEY idx_payments_order (order_id),
  KEY idx_payments_preorder (preorder_id),
  KEY idx_payments_method (method, verification),
  CONSTRAINT fk_payments_order    FOREIGN KEY (order_id)    REFERENCES orders(id)            ON DELETE RESTRICT,
  CONSTRAINT fk_payments_preorder FOREIGN KEY (preorder_id) REFERENCES preorders(id)         ON DELETE RESTRICT,
  CONSTRAINT fk_payments_channel  FOREIGN KEY (channel_id)  REFERENCES payment_channels(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_payments_verifier FOREIGN KEY (verified_by) REFERENCES users(id)             ON DELETE SET NULL,
  CONSTRAINT chk_payments_target CHECK (
    (order_id IS NOT NULL AND preorder_id IS NULL) OR
    (order_id IS NULL AND preorder_id IS NOT NULL)
  ),
  CONSTRAINT chk_payments_channel CHECK (
    (method = 'cash' AND channel_id IS NULL) OR
    (method <> 'cash' AND channel_id IS NOT NULL)
  ),
  CONSTRAINT chk_payments_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE payment_proofs (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id        BIGINT UNSIGNED NOT NULL,
  file_path         VARCHAR(255)    NOT NULL COMMENT 'Nama berkas acak, di luar direktori publik',
  original_name     VARCHAR(255)    NULL,
  mime_type         VARCHAR(50)     NOT NULL,
  file_size         INT UNSIGNED    NOT NULL COMMENT 'Byte, setelah kompresi',
  captured_via      ENUM('webcam','upload') NOT NULL,
  uploaded_by       BIGINT UNSIGNED NULL,
  created_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  KEY idx_proofs_payment (payment_id),
  CONSTRAINT fk_proofs_payment  FOREIGN KEY (payment_id)  REFERENCES payments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_proofs_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
-- ATURAN APLIKASI — pembayaran non-tunai wajib memiliki minimal satu baris
-- di tabel ini sebelum transaksi diselesaikan. Tidak dapat ditegakkan
-- database karena baris payment dibuat lebih dulu, sehingga validasi
-- dilakukan di sisi server dalam satu transaksi database.
-- CATATAN KEAMANAN (area risiko: input validation) — mime_type dan
-- file_size diverifikasi dari isi berkas, bukan dari header yang dikirim
-- klien. Berkas disimpan dengan nama acak dan tidak pernah dieksekusi.


-- =====================================================================
-- BAGIAN 7 — STOK
-- =====================================================================

CREATE TABLE stock_movements (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  variant_id        BIGINT UNSIGNED NOT NULL,
  type              ENUM('purchase','sale','preorder_handover','adjustment','return','initial')
                    NOT NULL,
  qty_change        INT             NOT NULL COMMENT 'Positif menambah, negatif mengurangi',
  stock_before      INT             NOT NULL,
  stock_after       INT             NOT NULL,
  reference_type    VARCHAR(50)     NULL COMMENT 'order_item, preorder_item, purchase_item',
  reference_id      BIGINT UNSIGNED NULL,
  reason            VARCHAR(255)    NULL COMMENT 'Wajib diisi untuk type = adjustment',
  user_id           BIGINT UNSIGNED NULL,
  created_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  KEY idx_movements_variant_time (variant_id, created_at),
  KEY idx_movements_type (type),
  KEY idx_movements_reference (reference_type, reference_id),
  CONSTRAINT fk_movements_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
  CONSTRAINT fk_movements_user    FOREIGN KEY (user_id)    REFERENCES users(id)            ON DELETE SET NULL,
  CONSTRAINT chk_movements_change CHECK (qty_change <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
-- ATURAN — tabel ini bersifat append-only. Tidak ada UPDATE atau DELETE.
-- Koreksi dilakukan dengan menambah baris baru bertipe 'adjustment'.


-- =====================================================================
-- BAGIAN 8 — REKAP HASIL ARTIST
-- =====================================================================

CREATE TABLE artist_settlements (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id          BIGINT UNSIGNED NOT NULL,
  artist_id         BIGINT UNSIGNED NOT NULL,
  total_sales       DECIMAL(14,2)   NOT NULL DEFAULT 0.00 COMMENT 'Hasil agregasi order_items, tanpa potongan komisi',
  total_units       INT             NOT NULL DEFAULT 0,
  deduction         DECIMAL(14,2)   NOT NULL DEFAULT 0.00 COMMENT 'Disediakan untuk kebutuhan mendatang. Selalu 0 pada model bisnis saat ini.',
  payable_amount    DECIMAL(14,2)   NOT NULL DEFAULT 0.00 COMMENT 'total_sales - deduction',
  paid_amount       DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  status            ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  calculated_at     TIMESTAMP       NULL COMMENT 'Kapan angka terakhir dihitung ulang',
  paid_at           TIMESTAMP       NULL,
  notes             TEXT            NULL,
  created_at        TIMESTAMP       NULL,
  updated_at        TIMESTAMP       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_settlements_event_artist (event_id, artist_id),
  KEY idx_settlements_status (status),
  CONSTRAINT fk_settlements_event  FOREIGN KEY (event_id)  REFERENCES events(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_settlements_artist FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
-- CATATAN — total_sales adalah hasil hitung ulang dari order_items, bukan
-- angka yang diketik manual. Tabel ini menyimpan hasil kalkulasi beserta
-- status pembayaran ke artist, sehingga ada jejak kapan dibayar.


SET FOREIGN_KEY_CHECKS = 1;


-- =====================================================================
-- VENDOR, BAHAN BAKU, DAN BOM (ditambahkan pasca-MVP, 2026-09-01)
-- =====================================================================
-- Dibangun atas permintaan eksplisit pemilik produk — BUKAN kebangkitan
-- "materials/vendors" yang ditunda di bawah (cakupannya sengaja lebih
-- sempit: tidak ada production_orders/purchases). Lihat CLAUDE.md/
-- README.md/PRD §10.2 untuk catatan lengkap. `code` bertipe VARCHAR
-- panjang bebas (bukan CHAR tetap seperti artists/categories) karena
-- kode vendor/bahan tidak dipakai membentuk code_prefix produk mana pun.

CREATE TABLE vendors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  contact_phone VARCHAR(30) NULL,
  contact_email VARCHAR(100) NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL,
  KEY idx_vendors_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE materials (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  unit VARCHAR(20) NOT NULL,        -- bebas: pcs, gram, meter, lembar, ...
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL,
  KEY idx_materials_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE vendor_material_prices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id BIGINT UNSIGNED NOT NULL,
  material_id BIGINT UNSIGNED NOT NULL,
  price DECIMAL(14,2) NOT NULL,
  is_preferred TINYINT(1) NOT NULL DEFAULT 0,  -- satu preferred per bahan, ditegakkan di app layer
  notes TEXT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uk_vendor_material (vendor_id, material_id),
  KEY idx_vmp_material (material_id),
  CONSTRAINT fk_vmp_vendor   FOREIGN KEY (vendor_id)   REFERENCES vendors(id)   ON DELETE RESTRICT,
  CONSTRAINT fk_vmp_material FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE product_variant_bom_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  -- Diikat ke VARIAN, bukan produk induk: ukuran/varian berbeda dari
  -- produk yang sama bisa butuh jumlah bahan berbeda (lihat CLAUDE.md).
  product_variant_id BIGINT UNSIGNED NOT NULL,
  material_id BIGINT UNSIGNED NOT NULL,
  qty_needed DECIMAL(12,4) NOT NULL,  -- per satu unit produk jadi; boleh pecahan
  notes TEXT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uk_bom_variant_material (product_variant_id, material_id),
  CONSTRAINT fk_bom_variant  FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
  CONSTRAINT fk_bom_material FOREIGN KEY (material_id)        REFERENCES materials(id)        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
-- CATATAN — bom_cost (total qty_needed * harga vendor acuan) TIDAK
-- disimpan sebagai kolom; selalu dihitung on-the-fly oleh
-- App\Services\BomCostCalculator dan TIDAK PERNAH menimpa
-- product_variants.cost_price (lihat dokblok kelas itu untuk alasannya).


-- =====================================================================
-- TABEL YANG SENGAJA DITUNDA
-- =====================================================================
-- Modul berikut tidak masuk MVP Oktober. Tabelnya belum dibuat karena
-- seluruhnya dapat ditambahkan tanpa mengubah tabel yang sudah ada:
--
--   material_purchases              -> pencatatan pembelian bahan aktual
--                                      (vendors/materials/harga SUDAH ada
--                                      di atas sejak 2026-09-01 — hanya
--                                      riwayat transaksi pembeliannya yang
--                                      masih ditunda)
--   production_orders, production_materials
--   flash_sales, flash_sale_items
--   purchases, purchase_items
--   import_jobs                     -> riwayat impor Excel
--
-- Satu-satunya penambahan kolom yang kelak dibutuhkan adalah
-- flash_sale_id nullable pada order_items, dan itu tidak merusak data
-- yang sudah ada.
--
-- CATATAN — nama pustaka pihak ketiga sengaja tidak disebutkan di
-- dokumen ini karena versinya belum diverifikasi.


-- =====================================================================
-- CONTOH KUERI KUNCI
-- =====================================================================
-- 1) Rekap hasil per artist untuk satu event
-- SELECT a.name, SUM(oi.qty) AS units, SUM(oi.line_total) AS total_sales
-- FROM order_items oi
-- JOIN orders o  ON o.id = oi.order_id AND o.status = 'completed'
-- JOIN artists a ON a.id = oi.artist_id
-- WHERE o.event_id = ?
-- GROUP BY a.id, a.name
-- ORDER BY total_sales DESC;
--
-- 2) Laba kotor per event, memakai harga snapshot
-- SELECT SUM(oi.line_total) AS revenue,
--        SUM(oi.cost_price * oi.qty) AS cost,
--        SUM(oi.line_total) - SUM(oi.cost_price * oi.qty) AS gross_profit
-- FROM order_items oi
-- JOIN orders o ON o.id = oi.order_id AND o.status = 'completed'
-- WHERE o.event_id = ?;
--
-- 3) Verifikasi konsistensi stok terhadap buku besar
-- SELECT v.sku, v.current_stock, COALESCE(SUM(sm.qty_change), 0) AS ledger_stock
-- FROM product_variants v
-- LEFT JOIN stock_movements sm ON sm.variant_id = v.id
-- GROUP BY v.id, v.sku, v.current_stock
-- HAVING v.current_stock <> ledger_stock;
