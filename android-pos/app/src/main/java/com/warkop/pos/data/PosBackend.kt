package com.warkop.pos.data

import com.warkop.pos.model.Approver
import com.warkop.pos.model.ReasonCode
import com.warkop.pos.model.BankChannel
import com.warkop.pos.model.QrisChannel
import com.warkop.pos.model.SplitItemsResult
import com.warkop.pos.model.SplitPart
import com.warkop.pos.model.Availability
import com.warkop.pos.model.CashMovement
import com.warkop.pos.model.CloseOutcome
import com.warkop.pos.model.FloorSnapshot
import com.warkop.pos.model.MenuCatalog
import com.warkop.pos.model.Order
import com.warkop.pos.model.PrintableKot
import com.warkop.pos.model.Shift
import com.warkop.pos.model.ShiftLimits
import com.warkop.pos.model.ShiftReport
import com.warkop.pos.model.StaffUser
import kotlinx.serialization.json.JsonObject

/** Hasil penyiapan terminal: outlet dan perangkat yang dipasangkan. */
data class TerminalConfig(
    val serverUrl: String,
    val outletId: Long,
    val outletName: String,
    val deviceId: Long,
    val deviceCode: String,
)

data class OutletChoice(val id: Long, val name: String)

/** 054 — pilihan admin ("local" | "online") dan URL yang dipakai untuk pilihan itu; url null = tetap alamat pairing. */
data class ServerEndpoint(val selected: String, val url: String?)

/** 055 — label dan tarif pajak dari server untuk baris "Pajak" di layar kasir; ratePercent null = tarif tidak diketahui. */
data class TaxDisplay(val label: String, val ratePercent: Double?)

