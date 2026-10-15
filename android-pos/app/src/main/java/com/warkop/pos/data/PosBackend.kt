package com.warkop.pos.data

import com.warkop.pos.model.Availability
import com.warkop.pos.model.FloorSnapshot
import com.warkop.pos.model.MenuCatalog
import com.warkop.pos.model.Order
import com.warkop.pos.model.Shift
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

    // --- katalog ---
    suspend fun loadMenu(): MenuCatalog

    /** null = tidak berubah sejak [since] (304). */
    suspend fun availability(since: String?): Availability?

    // --- shift (dasar) ---
    suspend fun currentShift(): Shift?
    suspend fun openShift(totalRupiah: Long): Shift

    // --- denah & pesanan (S1/US2). Semua tulis membawa kunci niat (Idempotency-Key / client_ref / local_ref). ---
    /** null = denah tidak berubah sejak [version]. */
    suspend fun floor(version: String?): FloorSnapshot?
    suspend fun openTab(sessionId: Long, tableId: Long?, guests: Int?, localRef: String): Order
    suspend fun tab(id: String): Order
    suspend fun addLine(tabId: String, variantId: String, qty: Int, note: String, modifierIds: List<Long>, key: String): Order
    suspend fun updateLine(tabId: String, lineId: Int, qty: Int, note: String, key: String): Order
    suspend fun removeLine(tabId: String, lineId: Int, key: String): Order
    suspend fun sendToKitchen(tabId: String, key: String): Order
    suspend fun pay(tabId: String, method: String, amount: Int, clientRef: String): Order
    suspend fun prebill(tabId: String): Order
    suspend fun closedOrders(): List<Order>
}
