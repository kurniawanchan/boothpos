package com.warkop.pos.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

/** 073 / FR-016 — jalur pembaruan: token owner/admin lama dibuang; outlet, terminal, alamat server, dan sesi PIN tidak disentuh. */
class LegacyVaultCleanupTest {
    @Test fun `hanya token admin yang dibuang`() {
        val cleared = mutableListOf<String>()
        LegacyVaultCleanup.run { cleared += it }
        assertEquals(listOf(TokenVault.ADMIN_TOKEN), cleared)
    }

    @Test fun `kunci terminal dan sesi tidak ikut dibuang`() {
        val kept = listOf(TokenVault.SERVER_URL, TokenVault.OUTLET_ID, TokenVault.OUTLET_NAME, TokenVault.DEVICE_ID, TokenVault.DEVICE_NAME, TokenVault.SESSION_TOKEN, TokenVault.SESSION_EXPIRES)
        kept.forEach { assertFalse("$it tidak boleh dibuang", it in LegacyVaultCleanup.KEYS) }
    }
}
