package com.warkop.pos.data

import com.warkop.core.domain.ApiError
import com.warkop.core.network.ApiClient
import com.warkop.core.network.ApiErrorMapper
import com.warkop.core.network.dto.AddLineRequestDto
import com.warkop.core.network.dto.CashMovementRequest
import com.warkop.core.network.dto.OverrideRequest
import kotlinx.serialization.json.JsonObject
import com.warkop.core.network.dto.CloseSessionRequest
import com.warkop.core.network.dto.DenominationQtyDto
import com.warkop.core.network.dto.OpenTabRequestDto
import com.warkop.core.network.dto.PayRequestDto
import com.warkop.core.network.dto.PaymentInputDto
import com.warkop.core.network.dto.UpdateLineRequestDto
import com.warkop.core.network.PosApi
import com.warkop.core.network.RequestContext
import com.warkop.core.network.apiCall
import com.warkop.core.network.dto.*
import com.warkop.core.domain.Money
import com.warkop.pos.model.Availability
import com.warkop.pos.model.FloorSnapshot
import com.warkop.pos.model.Order
import com.warkop.pos.model.MenuCatalog
import com.warkop.pos.model.Approver
import com.warkop.pos.model.ReasonCode
import com.warkop.pos.model.BankChannel
import com.warkop.pos.model.QrisChannel
import com.warkop.pos.model.SplitItemsResult
import com.warkop.pos.model.SplitPart
import com.warkop.pos.model.CashMovement
import com.warkop.pos.model.CloseOutcome
import com.warkop.pos.model.Shift
import com.warkop.pos.model.ShiftLimits
import com.warkop.pos.model.ShiftReport
import com.warkop.pos.model.StaffUser
import java.time.Instant

/**
 * Implementasi HTTP dari [PosBackend]. Memegang keadaan sesi ringan (URL, token penyiapan, token PIN, outlet,
 * perangkat) yang dipulihkan dari [TokenVault] saat aplikasi mulai. Token TIDAK dicatat ke log.
 */
class RemoteBackend(private val vault: TokenVault, private val log: (String) -> Unit = {}) : PosBackend {
    private var serverUrl: String? = vault.get(TokenVault.SERVER_URL)
    private var adminToken: String? = vault.get(TokenVault.ADMIN_TOKEN)
    private var sessionToken: String? = vault.get(TokenVault.SESSION_TOKEN)
    private var outletId: Long? = vault.get(TokenVault.OUTLET_ID)?.toLongOrNull()
    private var deviceId: Long? = vault.get(TokenVault.DEVICE_ID)?.toLongOrNull()
    // Kode perangkat dibaca SEKALI dari brankas (saat konstruksi) lalu disimpan di memori: pembacaan Keystore
    // tidak boleh dilakukan di main thread setiap kali tulisan selesai (sumber ANR saat persetujuan).
    private var myDeviceCode: String? = vault.get(TokenVault.DEVICE_CODE)
    private var apiFor: Pair<String, PosApi>? = null

    /** Terminal yang sudah disiapkan (URL + outlet + perangkat), atau null → layar penyiapan. */
    fun savedConfig(): TerminalConfig? {
        val url = serverUrl ?: return null
        val outlet = outletId ?: return null
        val device = deviceId ?: return null
        return TerminalConfig(url, outlet, vault.get(TokenVault.OUTLET_NAME).orEmpty(), device, vault.get(TokenVault.DEVICE_CODE).orEmpty())
    }

    fun hasValidSession(nowMillis: Long = System.currentTimeMillis()): Boolean =
        sessionToken != null && (vault.get(TokenVault.SESSION_EXPIRES)?.toLongOrNull() ?: 0L) > nowMillis

    fun resetTerminal() {
        vault.clear(TokenVault.SERVER_URL, TokenVault.ADMIN_TOKEN, TokenVault.SESSION_TOKEN, TokenVault.SESSION_EXPIRES,
            TokenVault.OUTLET_ID, TokenVault.OUTLET_NAME, TokenVault.DEVICE_ID, TokenVault.DEVICE_CODE)
        serverUrl = null; adminToken = null; sessionToken = null; outletId = null; deviceId = null; apiFor = null; myDeviceCode = null
    }

