package com.boothpos.customer.feature.checkout.domain

import java.security.MessageDigest
import java.util.UUID
import javax.inject.Inject

/** Pembuat `Idempotency-Key` (kontrak §8: `^[A-Za-z0-9_-]{16,64}$`). Disuntikkan supaya tes deterministik. */
fun interface IdempotencyKeyGenerator {
    fun newKey(): String
}

/** UUID acak: 36 karakter heksadesimal + tanda hubung, sesuai pola kunci di kontrak. */
class UuidIdempotencyKeyGenerator @Inject constructor() : IdempotencyKeyGenerator {
    override fun newKey(): String = UUID.randomUUID().toString()
}

/**
 * Sidik isi pesanan. Kunci idempotensi diikat ke sidik ini: selama isi pesanan (metode, alamat,
 * kontak, barang, catatan) sama, kunci yang sama dipakai ulang; begitu isinya berubah, itu niat
 * baru dan kuncinya baru. Cakupannya sama dengan hash payload server (tanpa `expected_total`),
 * sehingga perubahan harga saja tidak mengganti kunci.
 *
 * Disimpan sebagai SHA-256, bukan teks aslinya, supaya nama/telepon tidak ikut tersimpan dua kali.
 */
object CheckoutFingerprint {
    private const val SEPARATOR = '\u001F'

    fun of(request: CheckoutRequest): String {
        val canonical = buildString {
            append(request.method.wireValue).append(SEPARATOR)
            append(request.addressId ?: "").append(SEPARATOR)
            append(request.contactName).append(SEPARATOR)
            append(request.contactPhone).append(SEPARATOR)
            append(request.notes.orEmpty()).append(SEPARATOR)
            request.items.sortedBy { it.variantId }.forEach { item ->
                append(item.variantId).append(':').append(item.quantity).append(',')
            }
        }
        return MessageDigest.getInstance("SHA-256")
            .digest(canonical.toByteArray(Charsets.UTF_8))
            .joinToString("") { byte -> "%02x".format(byte) }
    }
}
