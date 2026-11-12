package com.boothpos.customer.core.data.session

import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.domain.session.SignOutReason
import com.boothpos.customer.core.data.domain.session.UserScopedDataCleaner
import com.boothpos.customer.core.data.security.StoredSession
import com.boothpos.customer.core.data.testing.InMemoryTokenStore
import com.boothpos.customer.core.data.testing.TestData
import com.boothpos.customer.core.network.UnauthorizedEvents
import java.time.Clock
import java.time.Instant
import java.time.ZoneOffset
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class SessionRepositoryImplTest {

    private val now = Instant.parse("2026-11-11T03:00:00Z")
    private val clock = Clock.fixed(now, ZoneOffset.UTC)
    private val account = TestData.account()
    private val tokenStore = InMemoryTokenStore()
    private val events = UnauthorizedEvents()
    private val cleanerReasons = mutableListOf<SignOutReason>()
    private val revokedTokens = mutableListOf<String>()

    private fun TestScope.repository(
        cleaners: Set<UserScopedDataCleaner> = setOf(UserScopedDataCleaner { cleanerReasons += it }),
        revoker: TokenRevoker = TokenRevoker { revokedTokens += it },
    ): SessionRepositoryImpl {
        val repository = SessionRepositoryImpl(tokenStore, cleaners, revoker, events, clock, backgroundScope)
        runCurrent()
        return repository
    }

    private fun signedIn() = StoredSession(FAKE_TOKEN, now.plusSeconds(3600), account)

    @Test
    fun `tanpa sesi tersimpan keadaannya keluar tanpa alasan`() = runTest {
        assertEquals(SessionState.SignedOut(), repository().state.value)
    }

    @Test
    fun `sesi tersimpan yang masih berlaku dipulihkan`() = runTest {
        tokenStore.stored = signedIn()

        assertEquals(SessionState.SignedIn(account, now.plusSeconds(3600)), repository().state.value)
    }

    @Test
    fun `sesi tersimpan yang sudah kedaluwarsa dihapus saat dipulihkan`() = runTest {
        tokenStore.stored = StoredSession(FAKE_TOKEN, now.minusSeconds(1), account)

        val repository = repository()

        assertEquals(SessionState.SignedOut(SignOutReason.SessionExpired), repository.state.value)
        assertNull(tokenStore.stored)
    }

    @Test
    fun `startSession menyimpan token dan akun`() = runTest {
        val repository = repository()

        repository.startSession(FAKE_TOKEN, now.plusSeconds(60), account)

        assertEquals(SessionState.SignedIn(account, now.plusSeconds(60)), repository.state.value)
        assertEquals(FAKE_TOKEN, tokenStore.peekToken())
    }

    @Test
    fun `signOut menghapus token dan data pengguna lalu mencabut token di server`() = runTest {
        tokenStore.stored = signedIn()
        val repository = repository()

        repository.signOut()
        runCurrent()

        assertEquals(SessionState.SignedOut(SignOutReason.UserRequested), repository.state.value)
        assertNull("Token harus hilang dari perangkat", tokenStore.peekToken())
        assertEquals(listOf(SignOutReason.UserRequested), cleanerReasons)
        assertEquals(listOf(FAKE_TOKEN), revokedTokens)
    }

    @Test
    fun `signOut tetap berhasil di perangkat walau pencabutan di server gagal`() = runTest {
        tokenStore.stored = signedIn()
        val repository = repository(revoker = { error("offline") })

        repository.signOut()
        runCurrent()

        assertEquals(SessionState.SignedOut(SignOutReason.UserRequested), repository.state.value)
        assertNull(tokenStore.peekToken())
    }

    @Test
    fun `pembersih yang gagal tidak membuat pelanggan tetap masuk`() = runTest {
        tokenStore.stored = signedIn()
        val repository = repository(
            cleaners = setOf(
                UserScopedDataCleaner { error("cache rusak") },
                UserScopedDataCleaner { cleanerReasons += it },
            ),
        )

        repository.signOut()

        assertEquals(SessionState.SignedOut(SignOutReason.UserRequested), repository.state.value)
        assertEquals(listOf(SignOutReason.UserRequested), cleanerReasons)
    }

    @Test
    fun `401 untuk token yang sedang dipakai mengakhiri sesi tanpa memanggil logout`() = runTest {
        tokenStore.stored = signedIn()
        val repository = repository()

        events.notifyRejected(FAKE_TOKEN)
        runCurrent()

        assertEquals(SessionState.SignedOut(SignOutReason.SessionExpired), repository.state.value)
        assertNull(tokenStore.peekToken())
        assertEquals(listOf(SignOutReason.SessionExpired), cleanerReasons)
        assertEquals(emptyList<String>(), revokedTokens)
    }

    @Test
    fun `401 milik token lama tidak mengakhiri sesi baru`() = runTest {
        tokenStore.stored = signedIn()
        val repository = repository()

        events.notifyRejected("token-lama-palsu")
        runCurrent()

        assertEquals(SessionState.SignedIn(account, now.plusSeconds(3600)), repository.state.value)
        assertEquals(FAKE_TOKEN, tokenStore.peekToken())
    }

    @Test
    fun `updateAccount memperbarui akun tanpa mengubah token`() = runTest {
        tokenStore.stored = signedIn()
        val repository = repository()
        val renamed = account.copy(name = "Nama Baru Uji")

        repository.updateAccount(renamed)

        assertEquals(SessionState.SignedIn(renamed, now.plusSeconds(3600)), repository.state.value)
        assertEquals(FAKE_TOKEN, tokenStore.peekToken())
        assertEquals(renamed, tokenStore.stored?.account)
    }

    @Test
    fun `updateAccount tanpa sesi tidak membuat sesi`() = runTest {
        val repository = repository()

        repository.updateAccount(account)

        assertEquals(SessionState.SignedOut(), repository.state.value)
        assertNull(tokenStore.stored)
    }

    private companion object {
        const val FAKE_TOKEN = "tes-token-palsu-123"
    }
}