    private fun api(): PosApi {
        val url = serverUrl ?: throw ApiError.Network("Terminal belum disiapkan.")
        apiFor?.takeIf { it.first == url }?.let { return it.second }
        val created = ApiClient.create(url, RequestContext({ sessionToken ?: adminToken }, { outletId }, { deviceId }), log)
        apiFor = url to created
        return created
    }

    // --- penyiapan terminal ---
    override suspend fun adminLogin(serverUrl: String, username: String, password: String) {
        this.serverUrl = serverUrl.trim()
        this.sessionToken = null
        this.adminToken = null
        this.apiFor = null
        val res = apiCall { api().login(LoginRequest(username.trim(), password)) }
        adminToken = res.token
        vault.put(TokenVault.SERVER_URL, this.serverUrl)
        vault.put(TokenVault.ADMIN_TOKEN, res.token)
    }

    override suspend fun listOutlets(): List<OutletChoice> =
        apiCall { api().scope() }.outlets.map { OutletChoice(it.id, it.name) }

    override suspend fun pairTerminal(outlet: OutletChoice, terminalName: String): TerminalConfig {
        outletId = outlet.id // X-Outlet-Id: server menurunkan outlet dari sini (hanya menyempit)
        val device = apiCall { api().pairDevice(PairDeviceRequest(terminalName.trim())) }
        deviceId = device.id
        vault.put(TokenVault.OUTLET_ID, outlet.id.toString())
        vault.put(TokenVault.OUTLET_NAME, outlet.name)
        vault.put(TokenVault.DEVICE_ID, device.id.toString())
        vault.put(TokenVault.DEVICE_CODE, device.code)
        myDeviceCode = device.code
        return TerminalConfig(serverUrl.orEmpty(), outlet.id, outlet.name, device.id, device.code)
    }

    // --- sesi PIN ---
    override suspend fun roster(): List<StaffUser> {
        val outlet = outletId ?: throw ApiError.Network("Terminal belum disiapkan.")
        return apiCall { api().roster(outlet) }.data.map { StaffUser(it.id.toString(), it.name, it.role.orEmpty(), roleCode(it.role), hasPin = it.hasPin, locked = it.locked) }
    }

    override suspend fun pinLogin(user: StaffUser, pin: String): PosSession {
        val outlet = outletId ?: throw ApiError.Network("Terminal belum disiapkan.")
        // Login PIN memakai token penyiapan, bukan sesi lama yang mungkin sudah habis.
        sessionToken = null
        val res = apiCall { api().pinLogin(PinLoginRequest(outlet, user.id.toLong(), pin)) }
        val expires = parseInstant(res.expiresAt)
        sessionToken = res.token
        vault.put(TokenVault.SESSION_TOKEN, res.token)
        vault.put(TokenVault.SESSION_EXPIRES, expires.toString())
        return session(res.user, expires)
    }

    override suspend fun refreshSession(): Long {
        val res = apiCall { api().refreshToken() }
        sessionToken = res.token
        val expires = parseInstant(res.expiresAt)
        vault.put(TokenVault.SESSION_TOKEN, res.token)
        vault.put(TokenVault.SESSION_EXPIRES, expires.toString())
        return expires
    }

    override suspend fun logout() {
        runCatching { if (sessionToken != null) apiCall { api().logout() } }
        sessionToken = null
        vault.clear(TokenVault.SESSION_TOKEN, TokenVault.SESSION_EXPIRES)
    }

    // --- katalog ---
    override suspend fun loadMenu(): MenuCatalog {
        val all = ArrayList<ProductDto>()
        var page = 1
        do {
            val res = apiCall { api().products(page) }
            all += res.data
            page++
        } while (res.meta.currentPage < res.meta.lastPage)
        return MenuMapper.toCatalog(all)
    }

