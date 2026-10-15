package com.warkop.pos.data

import com.warkop.core.domain.ApiError
import com.warkop.core.network.ApiClient
import com.warkop.core.network.PosApi
import com.warkop.core.network.RequestContext
import com.warkop.core.network.apiCall
import com.warkop.core.network.dto.*
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
        serverUrl = null; adminToken = null; sessionToken = null; outletId = null; deviceId = null; apiFor = null
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

    private fun session(user: AuthUserDto, expires: Long) = PosSession(
        user = StaffUser(user.id.toString(), user.name, user.role.orEmpty(), roleCode(user.role)),
        expiresAtMillis = expires, permissions = user.permissions.toSet(), menuKeys = user.menuKeys.toSet(),
    )

    private fun parseInstant(iso: String): Long = runCatching { Instant.parse(iso).toEpochMilli() }
        .getOrElse { runCatching { java.time.OffsetDateTime.parse(iso).toInstant().toEpochMilli() }.getOrDefault(System.currentTimeMillis() + 12 * 3_600_000L) }

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
