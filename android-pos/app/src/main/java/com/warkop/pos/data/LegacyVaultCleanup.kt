package com.warkop.pos.data

/**
 * 073 — rahasia yang hanya dipakai penyiapan lama (login owner/admin) dibuang dari brankas saat aplikasi dimulai. Hanya
 * kunci ini; outlet, terminal, alamat server, dan sesi PIN yang sah TIDAK disentuh, jadi tablet yang sudah terpasang
 * tetap bekerja tanpa dipasangkan ulang (FR-016). [run] menerima fungsi hapus supaya bisa diuji tanpa Android Keystore.
 */
object LegacyVaultCleanup {
    val KEYS: List<String> = listOf(TokenVault.ADMIN_TOKEN)

    fun run(clear: (String) -> Unit) = KEYS.forEach(clear)
}