    override suspend fun availability(since: String?): Availability? {
        val res = apiCall { api().availability(since) }
        if (res.code() == 304) return null
        if (!res.isSuccessful) throw ApiErrorMapper.fromHttp(res.code(), runCatching { res.errorBody()?.string() }.getOrNull())
        val body = res.body() ?: return null
        return Availability(body.etag, body.soldOut.flatMap { it.variantIds }.map { it.toString() }.toSet())
    }

    // --- shift ---
    override suspend fun currentShift(): Shift? {
        val res = apiCall { api().currentSession() }
        if (res.code() == 404) return null
        if (!res.isSuccessful) throw ApiErrorMapper.fromHttp(res.code(), runCatching { res.errorBody()?.string() }.getOrNull())
        return res.body()?.let(::shift)
    }

    override suspend fun openShift(denominations: List<Pair<Int, Int>>): Shift = shift(apiCall {
        api().openSession(OpenSessionRequest(openingDenominations = denominations.filter { it.second > 0 }.map { DenominationQtyDto(it.first, it.second) }))
    })

    override suspend fun shiftReport(sessionId: Long): ShiftReport = ShiftMapper.report(apiCall { api().xReport(sessionId) })

    override suspend fun cashMovements(sessionId: Long): List<CashMovement> =
        apiCall { api().cashMovements(sessionId) }.data.map(ShiftMapper::movement)

    override suspend fun recordCashMovement(sessionId: Long, inflow: Boolean, reasonCode: String, note: String?, amount: Int, key: String, overrideToken: String?): CashMovement =
        ShiftMapper.movement(apiCall {
            api().recordCashMovement(sessionId, key, CashMovementRequest(
                direction = if (inflow) "in" else "out", reasonCode = reasonCode, reasonNote = note?.takeIf { it.isNotBlank() }, amount = amount.toLong(),
                overrideToken = overrideToken,
            ))
        })

    override suspend fun closeShift(sessionId: Long, counted: List<Pair<Int, Int>>, edcBatch: Int?, key: String, overrideToken: String?): CloseOutcome =
        ShiftMapper.close(apiCall {
            api().closeSession(sessionId, key, CloseSessionRequest(
                countedByDenomination = counted.filter { it.second > 0 }.map { DenominationQtyDto(it.first, it.second) },
                edcBatchTotal = edcBatch?.toLong(),
                varianceOverrideToken = overrideToken,
            ))
        })

    override suspend fun shiftLimits(): ShiftLimits = ShiftMapper.limits(apiCall { api().features() })

    override suspend fun approvers(action: String): List<Approver> =
        apiCall { api().approvers(action) }.map { Approver(it.id, it.name, it.role) }

    override suspend fun issueOverride(action: String, subject: JsonObject, approverId: Long, pin: String): String =
        apiCall { api().issueOverride(OverrideRequest(action, subject, approverId, pin)) }.overrideToken

    private fun shift(d: SessionDto): Shift {
        val opened = d.openedAt?.let { runCatching { java.time.OffsetDateTime.parse(it).toLocalTime().toString().take(5) }.getOrNull() } ?: "--:--"
        return Shift("Shift kasir", opened, "", Money.parse(d.openingCash).toInt(), "", d.id)
    }

    private fun session(user: AuthUserDto, expires: Long) = PosSession(
        user = StaffUser(user.id.toString(), user.name, user.role.orEmpty(), roleCode(user.role)),
        expiresAtMillis = expires, permissions = user.permissions.toSet(), menuKeys = user.menuKeys.toSet(),
    )

    private fun parseInstant(iso: String): Long = runCatching { Instant.parse(iso).toEpochMilli() }
        .getOrElse { runCatching { java.time.OffsetDateTime.parse(iso).toInstant().toEpochMilli() }.getOrDefault(System.currentTimeMillis() + 12 * 3_600_000L) }

    // --- denah & pesanan ---

