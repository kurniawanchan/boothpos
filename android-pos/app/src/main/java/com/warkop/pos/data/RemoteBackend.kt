package com.warkop.pos.data

import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody

import kotlinx.serialization.json.doubleOrNull
import kotlinx.serialization.json.jsonPrimitive

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
import com.warkop.pos.model.PrintableKot
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
 * Implementasi HTTP dari [PosBackend]. Memegang keadaan sesi ringan (URL, token PIN, outlet,
 * perangkat) yang dipulihkan dari [TokenVault] saat aplikasi mulai. Token TIDAK dicatat ke log.
 */
class RemoteBackend(private val vault: SecretStore, private val log: (String) -> Unit = {}) : PosBackend {
    private var serverUrl: String? = vault.get(TokenVault.SERVER_URL)
    private var sessionToken: String? = vault.get(TokenVault.SESSION_TOKEN)
    // 077 — pilihan outlet/terminal per mode data. Awal = LIVE (kunci tanpa akhiran, kompatibel dengan pemasangan lama);
    // PosStore memanggil useMode() begitu mode diketahui dari server.
    private var mode: com.warkop.pos.model.SystemMode = com.warkop.pos.model.SystemMode.parse(vault.get(TokenVault.SYSTEM_MODE))
    private fun key(base: String) = TokenVault.terminalKey(base, demo = mode == com.warkop.pos.model.SystemMode.DEMO)
    private var outletId: Long? = vault.get(key(TokenVault.OUTLET_ID))?.toLongOrNull()
    private var deviceId: Long? = vault.get(key(TokenVault.DEVICE_ID))?.toLongOrNull()
    // Nama terminal dibaca SEKALI dari brankas (saat konstruksi) lalu disimpan di memori: pembacaan Keystore
    // tidak boleh dilakukan di main thread setiap kali tulisan selesai (sumber ANR saat persetujuan).
    // 064 — "terminal ini" dikenali lewat deviceId (di atas), bukan nama; nama hanya untuk tampilan.
    private var myDeviceName: String = vault.get(key(TokenVault.DEVICE_NAME)).orEmpty()
    private var apiFor: Pair<String, PosApi>? = null

    init {
        // 073 — token owner/admin dari penyiapan lama dibuang saat aplikasi dimulai: tidak dipakai lagi, dan kredensial
        // berhak tinggi tidak boleh tertinggal di tablet kasir.
        LegacyVaultCleanup.run { vault.clear(it) }
    }

    /** Terminal yang sudah disiapkan (URL + outlet + perangkat), atau null → layar penyiapan. */
    override fun savedConfig(): TerminalConfig? {
        val url = serverUrl?.takeIf { it.isNotBlank() } ?: return null
        val outlet = outletId ?: return null
        val device = deviceId ?: return null
        return TerminalConfig(url, outlet, vault.get(key(TokenVault.OUTLET_NAME)).orEmpty(), device, myDeviceName)
    }

    fun hasValidSession(nowMillis: Long = System.currentTimeMillis()): Boolean =
        sessionToken != null && (vault.get(TokenVault.SESSION_EXPIRES)?.toLongOrNull() ?: 0L) > nowMillis

    override fun currentMode(): com.warkop.pos.model.SystemMode = mode

    override fun useMode(mode: com.warkop.pos.model.SystemMode) {
        vault.put(TokenVault.SYSTEM_MODE, mode.name)
        if (mode == this.mode) return
        // Sesi PIN dikeluarkan untuk outlet/terminal mode lama; di mode baru tokennya tidak lagi cocok dengan header yang dikirim.
        dropSession()
        this.mode = mode
        outletId = vault.get(key(TokenVault.OUTLET_ID))?.toLongOrNull()
        deviceId = vault.get(key(TokenVault.DEVICE_ID))?.toLongOrNull()
        myDeviceName = vault.get(key(TokenVault.DEVICE_NAME)).orEmpty()
    }

    // Hanya pilihan MODE INI yang dilupakan: pilihan mode lain tetap, jadi kembali ke mode itu tidak mengulang penyiapan.
    override fun forgetTerminal() {
        vault.clear(TokenVault.SESSION_TOKEN, TokenVault.SESSION_EXPIRES, key(TokenVault.OUTLET_ID), key(TokenVault.OUTLET_NAME),
            key(TokenVault.DEVICE_ID), key(TokenVault.DEVICE_NAME), TokenVault.DEVICE_CODE)
        sessionToken = null; outletId = null; deviceId = null; myDeviceName = ""
    }

