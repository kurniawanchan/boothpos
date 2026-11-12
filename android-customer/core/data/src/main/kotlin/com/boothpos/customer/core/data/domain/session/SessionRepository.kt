package com.boothpos.customer.core.data.domain.session

import com.boothpos.customer.core.data.domain.model.Account
import java.time.Instant
import kotlinx.coroutines.flow.StateFlow

enum class SignOutReason {
    /** Pelanggan menekan "Keluar". */
    UserRequested,

    /** Token ditolak server (401) atau sudah lewat masa berlakunya. */
    SessionExpired,
}

sealed interface SessionState {
    /** Sesi tersimpan belum selesai dibaca (awal proses). */
    data object Loading : SessionState

    data class SignedOut(val reason: SignOutReason? = null) : SessionState

    data class SignedIn(val account: Account, val expiresAt: Instant?) : SessionState
}

/**
 * Satu-satunya pemilik sesi pelanggan. Token tidak pernah terlihat oleh modul fitur: fitur auth
 * menyerahkannya lewat [startSession], sesudah itu hanya interseptor jaringan yang membacanya.
 */
interface SessionRepository {
    val state: StateFlow<SessionState>

    /** Dipanggil fitur auth setelah register/login berhasil. */
    suspend fun startSession(token: String, expiresAt: Instant?, account: Account)

    /** Dipanggil setelah profil diubah (`PUT /customer/me`). Tidak berpengaruh bila tidak ada sesi. */
    suspend fun updateAccount(account: Account)

    /**
     * Menghapus token dan data milik pengguna di perangkat SEKARANG JUGA, lalu mencabut token di
     * server di latar belakang (upaya terbaik: keluar tetap berhasil saat offline).
     */
    suspend fun signOut()
}

/**
 * Pembersih data milik pengguna di perangkat. Daftarkan dengan `@Binds @IntoSet` di modul Hilt
 * pemilik datanya; [SessionRepository] memanggil semuanya saat sesi berakhir.
 */
fun interface UserScopedDataCleaner {
    suspend fun clear(reason: SignOutReason)
}
