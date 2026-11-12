package com.boothpos.customer.feature.checkout.domain

import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class CheckoutDomainTest {

    private val request = CheckoutRequest(
        method = FulfilmentMethod.Courier,
        addressId = 5,
        contactName = "Pelanggan Uji",
        contactPhone = "0800-0000-0000",
        items = listOf(CheckoutItem(31, 2), CheckoutItem(32, 1)),
        notes = null,
    )

    // --- jawaban pasti vs penolakan middleware ---

    @Test
    fun `hanya jawaban handler 409 dan 422 yang dikenal menyelesaikan niat tertunda`() {
        listOf(
            409 to ApiErrorCodes.PRICE_CHANGED,
            409 to ApiErrorCodes.CART_INVALID,
            409 to ApiErrorCodes.IDEMPOTENCY_KEY_REUSED,
            422 to ApiErrorCodes.VALIDATION_FAILED,
            422 to ApiErrorCodes.IDEMPOTENCY_KEY_REQUIRED,
        ).forEach { (status, code) ->
            assertTrue("$status $code", CheckoutRules.settlesPendingIntent(ApiError.Http(status, code)))
        }
    }

    @Test
    fun `penolakan middleware dan kode tak dikenal tidak menyelesaikan niat tertunda`() {
        listOf(
            401 to ApiErrorCodes.UNAUTHENTICATED,
            403 to ApiErrorCodes.FORBIDDEN,
            404 to ApiErrorCodes.NOT_FOUND,
            413 to "payload_too_large",
            413 to ApiErrorCodes.HTTP_ERROR,
            423 to ApiErrorCodes.STORE_LOCKED,
            423 to "installation_locked",
            429 to ApiErrorCodes.TOO_MANY_REQUESTS,
            503 to ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED,
            503 to ApiErrorCodes.SERVER_ERROR,
            400 to ApiErrorCodes.HTTP_ERROR,
            // Kode tak dikenal pada 409/422 bisa berarti "kunci ini sedang diproses": justru ada percobaan sebelumnya.
            409 to "idempotency_in_progress",
            422 to "kode_baru_dari_server",
            // Status dan kode harus cocok: kode jawaban pasti pada status lain bukan jawaban handler.
            404 to ApiErrorCodes.VALIDATION_FAILED,
            409 to ApiErrorCodes.VALIDATION_FAILED,
        ).forEach { (status, code) ->
            assertFalse("$status $code", CheckoutRules.settlesPendingIntent(ApiError.Http(status, code)))
        }
        listOf(ApiError.Timeout, ApiError.Network, ApiError.Unknown, ApiError.SessionStorage).forEach { error ->
            assertFalse(error.toString(), CheckoutRules.settlesPendingIntent(error))
        }
    }

    // --- sidik niat checkout ---

    @Test
    fun `sidik sama untuk isi pesanan yang sama tanpa bergantung urutan barang`() {
        val reordered = request.copy(items = request.items.reversed())

        assertEquals(CheckoutFingerprint.of(request), CheckoutFingerprint.of(reordered))
        assertEquals(64, CheckoutFingerprint.of(request).length)
    }

    @Test
    fun `sidik berubah bila metode alamat kontak catatan atau barang berubah`() {
        val base = CheckoutFingerprint.of(request)

        listOf(
            request.copy(method = FulfilmentMethod.Pickup, addressId = null),
            request.copy(addressId = 6),
            request.copy(contactName = "Pelanggan Lain"),
            request.copy(contactPhone = "0800-1111-1111"),
            request.copy(notes = "Tolong dibungkus rapi."),
            request.copy(items = listOf(CheckoutItem(31, 3), CheckoutItem(32, 1))),
            request.copy(items = listOf(CheckoutItem(31, 2))),
            // Batas kolom tidak boleh ambigu: "ab" + "c" berbeda dari "a" + "bc".
            request.copy(contactName = "Pelanggan Uji0", contactPhone = "800-0000-0000"),
        ).forEach { changed -> assertNotEquals(base, CheckoutFingerprint.of(changed)) }
    }

    @Test
    fun `sidik dan toString tidak memuat data pribadi`() {
        val fingerprint = CheckoutFingerprint.of(request)

        assertFalse(fingerprint.contains("Pelanggan"))
        assertFalse(fingerprint.contains("0800"))
        assertFalse(request.toString().contains("Pelanggan"))
        assertFalse(request.toString().contains("0800"))
    }

    @Test
    fun `kunci idempotensi bawaan sesuai pola kontrak dan berbeda setiap kali`() {
        val generator = UuidIdempotencyKeyGenerator()
        val pattern = Regex("^[A-Za-z0-9_-]{16,64}$")

        val keys = List(20) { generator.newKey() }

        assertTrue(keys.all(pattern::matches))
        assertEquals(20, keys.toSet().size)
    }

    // --- aturan kontak pesanan ---

    @Test
    fun `telepon pemesan wajib dan catatan dibatasi 500 karakter`() {
        assertEquals(FieldIssue.Required, CheckoutRules.contactPhone("  "))
        assertNull(CheckoutRules.contactPhone("0800-0000-0000"))
        assertEquals(FieldIssue.TooLong(30), CheckoutRules.contactPhone("0".repeat(31)))
        assertEquals(FieldIssue.Required, CheckoutRules.contactName(""))
        assertNull(CheckoutRules.notes("x".repeat(500)))
        assertEquals(FieldIssue.TooLong(500), CheckoutRules.notes("x".repeat(501)))
        assertNull(CheckoutRules.notes(""))
    }

    // --- aturan alamat ---

    private val draft = AddressDraft(
        label = "Rumah",
        recipientName = "Pelanggan Uji",
        phone = "0800-0000-0000",
        addressLine = "Jl. Contoh No. 1",
        city = "Kota Uji",
        province = "Provinsi Uji",
        postalCode = "00000",
        notes = "",
    )

    @Test
    fun `alamat lengkap lolos dan kolom opsional boleh kosong`() {
        assertTrue(AddressRules.validate(draft).isEmpty())
        assertTrue(AddressRules.validate(draft.copy(label = "", postalCode = "  ", notes = "")).isEmpty())
    }

    @Test
    fun `kolom wajib yang kosong dan kolom yang terlalu panjang dilaporkan per kolom`() {
        val issues = AddressRules.validate(
            AddressDraft(
                label = "x".repeat(51),
                recipientName = "  ",
                phone = "",
                addressLine = "x".repeat(256),
                city = "",
                province = "x".repeat(101),
                postalCode = "12345678901",
                notes = "x".repeat(256),
            ),
        )

        assertEquals(
            mapOf(
                AddressField.Label to FieldIssue.TooLong(50),
                AddressField.RecipientName to FieldIssue.Required,
                AddressField.Phone to FieldIssue.Required,
                AddressField.AddressLine to FieldIssue.TooLong(255),
                AddressField.City to FieldIssue.Required,
                AddressField.Province to FieldIssue.TooLong(100),
                AddressField.PostalCode to FieldIssue.TooLong(10),
                AddressField.Notes to FieldIssue.TooLong(255),
            ),
            issues,
        )
    }

    @Test
    fun `draf alamat menjadi masukan dengan kolom opsional kosong sebagai null dan tidak bocor lewat toString`() {
        val input = draft.copy(label = " ", notes = "", isDefault = true).toInput()

        assertNull(input.label)
        assertNull(input.notes)
        assertEquals("00000", input.postalCode)
        assertTrue(input.isDefault)
        assertFalse(draft.toString().contains("Contoh"))
        assertFalse(draft.toString().contains("0800"))
        assertEquals("Kota Uji", draft.with(AddressField.City, "Kota Uji").valueOf(AddressField.City))
    }
}