    /**
     * 079 — nama toko di layar PIN mengikuti OUTLET tablet ini. Tanpa outlet tersimpan (penyiapan pertama) memakai
     * identitas bersama. Outlet tersimpan yang tidak ada di mode yang sedang berlaku di server (404 — outlet DEMO dan LIVE
     * berbeda) jatuh ke identitas bersama, sehingga mode tetap terbaca dan pergantian mode dikenali seperti sebelumnya.
     */
    override suspend fun branding(): com.warkop.pos.model.Branding {
        val outlet = outletId
        val dto = if (outlet == null) {
            apiCall { api().branding() }
        } else {
            try {
                apiCall { api().outletBranding(outlet) }
            } catch (_: ApiError.NotFound) {
                apiCall { api().branding() }
            }
        }
        return BrandingMapper.fromBranding(dto, serverUrl)
    }

    /** 078 — pengguna yang sedang masuk, dibaca ulang dari server supaya foto/nama yang diubah di web ikut tampil tanpa masuk ulang. */
    override suspend fun me(): StaffUser {
        val u = apiCall { api().me() }
        return StaffUser(u.id.toString(), u.name, u.role.orEmpty(), roleCode(u.role), photoUrl = ServerUrl.resolve(serverUrl, u.photoUrl))
    }

    override suspend fun sessionBranding(): com.warkop.pos.model.Branding = BrandingMapper.fromFeatures(apiCall { api().features() }, serverUrl)

    private fun api(): PosApi {
        val url = serverUrl ?: throw ApiError.Network("Terminal belum disiapkan.")
        apiFor?.takeIf { it.first == url }?.let { return it.second }
        val created = ApiClient.create(url, RequestContext({ sessionToken }, { outletId }, { deviceId }), log)
        apiFor = url to created
        return created
    }

    // --- penyiapan terminal (073: tanpa login, tanpa token) ---
    override suspend fun outlets(): List<OutletChoice> =
        apiCall { api().setupOutlets() }.data.map { OutletChoice(it.id, it.name) }

    override suspend fun terminals(outlet: OutletChoice): List<TerminalChoice> =
        apiCall { api().setupTerminals(outlet.id) }.data.map { TerminalChoice(it.id, it.name) }

    override suspend fun registerTerminal(outlet: OutletChoice, terminalName: String): TerminalConfig {
        val device = apiCall { api().registerTerminal(outlet.id, RegisterTerminalRequest(terminalName.trim())) }
        // Pilihan baru menggantikan yang lama; sesi PIN lama (outlet/terminal lain) tidak berlaku lagi.
        dropSession()
        outletId = outlet.id
        deviceId = device.id
        vault.put(key(TokenVault.OUTLET_ID), outlet.id.toString())
        vault.put(key(TokenVault.OUTLET_NAME), outlet.name)
        vault.put(key(TokenVault.DEVICE_ID), device.id.toString())
        vault.put(key(TokenVault.DEVICE_NAME), device.name)
        myDeviceName = device.name
        return TerminalConfig(serverUrl.orEmpty(), outlet.id, outlet.name, device.id, device.name)
    }

    override fun serverUrl(): String? = serverUrl

    override fun setServerUrl(url: String) {
        // Alamat kosong berarti "kosongkan" (null), bukan string "" yang menyesatkan loader.
        val trimmed = url.trim().takeIf { it.isNotBlank() }
        serverUrl = trimmed
        if (trimmed == null) {
            vault.clear(TokenVault.SERVER_URL)
        } else {
            vault.put(TokenVault.SERVER_URL, trimmed)
        }
    }

    // --- sesi PIN ---
    override suspend fun roster(): List<StaffUser> {
        val outlet = outletId ?: throw ApiError.Network("Terminal belum disiapkan.")
        return try {
            rosterFor(outlet)
        } catch (e: ApiError) {
            if (!SessionRecovery.dropsSession(e, hasSession = sessionToken != null)) throw e
            // 057 / US7 — buang sesi PIN yang sudah tidak berlaku, lalu satu percobaan ulang tanpa token (073: roster terbuka; tidak berulang).
            dropSession()
            rosterFor(outlet)
        }
    }

    private suspend fun rosterFor(outlet: Long): List<StaffUser> =
        apiCall { api().roster(outlet) }.data.map { StaffUser(it.id.toString(), it.name, it.role.orEmpty(), roleCode(it.role), hasPin = it.hasPin, locked = it.locked, photoUrl = ServerUrl.resolve(serverUrl, it.photoUrl)) }

    /** Membuang token sesi PIN dari memori dan penyimpanan. */
    fun dropSession() {
        sessionToken = null
        vault.clear(TokenVault.SESSION_TOKEN, TokenVault.SESSION_EXPIRES)
    }

