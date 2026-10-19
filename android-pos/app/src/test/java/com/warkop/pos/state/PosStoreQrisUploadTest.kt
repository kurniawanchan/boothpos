package com.warkop.pos.state

import com.warkop.pos.model.QrisChannel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** 056 amandemen — simpan gambar QR dari layar: dikirim ke kanal yang dimuat, tanpa kanal tidak ada yang dikirim. */
@OptIn(ExperimentalCoroutinesApi::class)
class PosStoreQrisUploadTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend

    @Before fun setUp() {
        Dispatchers.setMain(dispatcher)
        backend = FakePosBackend()
    }

    @After fun tearDown() { Dispatchers.resetMain() }

    @Test fun `gambar dikirim ke kanal QRIS yang sedang dimuat`() = runTest(dispatcher) {
        backend.qris = QrisChannel(id = 7, provider = "QRIS", accountName = "Warkop WJM 3", qrImageUrl = null, canManageQr = true)
        val store = PosStore(backend = backend)
        store.loadQrisLabel()
        advanceUntilIdle()

        store.saveQrImage(ByteArray(1234), "image/png")
        advanceUntilIdle()

        assertEquals(listOf(Triple(7L, "image/png", 1234)), backend.uploads)
    }

    @Test fun `tanpa kanal yang dimuat tidak ada unggahan`() = runTest(dispatcher) {
        val store = PosStore(backend = backend)
        store.saveQrImage(ByteArray(10), "image/jpeg")
        advanceUntilIdle()
        assertTrue(backend.uploads.isEmpty())
    }
}
