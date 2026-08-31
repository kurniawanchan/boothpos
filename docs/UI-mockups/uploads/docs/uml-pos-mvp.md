**Klasifikasi: INTERNAL**

# Diagram UML — BoothPOS

*Sistem POS event-based multi-artist untuk toko merchandise*

| Field | Isi |
|---|---|
| Versi | v1.2 |
| Tanggal | 30 Agustus 2026 |
| Cakupan | MVP Oktober 2026, termasuk pre-order dan pengiriman kurir |
| Acuan | PRD v1.4 dan `schema-pos-mvp.sql` v1.0 |

Diagram ditulis dalam sintaks Mermaid agar ikut terkontrol versi bersama kode. Dapat dirender di GitHub, ekstensi Mermaid pada VS Code, atau mermaid.live.

---

## 1. Use case diagram

Menggambarkan siapa melakukan apa terhadap sistem pada cakupan MVP.

```mermaid
flowchart LR
  owner([Owner / Admin])
  cashier([Kasir])
  customer([Pelanggan])
  artist([Artist])

  subgraph SYS[Sistem POS]
    uc1(Kelola artist & kategori)
    uc2(Kelola produk & varian)
    uc3(Kelola stok)
    uc4(Kelola event)
    uc5(Buka & tutup sesi kasir)
    uc6(Proses transaksi penjualan)
    uc7(Catat pembayaran & bukti)
    uc8(Tampilkan struk)
    uc9(Catat pre-order)
    uc10(Kelola pengiriman)
    uc11(Lihat laporan penjualan)
    uc12(Rekap hasil artist)
    uc13(Kelola pengguna & pengaturan)
    uc14(Cadangkan data)
  end

  owner --> uc1
  owner --> uc2
  owner --> uc3
  owner --> uc4
  owner --> uc11
  owner --> uc12
  owner --> uc13
  owner --> uc14
  owner --> uc10

  cashier --> uc5
  cashier --> uc6
  cashier --> uc7
  cashier --> uc8
  cashier --> uc9

  customer -.membayar & difoto buktinya.-> uc7
  customer -.memotret struk di layar.-> uc8
  customer -.memesan.-> uc9
  artist -.menerima rekap di luar sistem.-> uc12

  uc6 -.include.-> uc7
  uc6 -.include.-> uc8
  uc6 -.include.-> uc3
  uc9 -.extend bila kirim kurir.-> uc10
```

Catatan: pelanggan dan artist bukan pengguna sistem pada MVP. Keduanya digambarkan sebagai aktor eksternal untuk memperjelas batas sistem — artist menerima rekap melalui berkas yang dikirim manual, bukan lewat login.

---

## 2. Class diagram

Struktur entitas MVP beserta relasinya. Hanya atribut kunci yang ditampilkan; daftar kolom lengkap ada di berkas skema.

```mermaid
classDiagram
  class Artist {
    +id
    +code : char3
    +name
    +isActive
  }
  class Category {
    +id
    +code : char2
    +name
    +parentId
  }
  class Product {
    +id
    +artistId
    +categoryId
    +codePrefix : char8
    +name
    +isPreorder
  }
  class ProductVariant {
    +id
    +productId
    +sku : char12
    +variantName
    +costPrice
    +sellPrice
    +currentStock
  }
  class Event {
    +id
    +name
    +startDate
    +endDate
    +status
    +eventCost
  }
  class CashierSession {
    +id
    +eventId
    +userId
    +openingCash
    +closingCash
    +status
  }
  class Order {
    +id
    +orderNumber
    +eventId
    +sessionId
    +totalAmount
    +totalCost
    +status
  }
  class OrderItem {
    +id
    +orderId
    +variantId
    +artistId
    +qty
    +costPrice
    +sellPrice
    +lineTotal
  }
  class Preorder {
    +id
    +preorderNumber
    +customerId
    +status
    +fulfillment
    +totalAmount
    +paidAmount
  }
  class PreorderItem {
    +id
    +preorderId
    +variantId
    +artistId
    +qty
  }
  class Shipment {
    +id
    +preorderId
    +courierName
    +trackingNumber
    +status
  }
  class Payment {
    +id
    +orderId
    +preorderId
    +method
    +purpose
    +amount
    +verification
  }
  class PaymentProof {
    +id
    +paymentId
    +filePath
    +capturedVia
  }
  class PaymentChannel {
    +id
    +type
    +provider
    +accountName
  }
  class StockMovement {
    +id
    +variantId
    +type
    +qtyChange
    +stockAfter
  }
  class Customer {
    +id
    +name
    +phone
  }
  class ArtistSettlement {
    +id
    +eventId
    +artistId
    +totalSales
    +paidAmount
    +status
  }
  class User {
    +id
    +username
    +role
  }

  Artist "1" --> "0..*" Product
  Category "1" --> "0..*" Product
  Category "0..1" --> "0..*" Category : parent
  Product "1" --> "1..*" ProductVariant
  ProductVariant "1" --> "0..*" StockMovement
  ProductVariant "1" --> "0..*" OrderItem
  ProductVariant "1" --> "0..*" PreorderItem
  Event "1" --> "0..*" CashierSession
  Event "1" --> "0..*" Order
  Event "1" --> "0..*" ArtistSettlement
  Artist "1" --> "0..*" ArtistSettlement
  Artist "1" --> "0..*" OrderItem : snapshot
  User "1" --> "0..*" CashierSession
  CashierSession "1" --> "0..*" Order
  Customer "0..1" --> "0..*" Order
  Customer "1" --> "0..*" Preorder
  Order "1" --> "1..*" OrderItem
  Order "1" --> "1..*" Payment
  Preorder "1" --> "1..*" PreorderItem
  Preorder "1" --> "0..*" Payment
  Preorder "1" --> "0..1" Shipment
  Payment "1" --> "0..*" PaymentProof
  PaymentChannel "1" --> "0..*" Payment
```

