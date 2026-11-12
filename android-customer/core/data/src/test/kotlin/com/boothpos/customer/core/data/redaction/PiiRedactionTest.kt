package com.boothpos.customer.core.data.redaction

import com.boothpos.customer.core.data.domain.model.Account
import com.boothpos.customer.core.data.domain.model.Address
import com.boothpos.customer.core.data.domain.model.AddressInput
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.remote.AccountDto
import com.boothpos.customer.core.data.remote.AddressDto
import com.boothpos.customer.core.data.remote.toRequest
import com.boothpos.customer.core.data.security.AesGcmTokenStore
import com.boothpos.customer.core.data.security.StoredSession
import java.time.Instant
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * `toString()` bawaan data class mencetak SETIAP properti, dan objek-objek ini sampai ke pesan
 * pengecualian, log, dan laporan crash. Tes ini mengunci bahwa kelas yang memegang token atau data
 * pribadi hanya mencetak nama tipe dan id yang tidak sensitif. Nilai di bawah jelas-jelas palsu.
 */
class PiiRedactionTest {

    private val token = "tes-token-rahasia-palsu-123"
    private val name = "Pelanggan Rahasia Uji"
    private val email = "pelanggan.rahasia@example.test"
    private val phone = "0800-1234-5678"
    private val recipient = "Penerima Rahasia Uji"
    private val line = "Jl. Rahasia Uji No. 99"
    private val city = "Kota Rahasia"
    private val province = "Provinsi Rahasia"
    private val postal = "99999"
    private val note = "Pagar hijau rahasia"
    private val label = "Rumah Rahasia"

    private val secrets = listOf(token, name, email, phone, recipient, line, city, province, postal, note, label)

    private val account = Account(id = 7, name = name, email = email, phone = phone, emailVerified = true, createdAt = Instant.EPOCH)
    private val address = Address(
        id = 5, label = label, recipientName = recipient, phone = phone, addressLine = line,
        city = city, province = province, postalCode = postal, notes = note, isDefault = true,
    )
    private val input = AddressInput(
        label = label, recipientName = recipient, phone = phone, addressLine = line,
        city = city, province = province, postalCode = postal, notes = note, isDefault = true,
    )

    private fun assertRedacted(typeName: String, value: Any) {
        val printed = value.toString()
        assertTrue("$typeName harus mulai dengan nama tipenya, tetapi: $printed", printed.startsWith("$typeName("))
        secrets.forEach { secret -> assertFalse("$typeName membocorkan '$secret': $printed", printed.contains(secret)) }
    }

    @Test
    fun `sesi tersimpan dan akun tidak mencetak token atau data pribadi`() {
        assertRedacted("StoredSession", StoredSession(token, Instant.EPOCH, account))
        assertRedacted("Account", account)
        assertRedacted("PersistedSession", persisted())
    }

    @Test
    fun `alamat dan isian alamat tidak mencetak data pribadi`() {
        assertRedacted("Address", address)
        assertRedacted("AddressInput", input)
    }

    @Test
    fun `DTO kawat akun dan alamat tidak mencetak data pribadi`() {
        assertRedacted(
            "AccountDto",
            AccountDto(id = 7, name = name, email = email, phone = phone, emailVerified = true, createdAt = "2026-11-11T10:00:00+07:00"),
        )
        assertRedacted(
            "AddressDto",
            AddressDto(
                id = 5, label = label, recipientName = recipient, phone = phone, addressLine = line,
                city = city, province = province, postalCode = postal, notes = note, isDefault = true,
            ),
        )
        assertRedacted("AddressRequestDto", input.toRequest())
    }

    @Test
    fun `state sesi yang memuat akun tidak mencetak data pribadi`() {
        val printed = SessionState.SignedIn(account, expiresAt = null).toString()

        secrets.forEach { secret -> assertFalse("SessionState membocorkan '$secret': $printed", printed.contains(secret)) }
    }

    @Test
    fun `pencetakan yang disamarkan tidak mengubah kesetaraan dan salinan`() {
        // Hanya toString yang berubah; data class tetap bisa dibandingkan dan disalin (dipakai tes dan state).
        assertTrue(account == account.copy())
        assertTrue(address.copy(isDefault = false) != address)
    }

    private fun persisted() = AesGcmTokenStore.PersistedSession(
        token = token,
        expiresAtEpochSecond = 0,
        accountId = 7,
        name = name,
        email = email,
        phone = phone,
        emailVerified = true,
        createdAtEpochSecond = 0,
    )
}
