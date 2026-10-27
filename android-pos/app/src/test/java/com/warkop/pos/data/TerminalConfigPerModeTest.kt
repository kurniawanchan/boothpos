package com.warkop.pos.data

import com.warkop.pos.model.SystemMode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Test

/**
 * 077 / US1 — outlet dan terminal berbeda per mode data di server, jadi tablet menyimpan satu pilihan PER MODE: ganti mode di web
 * memilih pilihan mode itu (atau penyiapan), dan kembali ke mode lama memulihkan pilihan pertama tanpa mengulang penyiapan.
 */
class TerminalConfigPerModeTest {
    private fun store(withServer: Boolean = true) = InMemoryStore().apply { if (withServer) put(TokenVault.SERVER_URL, "http://10.0.0.5:8010") }

    private fun save(vault: SecretStore, demo: Boolean, outlet: Long, name: String, device: Long, deviceName: String) {
        vault.put(TokenVault.terminalKey(TokenVault.OUTLET_ID, demo), outlet.toString())
        vault.put(TokenVault.terminalKey(TokenVault.OUTLET_NAME, demo), name)
        vault.put(TokenVault.terminalKey(TokenVault.DEVICE_ID, demo), device.toString())
        vault.put(TokenVault.terminalKey(TokenVault.DEVICE_NAME, demo), deviceName)
    }

    @Test fun `kunci LIVE tanpa akhiran dan DEMO berakhiran _demo`() {
        assertEquals("outlet_id", TokenVault.terminalKey(TokenVault.OUTLET_ID, demo = false))
        assertEquals("outlet_id_demo", TokenVault.terminalKey(TokenVault.OUTLET_ID, demo = true))
    }

    @Test fun `pemasangan lama tanpa akhiran terbaca sebagai pilihan LIVE`() {
        val vault = store(); save(vault, demo = false, outlet = 9, name = "WJM 3", device = 4, deviceName = "Kasir Depan")
        val backend = RemoteBackend(vault)
        assertEquals(TerminalConfig("http://10.0.0.5:8010", 9, "WJM 3", 4, "Kasir Depan"), backend.savedConfig())
    }

    @Test fun `mode DEMO tanpa pilihan mengembalikan null sehingga masuk penyiapan`() {
        val vault = store(); save(vault, demo = false, outlet = 9, name = "WJM 3", device = 4, deviceName = "Kasir Depan")
        val backend = RemoteBackend(vault)
        backend.useMode(SystemMode.DEMO)
        assertNull(backend.savedConfig())
    }

    @Test fun `pilihan kedua mode tersimpan terpisah dan kembali ke LIVE memulihkan yang pertama`() {
        val vault = store(); save(vault, demo = false, outlet = 9, name = "WJM 3", device = 4, deviceName = "Kasir Depan")
        save(vault, demo = true, outlet = 21, name = "Demo Outlet", device = 33, deviceName = "Tablet Demo")
        val backend = RemoteBackend(vault)

        backend.useMode(SystemMode.DEMO)
        assertEquals(21L, backend.savedConfig()?.outletId)
        assertEquals("Tablet Demo", backend.savedConfig()?.deviceName)

        backend.useMode(SystemMode.LIVE)
        assertEquals(9L, backend.savedConfig()?.outletId)
        assertEquals("Kasir Depan", backend.savedConfig()?.deviceName)
    }

    @Test fun `lupakan terminal hanya menghapus pilihan mode yang sedang berlaku`() {
        val vault = store(); save(vault, demo = false, outlet = 9, name = "WJM 3", device = 4, deviceName = "Kasir Depan")
        save(vault, demo = true, outlet = 21, name = "Demo Outlet", device = 33, deviceName = "Tablet Demo")
        val backend = RemoteBackend(vault)

        backend.useMode(SystemMode.DEMO)
        backend.forgetTerminal()
        assertNull(backend.savedConfig())

        backend.useMode(SystemMode.LIVE)
        assertNotNull(backend.savedConfig())
        assertEquals(9L, backend.savedConfig()?.outletId)
    }

    @Test fun `mode yang sama dipanggil ulang tidak membuang sesi`() {
        val vault = store(); save(vault, demo = false, outlet = 9, name = "WJM 3", device = 4, deviceName = "Kasir Depan")
        vault.put(TokenVault.SESSION_TOKEN, "tok")
        val backend = RemoteBackend(vault)
        backend.useMode(SystemMode.LIVE)
        assertEquals("tok", vault.get(TokenVault.SESSION_TOKEN))
    }

    @Test fun `pergantian mode membuang sesi PIN yang tidak lagi cocok`() {
        val vault = store(); save(vault, demo = false, outlet = 9, name = "WJM 3", device = 4, deviceName = "Kasir Depan")
        vault.put(TokenVault.SESSION_TOKEN, "tok")
        val backend = RemoteBackend(vault)
        backend.useMode(SystemMode.DEMO)
        assertNull(vault.get(TokenVault.SESSION_TOKEN))
    }
}
