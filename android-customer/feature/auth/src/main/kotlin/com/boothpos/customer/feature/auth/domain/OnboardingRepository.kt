package com.boothpos.customer.feature.auth.domain

/**
 * Apakah layar selamat datang (pilihan Masuk / Daftar / lihat toko tanpa akun) masih perlu
 * ditampilkan pada pembukaan pertama aplikasi. Bukan data sensitif.
 */
interface OnboardingRepository {
    suspend fun isWelcomePending(): Boolean

    suspend fun markWelcomeSeen()
}