Dua hal yang sengaja terlihat dari diagram ini:

- `OrderItem` punya relasi langsung ke `Artist` meski sudah bisa ditelusuri lewat `ProductVariant → Product → Artist`. Ini snapshot yang disengaja agar rekap hasil artist kebal terhadap perubahan data master.
- `Payment` menempel ke `Order` atau ke `Preorder`, tidak pernah keduanya. Pre-order bisa punya beberapa pembayaran karena ada DP lalu pelunasan.

---

## 3. Sequence diagram — transaksi kasir

Alur transaksi dengan pembayaran non-tunai, termasuk aturan bukti bayar wajib.

```mermaid
sequenceDiagram
  actor K as Kasir
  participant UI as Vue (POS)
  participant API as Laravel API
  participant DB as MySQL
  participant FS as Penyimpanan lokal
  actor P as Pelanggan

  K->>UI: Cari produk & tambah ke keranjang
  UI->>API: GET /products?search=
  API->>DB: SELECT varian aktif
  DB-->>API: Daftar varian
  API-->>UI: Hasil pencarian
  UI-->>K: Tampilkan keranjang & total

  K->>UI: Pilih metode bayar (transfer / QR)
  UI->>API: GET /payment-channels
  API-->>UI: Rekening BCA, Mandiri, QR
  UI-->>P: Tampilkan rekening tujuan di layar
  P-->>K: Tunjukkan bukti transfer

  K->>UI: Ambil foto bukti via webcam
  UI->>UI: Kompresi gambar
  UI->>API: POST /payment-proofs (berkas)
  API->>API: Validasi tipe & ukuran berkas
  API->>FS: Simpan dengan nama acak
  FS-->>API: Path berkas
  API-->>UI: proof_token

  K->>UI: Selesaikan transaksi
  UI->>API: POST /orders (item, pembayaran, proof_token)

  rect rgba(128,128,128,0.12)
    note over API,DB: Satu transaksi database
    API->>API: Tolak bila non-tunai tanpa bukti
    API->>DB: INSERT orders
    API->>DB: INSERT order_items (snapshot harga & artist)
    API->>DB: INSERT payments
    API->>DB: INSERT payment_proofs
    API->>DB: INSERT stock_movements (type = sale)
    API->>DB: UPDATE product_variants.current_stock
    DB-->>API: Commit
  end

  API-->>UI: Order tersimpan + data struk
  UI-->>K: Tampilkan struk di layar
  P-->>P: Memotret struk
```

Bagian berkotak adalah satu transaksi database. Bila salah satu langkah gagal, seluruhnya dibatalkan — ini yang mencegah stok berkurang tanpa transaksi tercatat, atau sebaliknya.

---

## 4. Sequence diagram — pre-order sampai pengiriman

