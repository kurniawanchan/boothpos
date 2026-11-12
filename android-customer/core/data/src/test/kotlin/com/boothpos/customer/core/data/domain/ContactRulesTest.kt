package com.boothpos.customer.core.data.domain

import com.boothpos.customer.core.data.domain.validation.ContactRules
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.data.domain.validation.characterCount
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class ContactRulesTest {

    @Test
    fun `nama wajib diisi dan spasi saja dianggap kosong`() {
        assertEquals(FieldIssue.Required, ContactRules.name(""))
        assertEquals(FieldIssue.Required, ContactRules.name("   "))
        assertNull(ContactRules.name(" Pelanggan Uji "))
    }

    @Test
    fun `nama dibatasi 100 karakter setelah dipangkas`() {
        assertNull(ContactRules.name("a".repeat(100)))
        assertNull(ContactRules.name("  " + "a".repeat(100) + "  "))
        assertEquals(FieldIssue.TooLong(100), ContactRules.name("a".repeat(101)))
    }

    @Test
    fun `telepon boleh kosong dan dibatasi 30 karakter`() {
        assertNull(ContactRules.phone(""))
        assertNull(ContactRules.phone("0800-0000-0000"))
        assertNull(ContactRules.phone("0".repeat(30)))
        assertEquals(FieldIssue.TooLong(30), ContactRules.phone("0".repeat(31)))
    }

    @Test
    fun `panjang dihitung per karakter seperti server bukan per unit UTF-16`() {
        // Satu emoji = dua unit UTF-16 tetapi satu karakter bagi mb_strlen.
        val emoji = String(Character.toChars(0x1F600))
        assertEquals(1, emoji.characterCount())
        assertNull(ContactRules.name(emoji.repeat(100)))
    }
}