    override suspend fun floor(version: String?): FloorSnapshot? {
        val res = apiCall { api().floor(version) }
        if (res.unchanged) return null
        val mine = myDeviceCode
        return FloorSnapshot(
            version = res.version,
            tables = res.areas.flatMap { area -> area.tables.map { OrderMapper.floorTable(it, area.area, mine) } },
            takeaway = res.takeawayTabs.map { OrderMapper.floorTab(it, mine, null) },
        )
    }

    override suspend fun openTab(sessionId: Long, tableId: Long?, guests: Int?, localRef: String): Order =
        OrderMapper.tab(
            apiCall { api().openTab(OpenTabRequestDto(sessionId = sessionId, tableId = tableId, guestCount = guests, localRef = localRef)) },
            myDeviceCode,
        )

    override suspend fun tab(id: String): Order = OrderMapper.tab(apiCall { api().tab(id.toLong()) }, myDeviceCode)

    override suspend fun addLine(tabId: String, variantId: String, qty: Int, note: String, modifierIds: List<Long>, key: String): Order =
        OrderMapper.tab(
            apiCall {
                api().addLine(tabId.toLong(), key, AddLineRequestDto(variantId = variantId.toLong(), qty = qty, note = note.ifBlank { null }, modifierOptionIds = modifierIds))
            },
            myDeviceCode,
        )

    override suspend fun updateLine(tabId: String, lineId: Int, qty: Int, note: String, key: String): Order =
        OrderMapper.tab(
            apiCall { api().updateLine(tabId.toLong(), lineId.toLong(), key, UpdateLineRequestDto(qty = qty, note = note)) },
            myDeviceCode,
        )

    override suspend fun removeLine(tabId: String, lineId: Int, key: String): Order =
        OrderMapper.tab(apiCall { api().removeLine(tabId.toLong(), lineId.toLong(), key) }, myDeviceCode)

    override suspend fun sendToKitchen(tabId: String, key: String): Order =
        OrderMapper.tab(apiCall { api().send(tabId.toLong(), key).tab }, myDeviceCode)

    override suspend fun pay(tabId: String, method: String, amount: Int, clientRef: String, approvalCode: String?, confirmDuplicate: Boolean?, channelId: Long?): Order =
        OrderMapper.tab(
            apiCall {
                api().pay(tabId.toLong(), PayRequestDto(clientRef = clientRef, payments = listOf(
                    PaymentInputDto(method, Money.toServer(amount.toLong()), approvalCode = approvalCode, confirmDuplicate = confirmDuplicate, channelId = channelId),
                )))
            },
            myDeviceCode,
        )

    override suspend fun voidLine(tabId: String, lineId: Int, reasonCode: String, kitchenStatus: String, stockDecision: String, reason: String?, overrideToken: String?): Order =
        OrderMapper.tab(apiCall {
            api().voidLine(tabId.toLong(), lineId.toLong(), VoidLineRequestDto(reasonCode = reasonCode, kitchenStatus = kitchenStatus, stockDecision = stockDecision, reason = reason, overrideToken = overrideToken))
        }, myDeviceCode)

    override suspend fun cancelTab(tabId: String, reasonCode: String, reason: String?, overrideToken: String?): Order =
        OrderMapper.tab(apiCall { api().cancelTab(tabId.toLong(), CancelTabRequestDto(reasonCode = reasonCode, reason = reason, overrideToken = overrideToken)) }, myDeviceCode)

    override suspend fun soldOut(productId: String, reasonCodeId: Long, note: String?, overrideToken: String?) {
        val res = apiCall { api().soldOut(productId.toLong(), SoldOutRequestDto(reasonCodeId = reasonCodeId, note = note?.takeIf { it.isNotBlank() }, overrideToken = overrideToken)) }
        if (!res.isSuccessful) throw ApiErrorMapper.fromHttp(res.code(), runCatching { res.errorBody()?.string() }.getOrNull())
    }

    override suspend fun reasonCodes(category: String): List<ReasonCode> =
        apiCall { api().reasonCodes(category) }.data.filter { it.isActive }.map { ReasonCode(it.id, it.label.ifBlank { it.code }, it.code) }