```mermaid
sequenceDiagram
  actor K as Kasir/Admin
  participant UI as Vue
  participant API as Laravel API
  participant DB as MySQL
  actor P as Pelanggan

  P->>K: Pesan item pre-order
  K->>UI: Input pelanggan, item, metode penyerahan
  UI->>API: POST /preorders
  API->>DB: INSERT preorders (status = ordered)
  API->>DB: INSERT preorder_items
  note over API,DB: Stok TIDAK berkurang di tahap ini
  DB-->>API: preorder_number
  API-->>UI: Konfirmasi
  UI-->>P: Tampilkan nomor pre-order

  P->>K: Bayar DP
  K->>UI: Catat DP + unggah bukti
  UI->>API: POST /preorders/{id}/payments (purpose = down_payment)
  API->>DB: INSERT payments + payment_proofs
  API->>DB: UPDATE preorders (paid_amount, status = dp_paid)

  note over K: Barang tiba dari produksi
  K->>UI: Tandai barang tiba
  UI->>API: PATCH /preorders/{id} (status = arrived)
  API->>DB: UPDATE status
  API->>DB: INSERT stock_movements (type = purchase)

  P->>K: Lunasi sisa pembayaran
  K->>UI: Catat pelunasan + bukti
  UI->>API: POST /preorders/{id}/payments (purpose = settlement)
  API->>DB: INSERT payments
  API->>DB: UPDATE preorders (status = settled)

  alt Penyerahan via kurir
    K->>UI: Input kurir, alamat, ongkir, resi
    UI->>API: POST /preorders/{id}/shipment
    API->>DB: INSERT shipments (status = pending)
    K->>UI: Perbarui status pengiriman
    UI->>API: PATCH /shipments/{id} (shipped)
  else Ambil di event
    K->>UI: Serahkan barang ke pelanggan
  end

  UI->>API: PATCH /preorders/{id} (status = handed_over)
  API->>DB: INSERT stock_movements (type = preorder_handover)
  API->>DB: UPDATE product_variants.current_stock
```

Titik paling rawan salah paham ada di sini: stok bertambah saat barang tiba, dan baru berkurang saat diserahkan. Kalau keduanya dilewat, stok sistem akan meleset dari stok fisik.

---

## 5. Activity diagram — alur kerja event

Dari persiapan sebelum event sampai rekap hasil artist setelahnya.

```mermaid
flowchart TD
  A([Mulai]) --> B[Buat event & input data produk]
  B --> C[Cadangkan basis data sebelum berangkat]
  C --> D[Buka sesi kasir & catat kas awal]
  D --> E{Jenis transaksi?}

  E -->|Penjualan langsung| F[Pilih produk & hitung total]
  E -->|Pre-order| G[Input pelanggan & item pre-order]

  F --> H{Metode bayar?}
  H -->|Tunai| I[Terima uang & hitung kembalian]
  H -->|Transfer / QR| J[Tampilkan rekening tujuan]
  J --> K[Foto bukti pembayaran]
  K --> L{Bukti terlampir?}
  L -->|Tidak| K
  L -->|Ya| M[Simpan transaksi]
  I --> M
  M --> N[Kurangi stok & tampilkan struk]
  N --> O{Masih ada pembeli?}

  G --> P{Metode penyerahan?}
  P -->|Ambil di event| Q[Catat DP]
  P -->|Kirim kurir| R[Catat alamat & ongkir]
  R --> Q
  Q --> O

  O -->|Ya| E
  O -->|Tidak| S[Tutup sesi & hitung kas akhir]
  S --> T{Kas cocok?}
  T -->|Tidak| U[Catat selisih & alasan]
  T -->|Ya| V[Tutup event]
  U --> V
  V --> W[Hitung rekap hasil per artist]
  W --> X[Ekspor rekap & kirim ke artist]
  X --> Y[Cadangkan basis data]
  Y --> Z([Selesai])
```

---

## 6. State machine diagram

### 6.1 Status pre-order

```mermaid
stateDiagram-v2
  [*] --> ordered : Pre-order dibuat
  ordered --> dp_paid : DP diterima
  ordered --> cancelled : Dibatalkan
  dp_paid --> arrived : Barang tiba
  dp_paid --> cancelled : Dibatalkan, DP dikembalikan
  arrived --> settled : Pelunasan diterima
  arrived --> cancelled : Dibatalkan
  settled --> handed_over : Diserahkan atau dikirim
  handed_over --> [*]
  cancelled --> [*]

  note right of settled
    Tidak boleh melompat ke
    handed_over sebelum lunas
  end note
```

### 6.2 Status pembayaran

```mermaid
stateDiagram-v2
  [*] --> pending : Pembayaran dicatat
  pending --> verified : Bukti cocok dengan mutasi
  pending --> rejected : Bukti tidak valid
  verified --> [*]
  rejected --> [*]

  note right of pending
    Pembayaran tunai langsung
    berstatus verified
  end note
```

