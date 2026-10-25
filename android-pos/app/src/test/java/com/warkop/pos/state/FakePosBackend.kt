package com.warkop.pos.state

import com.warkop.core.domain.ApiError
import com.warkop.pos.data.KitchenSend
import com.warkop.pos.data.OutletChoice
import com.warkop.pos.data.PosBackend
import com.warkop.pos.data.PosSession
import com.warkop.pos.data.TerminalChoice
import com.warkop.pos.data.TaxDisplay
import com.warkop.pos.data.TerminalConfig
import com.warkop.pos.model.*
import kotlinx.serialization.json.JsonObject

/**
 * 054 — tiruan [PosBackend] untuk uji keadaan koneksi di layar PIN. Hanya roster dan alamat server yang bisa diatur;
 * semua metode lain sengaja melempar, supaya uji gagal keras bila PosStore memanggil sesuatu di luar cakupannya.
 */
class FakePosBackend : PosBackend {
    var rosterResult: () -> List<StaffUser> = { emptyList() }
    private var serverUrlValue: String? = null
    var tax: TaxDisplay? = null
    /** 055 / T022 — hasil loadMenu; null = panggilan tidak diharapkan. */
    var menuResult: (() -> MenuCatalog)? = null

    override suspend fun roster(): List<StaffUser> = rosterResult()
    override fun serverUrl(): String? = serverUrlValue
    override fun setServerUrl(url: String) { serverUrlValue = url.trim().takeIf { it.isNotBlank() } }
    override suspend fun taxDisplay(): TaxDisplay? = tax
    var qris: com.warkop.pos.model.QrisChannel? = null
    /** 056 amandemen — unggahan yang diterima fake: (channelId, mime, ukuran). */
    val uploads = mutableListOf<Triple<Long, String, Int>>()
    override suspend fun uploadQrImage(channelId: Long, bytes: ByteArray, mime: String) { uploads += Triple(channelId, mime, bytes.size) }

    private fun unused(): Nothing = throw UnsupportedOperationException("tidak dipakai dalam uji koneksi")

