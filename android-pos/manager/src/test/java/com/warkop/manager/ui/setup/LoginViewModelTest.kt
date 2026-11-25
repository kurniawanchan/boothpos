package com.warkop.manager.ui.setup

import com.warkop.core.domain.ApiError
import com.warkop.core.network.dto.AuthUserDto
import com.warkop.manager.data.InMemoryStore
import com.warkop.manager.data.ManagerApi
import com.warkop.manager.data.SecureStore
import com.warkop.manager.data.SelectedOutlet
import com.warkop.manager.data.ServerKind
import com.warkop.manager.data.ServerProfiles
import com.warkop.manager.data.SessionRepository
import com.warkop.manager.data.dto.ManagerLoginRequest
import com.warkop.manager.data.dto.ManagerLoginResponse
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test

/**
 * 094 / T044 — perilaku login. `LoginViewModel` butuh `AppContainer` (Application), jadi tes menyasar dua tempat logikanya
 * berada: [SessionRepository.login] (token disimpan HANYA saat 200; 404 = server lama) dan [LoginViewModel.loginStatus]
 * (pesan per kode server, kontrak A1).
 */
class LoginViewModelTest {
    private val store = InMemoryStore()
    private lateinit var repo: SessionRepository
    private val profiles = ServerProfiles(store) { repo.endSession() }
    private var loginResult: (ManagerLoginRequest) -> ManagerLoginResponse = { error("belum diatur") }
    private var lastRequest: ManagerLoginRequest? = null

    private val api = object : ManagerApi by unusedApi() {
        override suspend fun login(body: ManagerLoginRequest): ManagerLoginResponse {
            lastRequest = body
            return loginResult(body)
        }
    }

    init {
        repo = SessionRepository(store, profiles) { api }
        profiles.save(ServerKind.LOCAL, "http://10.0.2.2:8010")
        repo.selectOutlet(SelectedOutlet(7, "WJM 3", demo = false))
    }

    private fun user() = AuthUserDto(id = 1, name = "Manajer", role = "Outlet Manager", menuKeys = listOf("dashboard", "products"), permissions = listOf("menu.recipe.edit"))

    @Test fun `200 menyimpan token dan menurunkan sesi dari user`() = runBlocking {
        loginResult = { ManagerLoginResponse("tok-123", user()) }
        val session = repo.login("  manajer ", "rahasia")
        assertEquals("tok-123", store.get(SecureStore.TOKEN))
        assertEquals(setOf("dashboard", "products"), session.menuKeys)
        assertEquals(setOf("menu.recipe.edit"), session.permissions)
        // Username dipangkas; outlet dari pilihan sebelum login; kata sandi dikirim apa adanya (tidak dipangkas).
        assertEquals(ManagerLoginRequest("manajer", "rahasia", 7), lastRequest)
    }

    @Test fun `401 dan 403 tidak menyimpan token`() = runBlocking {
        for (err in listOf(ApiError.Unauthorized("Username atau kata sandi salah."), ApiError.Forbidden("x", "manager_app_denied"), ApiError.Forbidden("x", "outlet_not_permitted"))) {
            loginResult = { throw err }
            try { repo.login("a", "b"); fail("harus melempar") } catch (e: ApiError) { assertEquals(err, e) }
            assertNull(store.get(SecureStore.TOKEN))
        }
    }

    @Test fun `404 berarti versi server belum mendukung aplikasi manajemen`() = runBlocking {
        loginResult = { throw ApiError.NotFound("Not Found") }
        try { repo.login("a", "b"); fail("harus melempar") } catch (e: ApiError) {
            assertEquals("server_outdated", e.code)
            assertTrue(e.message.contains("Versi server"))
        }
        assertNull(store.get(SecureStore.TOKEN))
    }

    @Test fun `pesan login per kode server`() {
        val denied = LoginViewModel.loginStatus(ApiError.Forbidden("Forbidden", "manager_app_denied"))
        assertTrue(denied.message!!.contains("tidak punya akses ke aplikasi manajemen"))

        val outlet = LoginViewModel.loginStatus(ApiError.Forbidden("Forbidden", "outlet_not_permitted"))
        assertTrue(outlet.message!!.contains("tidak terdaftar di outlet"))

        // 401 dan 429: pesan server dipakai apa adanya (429 sudah memuat lama tunggu, renderer 078).
        assertEquals("Username atau kata sandi salah.", LoginViewModel.loginStatus(ApiError.Unauthorized("Username atau kata sandi salah.")).message)
        val wait = "Terlalu banyak percobaan. Coba lagi dalam 45 detik."
        assertEquals(wait, LoginViewModel.loginStatus(ApiError.TooMany(wait, "throttled", null)).message)

        // 422 dipetakan ke kolom, bukan pesan umum.
        val v = LoginViewModel.loginStatus(ApiError.Validation("x", mapOf("username" to listOf("Wajib diisi."))))
        assertEquals("Wajib diisi.", v.error("username"))
        assertNull(v.message)
    }
}

/** Delegasi untuk API palsu: hanya metode yang ditimpa tes yang boleh terpanggil. */
private fun unusedApi(): ManagerApi = java.lang.reflect.Proxy.newProxyInstance(
    ManagerApi::class.java.classLoader, arrayOf(ManagerApi::class.java),
) { _, m, _ -> error("ManagerApi.${m.name} tidak dipakai di tes ini") } as ManagerApi