### 6.3 Status sesi kasir

```mermaid
stateDiagram-v2
  [*] --> open : Kasir mencatat kas awal
  open --> closed : Kas akhir dihitung
  closed --> [*]

  note right of open
    Transaksi hanya dapat
    dibuat saat sesi open
  end note
```

### 6.4 Status event

```mermaid
stateDiagram-v2
  [*] --> draft
  draft --> active : Event dimulai
  draft --> cancelled
  active --> closed : Event selesai
  closed --> [*]
  cancelled --> [*]

  note right of closed
    Rekap hasil artist dihitung
    saat event ditutup
  end note
```

### 6.5 Status pengiriman

```mermaid
stateDiagram-v2
  [*] --> pending : Pengiriman dibuat
  pending --> packed : Barang dikemas
  packed --> shipped : Diserahkan ke kurir
  shipped --> delivered : Diterima pelanggan
  delivered --> [*]
```

---

## 7. Deployment diagram

Seluruh komponen berjalan di satu laptop operasional.

```mermaid
flowchart TB
  subgraph LAPTOP[Laptop operasional — localhost]
    subgraph WEB[Web server]
      LAR[Laravel API + berkas statis Vue]
    end
    DB[(MySQL)]
    FS[/Penyimpanan berkas:<br/>foto produk, bukti bayar, QR/]
    BR[Peramban — antarmuka kasir]
  end

  CAM[Webcam laptop]
  EXT[(Media eksternal:<br/>cadangan basis data & berkas)]

  BR -->|HTTP localhost| LAR
  LAR --> DB
  LAR --> FS
  CAM --> BR
  DB -.dump terjadwal.-> EXT
  FS -.salinan berkas.-> EXT

  subgraph FUTURE[Pasca-event — belum dibangun]
    SRV[Server publik]
    SHOP[Storefront online]
  end

  LAR -.migrasi kelak.-> SRV
  SRV -.-> SHOP
```

Tidak ada panah keluar ke internet pada blok utama. Inilah alasan kebutuhan offline terpenuhi tanpa lapisan sinkronisasi.

Panah putus-putus ke media eksternal adalah satu-satunya pengaman terhadap kerusakan perangkat. Bila panah itu tidak dijalankan rutin, seluruh data penjualan bergantung pada satu laptop.

---

## 8. Catatan implementasi

| Aturan | Alasan |
|---|---|
| Transaksi penjualan, pembayaran, bukti, dan pergerakan stok ditulis dalam satu transaksi database | Mencegah stok berkurang tanpa transaksi tercatat, atau sebaliknya |
| Validasi bukti bayar wajib ditegakkan di sisi server | Aturan di antarmuka saja dapat dilewati melalui permintaan API langsung |
| `stock_movements` bersifat append-only | Koreksi dilakukan dengan baris `adjustment` baru, bukan mengubah riwayat |
| Nomor transaksi dan pre-order dihasilkan server | Menghindari duplikasi bila antarmuka mengirim ulang permintaan |
| Status hanya berpindah sesuai state machine di bagian 6 | Lompatan status seperti `ordered` langsung ke `handed_over` harus ditolak API |

---

## 9. Gate lisensi Pro vs Master (v1.1)

Ditambahkan setelah keputusan produk: multi-artist tetap ada tapi bisa
dimatikan, dengan harga berbeda per tingkat. Bukan diagram baru — cukup
satu titik keputusan yang menyisip ke use case `uc1` (Kelola artist) di
bagian 1.

```mermaid
flowchart TD
  A([Admin klik "Tambah Artist"]) --> B{multi_artist_enabled?}
  B -->|Ya - Master| C[Buat artist baru, tanpa batas]
  B -->|Tidak - Pro| D{Sudah ada artist aktif?}
  D -->|Belum, instalasi baru| E[Buat sebagai artist bawaan]
  D -->|Sudah ada| F[Tolak 403: perlu upgrade ke Master]
```

Titik penegakan: `ArtistPolicy::create`, bukan di layer database. Skema
tidak berubah — `artists` tetap tabel biasa tanpa kolom atau constraint
tambahan untuk membedakan Pro/Master.

Konsekuensi pada diagram lain di dokumen ini: relasi `Artist → Product`
pada class diagram (bagian 2) dan snapshot `artist_id` di `OrderItem`
tetap sama persis untuk kedua tingkat harga — pada Pro, seluruh
`order_items` hanya menunjuk ke satu artist bawaan itu. Tidak ada
percabangan skema antara Pro dan Master, hanya percabangan di titik
pembuatan artist.
