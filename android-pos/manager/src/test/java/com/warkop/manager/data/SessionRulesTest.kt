package com.warkop.manager.data

import com.warkop.core.domain.ApiError
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class SessionRulesTest {
    private val store = InMemoryStore()
    private lateinit var repo: SessionRepository
    private val profiles = ServerProfiles(store) { repo.endSession() }

    init {
        repo = SessionRepository(store, profiles) { error("tidak dipanggil di tes ini") }
    }

    @Test fun `401 dan 403 manager_app_denied mengakhiri sesi, 403 lain tidak`() {
        assertTrue(SessionRepository.isSessionEnd(ApiError.Unauthorized("x")))
        assertTrue(SessionRepository.isSessionEnd(ApiError.Forbidden("x", "manager_app_denied")))
        assertFalse(SessionRepository.isSessionEnd(ApiError.Forbidden("x", "outlet_forbidden")))
    }

    @Test fun `mengganti profil aktif atau URL profil aktif menghapus token`() {
        profiles.save(ServerKind.LOCAL, "http://10.0.2.2:8010")
        store.put(SecureStore.TOKEN, "t")
        profiles.save(ServerKind.ONLINE, "https://pos.example.com") // profil TIDAK aktif: token tetap
        assertEquals("t", repo.token())
        profiles.activate(ServerKind.ONLINE)
        assertNull(repo.token())
        store.put(SecureStore.TOKEN, "t2")
        profiles.save(ServerKind.ONLINE, "https://pos2.example.com")
        assertNull(repo.token())
    }

    @Test fun `outlet terpilih disimpan per profil dan ganti outlet menghapus token`() {
        repo.selectOutlet(SelectedOutlet(9, "WJM 3", demo = false))
        store.put(SecureStore.TOKEN, "t")
        repo.selectOutlet(SelectedOutlet(9, "WJM 3", demo = false)) // sama: token tetap
        assertEquals("t", repo.token())
        repo.selectOutlet(SelectedOutlet(10, "WJM 2", demo = false))
        assertNull(repo.token())

        profiles.activate(ServerKind.ONLINE)
        assertNull(repo.selectedOutlet())
        profiles.activate(ServerKind.LOCAL)
        assertEquals(10L, repo.selectedOutlet()?.id)
    }

    @Test fun `profil online lewat http ditandai tidak aman`() {
        assertTrue(ServerProfile(ServerKind.ONLINE, "http://pos.example.com").insecureOnline)
        assertFalse(ServerProfile(ServerKind.ONLINE, "https://pos.example.com").insecureOnline)
        assertFalse(ServerProfile(ServerKind.LOCAL, "http://192.168.1.10:8010").insecureOnline)
    }
}
