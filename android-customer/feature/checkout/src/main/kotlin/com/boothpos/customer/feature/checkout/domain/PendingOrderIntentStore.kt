package com.boothpos.customer.feature.checkout.domain

/**
 * Niat pesanan yang hasilnya BELUM PASTI, disimpan di luar layar checkout.
 *
 * Kenapa perlu: kunci idempotensi semula hanya hidup di SavedStateHandle layar checkout. Bila
 * pengiriman berakhir "status belum diketahui" (timeout, koneksi putus, 5xx) lalu pelanggan
 * meninggalkan checkout dan memesan isi yang SAMA lagi, kunci baru akan dibuat dan server bisa
 * mencatat pesanan kedua. Dengan penyimpanan ini, isi pesanan yang sama (sidik
 * [CheckoutFingerprint]) dari akun yang sama memakai kunci yang sama, sehingga server memutar
 * ulang pesanan pertama alih-alih membuat duplikat.
 *
 * Hanya SATU niat yang disimpan, dan hanya sidik (SHA-256) + kunci acak, tanpa nama, telepon,
 * atau alamat. Niat dilupakan begitu server menjawab pasti (pesanan dibuat atau ditolak).
 */
interface PendingOrderIntentStore {
    /** Kunci yang tersimpan untuk [fingerprint] milik [accountId], atau null. */
    suspend fun keyFor(accountId: Long, fingerprint: String): String?

    /** Dicatat SEBELUM permintaan dikirim, supaya proses yang mati di tengah jalan pun terlindungi. */
    suspend fun remember(accountId: Long, fingerprint: String, key: String)

    suspend fun forget()
}