    /** 073 — penyiapan tanpa login: hasil dapat diatur uji; null = panggilan tidak diharapkan. */
    var outletsResult: (() -> List<OutletChoice>)? = null
    var terminalsResult: ((OutletChoice) -> List<TerminalChoice>)? = null
    var registerResult: ((OutletChoice, String) -> TerminalConfig)? = null
    var forgotten = 0
    override suspend fun outlets(): List<OutletChoice> = outletsResult?.invoke() ?: unused()
    override suspend fun terminals(outlet: OutletChoice): List<TerminalChoice> = terminalsResult?.invoke(outlet) ?: unused()
    override suspend fun registerTerminal(outlet: OutletChoice, terminalName: String): TerminalConfig = registerResult?.invoke(outlet, terminalName) ?: unused()
    override fun forgetTerminal() { forgotten++ }
    /** 073 — hasil login PIN uji; null = panggilan tidak diharapkan. */
    var pinLoginResult: ((StaffUser, String) -> PosSession)? = null
    override suspend fun pinLogin(user: StaffUser, pin: String): PosSession = pinLoginResult?.invoke(user, pin) ?: unused()
    override suspend fun refreshSession(): Long = unused()
    var logouts = 0
    override suspend fun logout() { logouts++ }
    override suspend fun loadMenu(): MenuCatalog = menuResult?.invoke() ?: unused()
    override suspend fun availability(since: String?): Availability? = unused()
    override suspend fun currentShift(): Shift? = unused()
    /** 065 — hitungan yang diterima fake saat buka/tutup shift (untuk memastikan hanya SATU total yang dikirim). */
    val openedWith = mutableListOf<ShiftCount>()
    val closedWith = mutableListOf<ShiftCount>()
    var closeResult: () -> CloseOutcome = { CloseOutcome(250_000, 250_000, 0, 5_000) }
    override suspend fun openShift(count: ShiftCount): Shift { openedWith += count; return Shift("Shift", "06:39", "", count.total, "1", id = 7) }
    override suspend fun shiftReport(sessionId: Long): ShiftReport = unused()
    override suspend fun cashMovements(sessionId: Long): List<CashMovement> = unused()
    override suspend fun recordCashMovement(sessionId: Long, inflow: Boolean, reasonCode: String, note: String?, amount: Int, key: String, overrideToken: String?): CashMovement = unused()
    override suspend fun closeShift(sessionId: Long, count: ShiftCount, key: String, overrideToken: String?): CloseOutcome { closedWith += count; return closeResult() }
    override suspend fun approvers(action: String): List<Approver> = unused()
    override suspend fun issueOverride(action: String, subject: JsonObject, approverId: Long, pin: String): String = unused()
    override suspend fun voidLine(tabId: String, lineId: Int, reasonCode: String, kitchenStatus: String, stockDecision: String, reason: String?, overrideToken: String?): Order = unused()
    override suspend fun cancelTab(tabId: String, reasonCode: String, reason: String?, overrideToken: String?): Order = unused()
    override suspend fun soldOut(productId: String, reasonCodeId: Long, note: String?, overrideToken: String?) = unused()
    /** 057 / US6 — daftar alasan uji; null = panggilan tidak diharapkan. */
    var reasons: (() -> List<ReasonCode>)? = null
    override suspend fun reasonCodes(category: String): List<ReasonCode> = reasons?.invoke() ?: unused()
    override suspend fun qrisChannel(): QrisChannel? = qris
    override suspend fun bankChannels(): List<BankChannel> = unused()
    override suspend fun verifyPayment(tabId: String, paymentId: Long, reference: String, overrideToken: String?): Order = unused()
    override suspend fun failPayment(tabId: String, paymentId: Long, overrideToken: String?): Order = unused()
    override suspend fun shiftLimits(): ShiftLimits = unused()
    override suspend fun floor(version: String?): FloorSnapshot? = unused()
    override suspend fun openTab(sessionId: Long, tableId: Long?, guests: Int?, localRef: String): Order = unused()
    override suspend fun tab(id: String): Order = unused()
    /** 075 — varian yang dikirim ke addLine, berurutan; hasilnya diatur lewat [addLineResult]. */
    val addLineCalls = mutableListOf<String>()
    var addLineResult: ((String) -> Order)? = null
    override suspend fun addLine(tabId: String, variantId: String, qty: Int, note: String, modifierIds: List<Long>, key: String): Order {
        addLineCalls += variantId
        return addLineResult?.invoke(variantId) ?: unused()
    }
    override suspend fun updateLine(tabId: String, lineId: Int, qty: Int, note: String, key: String): Order = unused()
    override suspend fun removeLine(tabId: String, lineId: Int, key: String): Order = unused()
    override suspend fun sendToKitchen(tabId: String, key: String): KitchenSend = unused()
    override suspend fun ticket(ticketId: Long): PrintableKot = unused()
    override suspend fun pay(tabId: String, method: String, amount: Int, clientRef: String, approvalCode: String?, confirmDuplicate: Boolean?, channelId: Long?): Order = unused()
    override suspend fun prebill(tabId: String): Order = unused()
    override suspend fun receipt(orderId: String): ReceiptView = unused()
    override suspend fun heartbeat(clockMs: Long, queueDepth: Int): HeartbeatResult = unused()
    override suspend fun setDiscount(tabId: String, percent: Double, reasonCode: String, scope: String, lineId: Int?, overrideToken: String?, key: String): Order = unused()
    override suspend fun clearDiscount(tabId: String, key: String): Order = unused()
    override suspend fun splitEqual(tabId: String, parts: Int, key: String): List<SplitPart> = unused()
    override suspend fun splitItems(tabId: String, lines: List<Pair<Int, Int>>, cashAmount: String, key: String): SplitItemsResult = unused()
    override suspend fun reprintOrder(orderId: String, copyNo: Int, overrideToken: String?, key: String): Int = unused()
    override suspend fun refundOrder(orderId: String, reasonCode: String, reason: String?, overrideToken: String?, key: String): Order = unused()
    override suspend fun closedOrders(): List<Order> = unused()
}
