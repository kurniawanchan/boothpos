package com.boothpos.customer.feature.auth.domain

import com.boothpos.customer.core.data.domain.validation.FieldIssue
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class CredentialRulesTest {

    @Test
    fun `e-mail wajib diisi dan dipangkas sebelum diperiksa`() {
        assertEquals(FieldIssue.Required, CredentialRules.email(""))
        assertEquals(FieldIssue.Required, CredentialRules.email("   "))
        assertNull(CredentialRules.email("  pelanggan.uji@example.test  "))
        assertEquals("pelanggan.uji@example.test", CredentialRules.normaliseEmail("  pelanggan.uji@example.test "))
    }

    @Test
    fun `bentuk e-mail yang jelas salah ditolak`() {
        listOf("pelanggan", "pelanggan@", "@example.test", "a b@example.test", "a@@example.test", "a@example", "a@example.")
            .forEach { assertEquals(it, FieldIssue.InvalidFormat, CredentialRules.email(it)) }
        listOf("a@example.test", "nama.depan+label@sub.example.test")
            .forEach { assertNull(it, CredentialRules.email(it)) }
    }

    @Test
    fun `e-mail dibatasi 100 karakter`() {
        val domain = "@example.test"
        assertNull(CredentialRules.email("a".repeat(100 - domain.length) + domain))
        assertEquals(FieldIssue.TooLong(100), CredentialRules.email("a".repeat(101 - domain.length) + domain))
    }

    @Test
    fun `kata sandi baru minimal 8 dan maksimal 72 karakter tanpa dipangkas`() {
        assertEquals(FieldIssue.Required, CredentialRules.newPassword(""))
        assertEquals(FieldIssue.TooShort(8), CredentialRules.newPassword("1234567"))
        assertNull(CredentialRules.newPassword("12345678"))
        // Spasi adalah karakter kata sandi yang sah dan ikut dihitung.
        assertNull(CredentialRules.newPassword("  1234  "))
        assertNull(CredentialRules.newPassword("x".repeat(72)))
        assertEquals(FieldIssue.TooLong(72), CredentialRules.newPassword("x".repeat(73)))
    }

    @Test
    fun `kata sandi saat masuk hanya wajib diisi`() {
        assertEquals(FieldIssue.Required, CredentialRules.currentPassword(""))
        assertNull(CredentialRules.currentPassword("abc"))
    }
}