/** 050 / T006 — hasil kirim ke dapur: tab terbaru dan tiket yang baru dibuat. */
data class KitchenSend(val tab: Order, val tickets: List<PrintableKot>)

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

    // --- shift & kas (048 / S2: US3). Angka selalu dari server; kunci niat dibawa tiap tulis. ---
    suspend fun currentShift(): Shift?
    /** [denominations]: pasangan (pecahan, jumlah); server menurunkan kas awal dari situ. */
    suspend fun openShift(denominations: List<Pair<Int, Int>>): Shift
    suspend fun shiftReport(sessionId: Long): ShiftReport
    suspend fun cashMovements(sessionId: Long): List<CashMovement>
    /** [overrideToken]: token persetujuan (sekali pakai) bila kas keluar melampaui ambang; null bila tidak ada. */
    suspend fun recordCashMovement(sessionId: Long, inflow: Boolean, reasonCode: String, note: String?, amount: Int, key: String, overrideToken: String? = null): CashMovement
    /** Hitungan buta: pasangan (pecahan, jumlah). Server menjumlahkan dan menentukan selisih. */
    suspend fun closeShift(sessionId: Long, counted: List<Pair<Int, Int>>, edcBatch: Int?, key: String, overrideToken: String? = null): CloseOutcome

    // --- persetujuan (048 / S3: US4). Token tidak pernah disimpan; hanya dipakai satu aksi. ---
    suspend fun approvers(action: String): List<Approver>
    /** Mengirim `subject` (parameter aksi apa adanya); server yang menghitung hash-nya. */
    suspend fun issueOverride(action: String, subject: JsonObject, approverId: Long, pin: String): String

    // --- aksi tab & pembayaran (048 / S3: T056). Setiap tulis menerima token persetujuan (null bila tidak perlu). ---
    suspend fun voidLine(tabId: String, lineId: Int, reasonCode: String, kitchenStatus: String, stockDecision: String, reason: String?, overrideToken: String?): Order
    suspend fun cancelTab(tabId: String, reasonCode: String, reason: String?, overrideToken: String?): Order
    suspend fun soldOut(productId: String, reasonCodeId: Long, note: String?, overrideToken: String?)
    suspend fun reasonCodes(category: String): List<ReasonCode>
    /** Kanal QRIS (qr_ewallet) dari server; null bila belum diatur. Nama = provider dari server. */
    suspend fun qrisChannel(): QrisChannel?
    /** Kanal bank (bank_transfer) untuk EDC. Kosong = server belum punya bank; klien tidak boleh mengirim pembayaran EDC tanpa id. */
    suspend fun bankChannels(): List<BankChannel>
    suspend fun verifyPayment(tabId: String, paymentId: Long, reference: String, overrideToken: String?): Order
    suspend fun failPayment(tabId: String, paymentId: Long, overrideToken: String?): Order
    suspend fun shiftLimits(): ShiftLimits

    // --- denah & pesanan (S1/US2). Semua tulis membawa kunci niat (Idempotency-Key / client_ref / local_ref). ---
    /** null = denah tidak berubah sejak [version]. */
    suspend fun floor(version: String?): FloorSnapshot?
    suspend fun openTab(sessionId: Long, tableId: Long?, guests: Int?, localRef: String): Order
    suspend fun tab(id: String): Order
    suspend fun addLine(tabId: String, variantId: String, qty: Int, note: String, modifierIds: List<Long>, key: String): Order
    suspend fun updateLine(tabId: String, lineId: Int, qty: Int, note: String, key: String): Order
    suspend fun removeLine(tabId: String, lineId: Int, key: String): Order
    /** 050 / T006 — tab setelah kirim + tiket dapur yang baru dibuat (satu per stasiun) untuk dicetak sebagai KOT. */
    suspend fun sendToKitchen(tabId: String, key: String): KitchenSend
    /** 050 / T008 — satu tiket dapur (untuk cetak ulang KOT). */
    suspend fun ticket(ticketId: Long): PrintableKot
    /** EDC: [approvalCode] + [confirmDuplicate] (setelah kasir mengonfirmasi duplikat). QRIS: [channelId] kanal QRIS. */
    suspend fun pay(tabId: String, method: String, amount: Int, clientRef: String, approvalCode: String? = null, confirmDuplicate: Boolean? = null, channelId: Long? = null): Order
    suspend fun prebill(tabId: String): Order

    /** Struk dari server (T038). */
    suspend fun receipt(orderId: String): com.warkop.pos.model.ReceiptView

    /** Heartbeat perangkat (US6): jam perangkat, jumlah antrean lokal (0 bila tidak ada), dan versi aplikasi. */
    suspend fun heartbeat(clockMs: Long, queueDepth: Int): com.warkop.pos.state.HeartbeatResult

    /** 054 / US3 — alamat server yang dipilih admin; null = galat (tablet tetap memakai alamat pairing). */
    suspend fun serverEndpoint(): ServerEndpoint?

    /** 055 / T036 — pajak untuk tampilan counter; null bila server belum memberi label. */
    suspend fun taxDisplay(): TaxDisplay?

    /** 056 amandemen — simpan gambar QR kanal ke server; galat server dilempar sebagai ApiError. */
    suspend fun uploadQrImage(channelId: Long, bytes: ByteArray, mime: String)

    // --- diskon, bagi rata, cetak ulang, refund (048 / S4 — US5). [key] = Idempotency-Key per niat pengguna. ---
    /** Diskon order ([scope] "order") atau satu baris ([scope] "line", [lineId]). Di atas batas → 409 override_required. */
    suspend fun setDiscount(tabId: String, percent: Double, reasonCode: String, scope: String, lineId: Int?, overrideToken: String?, key: String): Order
    suspend fun clearDiscount(tabId: String, key: String): Order
    /** Bagi rata tanpa pembayaran; jumlah tiap bagian dari server (bukan dihitung di klien). */
    suspend fun splitEqual(tabId: String, parts: Int, key: String): List<SplitPart>
    /** Bagi per item ke POST /tabs/{id}/split: [lines] = (id baris, qty) yang dipindah; [cashAmount] = tunai bagian itu. */
    suspend fun splitItems(tabId: String, lines: List<Pair<Int, Int>>, cashAmount: String, key: String): SplitItemsResult
    /** Cetak ulang: salinan pertama gratis, berikutnya wajib [overrideToken]. Mengembalikan copy_no. */
    suspend fun reprintOrder(orderId: String, copyNo: Int, overrideToken: String?, key: String): Int
    suspend fun refundOrder(orderId: String, reasonCode: String, reason: String?, overrideToken: String?, key: String): Order
    suspend fun closedOrders(): List<Order>
}