    override suspend fun pinLogin(user: StaffUser, pin: String): PosSession {
        val outlet = outletId ?: throw ApiError.Network("Terminal belum disiapkan.")
        val device = deviceId ?: throw ApiError.Network("Terminal belum disiapkan.")
        // Login PIN tanpa token: sesi lama yang mungkin sudah habis tidak boleh ikut terkirim.
        sessionToken = null
        val res = apiCall { api().pinLogin(PinLoginRequest(outlet, user.id.toLong(), pin, device)) }
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
            // 055 — pos=1: menu outlet shift (item yang tidak dibawa outlet tidak tampil; harga outlet ikut).
            val res = apiCall { api().products(page, pos = 1) }
            all += res.data
            page++
        } while (res.meta.currentPage < res.meta.lastPage)
        // 055 / T011 — kategori dari server untuk mode aktif; tidak diturunkan dari produk.
        val categories = apiCall { api().posCategories() }.data
        return MenuMapper.toCatalog(all, categories, serverBase = serverUrl)
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

    override suspend fun openShift(count: com.warkop.pos.model.ShiftCount): Shift = shift(apiCall {
        // 065 — satu dari dua: total ketikan (`opening_cash`) ATAU pecahan; server menolak/menyamakan bila keduanya.
        api().openSession(when (count) {
            is com.warkop.pos.model.ShiftCount.Typed -> OpenSessionRequest(openingCash = count.total.toLong())
            is com.warkop.pos.model.ShiftCount.ByDenomination -> OpenSessionRequest(openingDenominations = count.rows.map { DenominationQtyDto(it.first, it.second) })
        })
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

    override suspend fun closeShift(sessionId: Long, count: com.warkop.pos.model.ShiftCount, key: String, overrideToken: String?): CloseOutcome =
        ShiftMapper.close(apiCall {
            // 065 — pecahan ATAU total ketikan (`counted_total`), tanpa EDC.
            api().closeSession(sessionId, key, when (count) {
                is com.warkop.pos.model.ShiftCount.Typed -> CloseSessionRequest(countedTotal = count.total.toLong(), varianceOverrideToken = overrideToken)
                is com.warkop.pos.model.ShiftCount.ByDenomination -> CloseSessionRequest(
                    countedByDenomination = count.rows.map { DenominationQtyDto(it.first, it.second) }, varianceOverrideToken = overrideToken,
                )
            })
        })

    override suspend fun shiftLimits(): ShiftLimits = ShiftMapper.limits(apiCall { api().features() })

    override suspend fun uploadQrImage(channelId: Long, bytes: ByteArray, mime: String) {
        val part = okhttp3.MultipartBody.Part.createFormData(
            "qr_image", if (mime == "image/png") "qr.png" else "qr.jpg",
            bytes.toRequestBody(mime.toMediaType()),
        )
        val res = api().uploadQrImage(channelId, part)
        if (!res.isSuccessful) throw ApiErrorMapper.fromHttp(res.code(), runCatching { res.errorBody()?.string() }.getOrNull())
    }

    override suspend fun taxDisplay(): TaxDisplay? {
        val f = apiCall { api().features() }
        // 083 — label kosong tidak lagi membuang seluruh pengaturan (dulu null → `show` ikut hilang); jatuh ke label bawaan server.
        val label = f.taxLabel?.takeIf { it.isNotBlank() } ?: "Pajak"
        // Tarif bisa berupa angka atau string desimal di JSON; keduanya dibaca sebagai Double.
        return TaxDisplay(label, f.taxRate?.jsonPrimitive?.doubleOrNull, show = f.taxShow != false)
    }

    override suspend fun approvers(action: String): List<Approver> =
        apiCall { api().approvers(action) }.map { Approver(it.id, it.name, it.role, ServerUrl.resolve(serverUrl, it.photoUrl)) }

    override suspend fun issueOverride(action: String, subject: JsonObject, approverId: Long, pin: String): String =
        apiCall { api().issueOverride(OverrideRequest(action, subject, approverId, pin)) }.overrideToken

    private fun shift(d: SessionDto): Shift {
        val opened = d.openedAt?.let { runCatching { java.time.OffsetDateTime.parse(it).toLocalTime().toString().take(5) }.getOrNull() } ?: "--:--"
        return Shift("Shift kasir", opened, "", Money.parse(d.openingCash).toInt(), "", d.id)
    }

    private fun session(user: AuthUserDto, expires: Long) = PosSession(
        user = StaffUser(user.id.toString(), user.name, user.role.orEmpty(), roleCode(user.role), photoUrl = ServerUrl.resolve(serverUrl, user.photoUrl)),
        expiresAtMillis = expires, permissions = user.permissions.toSet(), menuKeys = user.menuKeys.toSet(),
    )

    private fun parseInstant(iso: String): Long = runCatching { Instant.parse(iso).toEpochMilli() }
        .getOrElse { runCatching { java.time.OffsetDateTime.parse(iso).toInstant().toEpochMilli() }.getOrDefault(System.currentTimeMillis() + 12 * 3_600_000L) }

    // --- denah & pesanan ---

    override suspend fun floor(version: String?): FloorSnapshot? {
        val res = apiCall { api().floor(version) }
        if (res.unchanged) return null
        val mine = deviceId
        return FloorSnapshot(
            version = res.version,
            tables = res.areas.flatMap { area -> area.tables.map { OrderMapper.floorTable(it, area.area, mine) } },
            takeaway = res.takeawayTabs.map { OrderMapper.floorTab(it, mine, null) },
        )
    }

    override suspend fun openTab(sessionId: Long, tableId: Long?, guests: Int?, localRef: String): Order =
        OrderMapper.tab(
            apiCall { api().openTab(OpenTabRequestDto(sessionId = sessionId, tableId = tableId, guestCount = guests, localRef = localRef)) },
            deviceId,
        )

    override suspend fun tab(id: String): Order = OrderMapper.tab(apiCall { api().tab(id.toLong()) }, deviceId)

    override suspend fun addLine(tabId: String, variantId: String, qty: Int, note: String, modifierIds: List<Long>, key: String): Order =
        OrderMapper.tab(
            apiCall {
                api().addLine(tabId.toLong(), key, AddLineRequestDto(variantId = variantId.toLong(), qty = qty, note = note.ifBlank { null }, modifierOptionIds = modifierIds))
            },
            deviceId,
        )

    override suspend fun updateLine(tabId: String, lineId: Int, qty: Int, note: String, key: String): Order =
        OrderMapper.tab(
            apiCall { api().updateLine(tabId.toLong(), lineId.toLong(), key, UpdateLineRequestDto(qty = qty, note = note)) },
            deviceId,
        )

    override suspend fun setNote(tabId: String, notes: String): Order =
        OrderMapper.tab(apiCall { api().setNote(tabId.toLong(), UpdateNoteRequestDto(notes)) }, deviceId)

    override suspend fun removeLine(tabId: String, lineId: Int, key: String): Order =
        OrderMapper.tab(apiCall { api().removeLine(tabId.toLong(), lineId.toLong(), key) }, deviceId)

    override suspend fun sendToKitchen(tabId: String, key: String): KitchenSend {
        // 050 / T006 — tiket dari respons yang sama dipakai untuk KOT; sebelumnya dibuang di sini.
        val res = apiCall { api().send(tabId.toLong(), key) }
        return KitchenSend(OrderMapper.tab(res.tab, deviceId), res.tickets.map(TicketMapper::kot))
    }

    override suspend fun ticket(ticketId: Long): PrintableKot =
        TicketMapper.kot(apiCall { api().ticket(ticketId) })

    override suspend fun pay(tabId: String, method: String, amount: Int, clientRef: String, approvalCode: String?, confirmDuplicate: Boolean?, channelId: Long?): Order =
        OrderMapper.tab(
            apiCall {
                api().pay(tabId.toLong(), PayRequestDto(clientRef = clientRef, payments = listOf(
                    PaymentInputDto(method, Money.toServer(amount.toLong()), approvalCode = approvalCode, confirmDuplicate = confirmDuplicate, channelId = channelId),
                )))
            },
            deviceId,
        )

    override suspend fun voidLine(tabId: String, lineId: Int, reasonCode: String, kitchenStatus: String, stockDecision: String, reason: String?, overrideToken: String?): Order =
        OrderMapper.tab(apiCall {
            api().voidLine(tabId.toLong(), lineId.toLong(), VoidLineRequestDto(reasonCode = reasonCode, kitchenStatus = kitchenStatus, stockDecision = stockDecision, reason = reason, overrideToken = overrideToken))
        }, deviceId)

    override suspend fun cancelTab(tabId: String, reasonCode: String, reason: String?, overrideToken: String?): Order =
        OrderMapper.tab(apiCall { api().cancelTab(tabId.toLong(), CancelTabRequestDto(reasonCode = reasonCode, reason = reason, overrideToken = overrideToken)) }, deviceId)

    override suspend fun soldOut(productId: String, reasonCodeId: Long, note: String?, overrideToken: String?) {
        val res = apiCall { api().soldOut(productId.toLong(), SoldOutRequestDto(reasonCodeId = reasonCodeId, note = note?.takeIf { it.isNotBlank() }, overrideToken = overrideToken)) }
        if (!res.isSuccessful) throw ApiErrorMapper.fromHttp(res.code(), runCatching { res.errorBody()?.string() }.getOrNull())
    }

    override suspend fun reasonCodes(category: String): List<ReasonCode> =
        apiCall { api().reasonCodes(category) }.data.filter { it.isActive }.map { ReasonCode(it.id, it.label.ifBlank { it.code }, it.code) }

    override suspend fun qrisChannel(): QrisChannel? =
        // 065 — bila ada beberapa kanal QRIS, utamakan yang SUDAH punya gambar QR: kanal pertama tanpa gambar tidak boleh
        // menutupi kanal ber-QR di bawahnya ("QR belum diatur" padahal QR sudah diunggah di web).
        apiCall { api().paymentChannels() }.data.filter { it.type == "qr_ewallet" }
            .let { qr -> qr.firstOrNull { !it.qrImageUrl.isNullOrBlank() } ?: qr.firstOrNull() }
            ?.let { QrisMapper.toChannel(it, serverBase = serverUrl) }

    override suspend fun bankChannels(): List<BankChannel> =
        apiCall { api().paymentChannels() }.data
            .filter { it.type == "bank_transfer" }
            .map { BankChannel(it.id, it.provider?.takeIf { p -> p.isNotBlank() } ?: it.accountName.orEmpty().ifBlank { "Bank ${it.id}" }) }

    override suspend fun verifyPayment(tabId: String, paymentId: Long, reference: String, overrideToken: String?): Order =
        OrderMapper.tab(apiCall { api().verifyPayment(tabId.toLong(), paymentId, VerifyPaymentRequestDto(reference = reference, overrideToken = overrideToken)) }, deviceId)

    override suspend fun failPayment(tabId: String, paymentId: Long, overrideToken: String?): Order =
        OrderMapper.tab(apiCall { api().failPayment(tabId.toLong(), paymentId, FailPaymentRequestDto(overrideToken = overrideToken)) }, deviceId)

    override suspend fun prebill(tabId: String): Order = OrderMapper.tab(apiCall { api().prebill(tabId.toLong()) }, deviceId)

    override suspend fun receipt(orderId: String): com.warkop.pos.model.ReceiptView =
        ReceiptMapper.map(apiCall { api().receipt(orderId.toLong()) }, serverBase = serverUrl)

    override suspend fun heartbeat(clockMs: Long, queueDepth: Int): com.warkop.pos.state.HeartbeatResult {
        val result = HeartbeatMapper.result(apiCall { api().heartbeat(com.warkop.core.network.dto.HeartbeatRequestDto(com.warkop.pos.state.APP_VERSION, clockMs, queueDepth)) })
        // 064 — nama dari server menang: mengisi tablet yang dipasang sebelum fitur ini, dan mengikuti ganti-nama.
        result.terminalName?.takeIf { it != myDeviceName }?.let {
            myDeviceName = it
            vault.put(key(TokenVault.DEVICE_NAME), it)
        }
        return result
    }

    // 065 — adoptServerUrl dan serverEndpoint (054) dihapus: alamat server dibaca sekali saat penyiapan (ServerDiscovery).

    override suspend fun setDiscount(tabId: String, percent: Double, reasonCode: String, scope: String, lineId: Int?, overrideToken: String?, key: String): Order =
        OrderMapper.tab(apiCall { api().setDiscount(tabId.toLong(), key, DiscountRequestDto(percent, reasonCode, scope, lineId, overrideToken)) }, deviceId)

    override suspend fun clearDiscount(tabId: String, key: String): Order =
        OrderMapper.tab(apiCall { api().clearDiscount(tabId.toLong(), key) }, deviceId)

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
        return SplitItemsResult(OrderMapper.tab(res.paidOrder, deviceId), OrderMapper.tab(res.tab, deviceId))
    }

    override suspend fun reprintOrder(orderId: String, copyNo: Int, overrideToken: String?, key: String): Int =
        apiCall { api().reprint(orderId.toLong(), key, ReprintRequestDto(copyNo = copyNo, overrideToken = overrideToken)) }.copyNo

    override suspend fun refundOrder(orderId: String, reasonCode: String, reason: String?, overrideToken: String?, key: String): Order =
        OrderMapper.tab(apiCall { api().refund(orderId.toLong(), key, RefundRequestDto(reasonCode, reason, overrideToken)) }, deviceId)

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
