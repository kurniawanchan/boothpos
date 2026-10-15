package com.warkop.pos.data

import com.warkop.pos.model.StaffUser

/** Hasil penyiapan terminal: outlet dan perangkat yang dipasangkan. */
data class TerminalConfig(
    val serverUrl: String,
    val outletId: Long,
    val outletName: String,
    val deviceId: Long,
    val deviceCode: String,
)

data class OutletChoice(val id: Long, val name: String)

/** Sesi PIN yang sedang berjalan. `permissions`/`menuKeys` hanya untuk menyembunyikan kontrol; server tetap penegak. */
data class PosSession(
    val user: StaffUser,
    val expiresAtMillis: Long,
    val permissions: Set<String>,
    val menuKeys: Set<String>,
)

/**
 * SATU batas antara UI dan server (research.md D3). Layar dan PosStore hanya mengenal antarmuka ini; implementasinya
 * RemoteBackend (HTTP). Semua fungsi suspend dan melempar ApiError (com.warkop.core.domain.ApiError) pada kegagalan.
 * Bagian ditambah per irisan; tidak ada implementasi data contoh di build rilis (FR-020).
 */
interface PosBackend {
    // --- penyiapan terminal (admin/owner, sekali) ---
    suspend fun adminLogin(serverUrl: String, username: String, password: String)
    suspend fun listOutlets(): List<OutletChoice>
    suspend fun pairTerminal(outlet: OutletChoice, terminalName: String): TerminalConfig

    // --- sesi PIN ---
    suspend fun roster(): List<StaffUser>
    suspend fun pinLogin(user: StaffUser, pin: String): PosSession
    suspend fun refreshSession(): Long
    suspend fun logout()
}
