package com.warkop.manager.data

import com.warkop.core.domain.ApiError
import com.warkop.core.network.SecretStore
import com.warkop.core.network.apiCall
import com.warkop.core.network.dto.AuthUserDto
import com.warkop.core.network.dto.SetupOutletDto
import com.warkop.manager.data.dto.ManagerLoginRequest

/** Outlet yang dipilih SEBELUM login (FR-003). Disimpan per profil server; dikirim sebagai X-Outlet-Id setelah login. */
data class SelectedOutlet(val id: Long, val name: String, val demo: Boolean)

/** Sesi yang berlaku. Menu dan aksi yang tampil diturunkan dari `menuKeys`/`permissions` (sumber: server). */
data class ManagerSession(
    val userName: String,
    val roleName: String?,
    val menuKeys: Set<String>,
    val permissions: Set<String>,
)

/**
 * 094 / T036 — login, sesi, dan outlet terpilih. Token hanya di [SecretStore] (terenkripsi), kata sandi tidak pernah
 * disimpan. 401 atau 403 `manager_app_denied` dari server mana pun = sesi berakhir (FR-005a): [endSession] dipanggil.
 */
class SessionRepository(
    private val store: SecretStore,
    private val profiles: ServerProfiles,
    private val api: () -> ManagerApi,
) {
    fun token(): String? = store.get(SecureStore.TOKEN)

    fun selectedOutlet(): SelectedOutlet? {
        val kind = profiles.active
        val id = store.get(SecureStore.perProfile(SecureStore.OUTLET_ID, kind))?.toLongOrNull() ?: return null
        return SelectedOutlet(
            id = id,
            name = store.get(SecureStore.perProfile(SecureStore.OUTLET_NAME, kind)).orEmpty(),
            demo = store.get(SecureStore.perProfile(SecureStore.OUTLET_MODE, kind)) == "demo",
        )
    }

    fun selectOutlet(outlet: SelectedOutlet) {
        val kind = profiles.active
        // Ganti outlet = sesi lama tidak berlaku (pengguna mungkin tidak ditugaskan di outlet baru; FR-007).
        if (selectedOutlet()?.id != outlet.id) endSession()
        store.put(SecureStore.perProfile(SecureStore.OUTLET_ID, kind), outlet.id.toString())
        store.put(SecureStore.perProfile(SecureStore.OUTLET_NAME, kind), outlet.name)
        store.put(SecureStore.perProfile(SecureStore.OUTLET_MODE, kind), if (outlet.demo) "demo" else "live")
    }

    fun clearOutlet() {
        val kind = profiles.active
        store.clear(
            SecureStore.perProfile(SecureStore.OUTLET_ID, kind),
            SecureStore.perProfile(SecureStore.OUTLET_NAME, kind),
            SecureStore.perProfile(SecureStore.OUTLET_MODE, kind),
        )
        endSession()
    }

    suspend fun outlets(): List<SetupOutletDto> = apiCall { api().setupOutlets() }.data

    suspend fun outletIsDemo(outletId: Long): Boolean =
        runCatching { apiCall { api().outletBranding(outletId) }.systemMode == "demo" }.getOrDefault(false)

    suspend fun login(username: String, password: String): ManagerSession {
        val outlet = selectedOutlet() ?: throw ApiError.Validation("Pilih outlet dulu.", mapOf("outlet_id" to listOf("Pilih outlet dulu.")))
        val res = try {
            apiCall { api().login(ManagerLoginRequest(username.trim(), password, outlet.id)) }
        } catch (e: ApiError.NotFound) {
            // Server sebelum 094 tidak punya rute ini (edge case spesifikasi: versi server tidak kompatibel).
            throw ApiError.Conflict("Versi server belum mendukung aplikasi manajemen. Perbarui server Warkop WJM.", "server_outdated")
        }
        store.put(SecureStore.TOKEN, res.token)
        return session(res.user)
    }

    /** Muat ulang `GET /auth/me` (saat aplikasi kembali ke depan): izin bisa berubah di web selama sesi. */
    suspend fun refresh(): ManagerSession = try {
        session(apiCall { api().me() })
    } catch (e: ApiError) {
        if (isSessionEnd(e)) endSession()
        throw e
    }

    suspend fun logout() {
        runCatching { apiCall { api().logout() } }
        endSession()
    }

    fun endSession() = store.clear(SecureStore.TOKEN)

    companion object {
        fun session(user: AuthUserDto) = ManagerSession(
            userName = user.name,
            roleName = user.role,
            menuKeys = user.menuKeys.toSet(),
            permissions = user.permissions.toSet(),
        )

        /** Galat yang berarti sesi aplikasi sudah tidak berlaku dan pengguna harus login ulang. */
        fun isSessionEnd(e: ApiError): Boolean =
            e is ApiError.Unauthorized || (e is ApiError.Forbidden && e.code == "manager_app_denied")
    }
}