    override suspend fun qrisChannel(): QrisChannel? =
        apiCall { api().paymentChannels() }.data.firstOrNull { it.type == "qr_ewallet" }
            ?.let { QrisChannel(it.id, it.provider?.takeIf { p -> p.isNotBlank() } ?: "QRIS") }

    override suspend fun bankChannels(): List<BankChannel> =
        apiCall { api().paymentChannels() }.data
            .filter { it.type == "bank_transfer" }
            .map { BankChannel(it.id, it.provider?.takeIf { p -> p.isNotBlank() } ?: it.accountName.orEmpty().ifBlank { "Bank ${it.id}" }) }

    override suspend fun verifyPayment(tabId: String, paymentId: Long, reference: String, overrideToken: String?): Order =
        OrderMapper.tab(apiCall { api().verifyPayment(tabId.toLong(), paymentId, VerifyPaymentRequestDto(reference = reference, overrideToken = overrideToken)) }, myDeviceCode)

    override suspend fun failPayment(tabId: String, paymentId: Long, overrideToken: String?): Order =
        OrderMapper.tab(apiCall { api().failPayment(tabId.toLong(), paymentId, FailPaymentRequestDto(overrideToken = overrideToken)) }, myDeviceCode)

    override suspend fun prebill(tabId: String): Order = OrderMapper.tab(apiCall { api().prebill(tabId.toLong()) }, myDeviceCode)

    override suspend fun setDiscount(tabId: String, percent: Double, reasonCode: String, scope: String, lineId: Int?, overrideToken: String?, key: String): Order =
        OrderMapper.tab(apiCall { api().setDiscount(tabId.toLong(), key, DiscountRequestDto(percent, reasonCode, scope, lineId, overrideToken)) }, myDeviceCode)

    override suspend fun clearDiscount(tabId: String, key: String): Order =
        OrderMapper.tab(apiCall { api().clearDiscount(tabId.toLong(), key) }, myDeviceCode)

    override suspend fun splitEqual(tabId: String, parts: Int, key: String): List<SplitPart> =
        apiCall { api().splitEqual(tabId.toLong(), key, SplitEqualRequestDto(clientRef = key, mode = "equal", parts = parts)) }
            .parts.map { SplitPart(it.index, Money.parse(it.amount).toInt()) }

    override suspend fun splitItems(tabId: String, lines: List<Pair<Int, Int>>, cashAmount: String, key: String): SplitItemsResult {
        val res = apiCall {
            api().splitItems(tabId.toLong(), key, SplitItemsRequestDto(
                clientRef = key,
                items = lines.map { SplitItemDto(it.first, it.second) },
                payments = listOf(PaymentInputDto("cash", cashAmount)),
            ))
        }
        return SplitItemsResult(OrderMapper.tab(res.paidOrder, myDeviceCode), OrderMapper.tab(res.tab, myDeviceCode))
    }

    override suspend fun reprintOrder(orderId: String, overrideToken: String?, key: String): Int =
        apiCall { api().reprint(orderId.toLong(), key, ReprintRequestDto(overrideToken)) }.copyNo

    override suspend fun refundOrder(orderId: String, reasonCode: String, reason: String?, overrideToken: String?, key: String): Order =
        OrderMapper.tab(apiCall { api().refund(orderId.toLong(), key, RefundRequestDto(reasonCode, reason, overrideToken)) }, myDeviceCode)

    override suspend fun closedOrders(): List<Order> =
        apiCall { api().closedOrders(25) }.data.map { OrderMapper.closed(it) }

    companion object {
        /** Kode peran untuk logika tampilan sementara (supervisor, batas diskon contoh). Server tetap penegak. */
        fun roleCode(role: String?): String = when (role?.trim()?.lowercase()) {
            "kasir" -> "CSH"
            "waiter" -> "WTR"
            "supervisor" -> "SPV"
            "outlet manager", "owner", "admin" -> "OMG"
            else -> "CSH"
        }
    }
}
