package com.boothpos.customer.feature.checkout.domain

import com.boothpos.customer.core.data.domain.model.Address
import com.boothpos.customer.core.data.domain.model.AddressInput
import com.boothpos.customer.core.data.domain.validation.ContactRules
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.data.domain.validation.characterCount
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes

/**
 * Pemeriksaan BENTUK masukan checkout di perangkat (kontrak §8), hanya supaya galat muncul
 * langsung di kolomnya. Server memvalidasi ulang dan keputusannya yang berlaku.
 */
object CheckoutRules {
    const val NOTES_MAX = 500

    fun contactName(raw: String): FieldIssue? = ContactRules.name(raw)

    /** Berbeda dari profil: untuk pesanan, nomor telepon WAJIB ("phone required in the end result"). */
    fun contactPhone(raw: String): FieldIssue? =
        if (raw.isBlank()) FieldIssue.Required else ContactRules.phone(raw)

    fun notes(raw: String): FieldIssue? =
        if (raw.trim().characterCount() > NOTES_MAX) FieldIssue.TooLong(NOTES_MAX) else null

    /**
     * Apakah [error] dari `POST /customer/orders` adalah jawaban PASTI dari handler pembuatan
     * pesanan, sehingga niat tertunda (kunci idempotensi yang hasilnya belum pasti) boleh dilupakan.
     *
     * Hanya jawaban handler yang boleh: 409 (`price_changed`, `cart_invalid`,
     * `idempotency_key_reused`) dan 422 (`validation_failed`, `idempotency_key_required`). Penolakan
     * middleware (401, 403, 404, 413, 423, 429, 503) terjadi SEBELUM pencarian kunci idempotensi di
     * server, jadi tidak mengatakan apa pun tentang percobaan sebelumnya dengan kunci yang sama:
     * pesanan itu bisa saja sudah tercatat. Melupakan niat karenanya membuat kunjungan checkout
     * berikutnya memakai kunci baru dan menggandakan pesanan. Status atau kode apa pun di luar daftar
     * (termasuk 409/422 dengan kode yang belum dikenal) juga TIDAK menyelesaikan niat: menyimpannya
     * lebih lama tidak berbahaya, melupakannya terlalu cepat berbahaya.
     */
    fun settlesPendingIntent(error: ApiError): Boolean {
        val http = error as? ApiError.Http ?: return false
        return when (http.status) {
            STATUS_CONFLICT -> http.code in CONFLICT_ANSWERS
            STATUS_UNPROCESSABLE -> http.code in UNPROCESSABLE_ANSWERS
            else -> false
        }
    }

    private const val STATUS_CONFLICT = 409
    private const val STATUS_UNPROCESSABLE = 422
    private val CONFLICT_ANSWERS = setOf(
        ApiErrorCodes.PRICE_CHANGED,
        ApiErrorCodes.CART_INVALID,
        ApiErrorCodes.IDEMPOTENCY_KEY_REUSED,
    )
    private val UNPROCESSABLE_ANSWERS = setOf(
        ApiErrorCodes.VALIDATION_FAILED,
        ApiErrorCodes.IDEMPOTENCY_KEY_REQUIRED,
    )
}

/** Kolom formulir alamat; [wireName] adalah kunci galat per kolom dari server (422). */
enum class AddressField(val wireName: String) {
    Label("label"),
    RecipientName("recipient_name"),
    Phone("phone"),
    AddressLine("address_line"),
    City("city"),
    Province("province"),
    PostalCode("postal_code"),
    Notes("notes"),
}

/** Isian formulir alamat apa adanya (belum di-trim). */
data class AddressDraft(
    val label: String = "",
    val recipientName: String = "",
    val phone: String = "",
    val addressLine: String = "",
    val city: String = "",
    val province: String = "",
    val postalCode: String = "",
    val notes: String = "",
    val isDefault: Boolean = false,
) {
    fun valueOf(field: AddressField): String = when (field) {
        AddressField.Label -> label
        AddressField.RecipientName -> recipientName
        AddressField.Phone -> phone
        AddressField.AddressLine -> addressLine
        AddressField.City -> city
        AddressField.Province -> province
        AddressField.PostalCode -> postalCode
        AddressField.Notes -> notes
    }

    fun with(field: AddressField, value: String): AddressDraft = when (field) {
        AddressField.Label -> copy(label = value)
        AddressField.RecipientName -> copy(recipientName = value)
        AddressField.Phone -> copy(phone = value)
        AddressField.AddressLine -> copy(addressLine = value)
        AddressField.City -> copy(city = value)
        AddressField.Province -> copy(province = value)
        AddressField.PostalCode -> copy(postalCode = value)
        AddressField.Notes -> copy(notes = value)
    }

    /** Kolom opsional yang kosong menjadi null; pemangkasan spasi dilakukan lapisan data. */
    fun toInput() = AddressInput(
        label = label.takeIf { it.isNotBlank() },
        recipientName = recipientName,
        phone = phone,
        addressLine = addressLine,
        city = city,
        province = province,
        postalCode = postalCode.takeIf { it.isNotBlank() },
        notes = notes.takeIf { it.isNotBlank() },
        isDefault = isDefault,
    )

    // Alamat adalah data pribadi: tidak boleh muncul bila objek ini tercetak di log/laporan crash.
    override fun toString(): String = "AddressDraft(isDefault=$isDefault)"

    companion object {
        fun from(address: Address) = AddressDraft(
            label = address.label.orEmpty(),
            recipientName = address.recipientName,
            phone = address.phone,
            addressLine = address.addressLine,
            city = address.city,
            province = address.province,
            postalCode = address.postalCode.orEmpty(),
            notes = address.notes.orEmpty(),
            isDefault = address.isDefault,
        )
    }
}

fun Address.toInput(isDefault: Boolean = this.isDefault) = AddressInput(
    label = label,
    recipientName = recipientName,
    phone = phone,
    addressLine = addressLine,
    city = city,
    province = province,
    postalCode = postalCode,
    notes = notes,
    isDefault = isDefault,
)

/** Batas panjang mengikuti kolom `customer_addresses` (kontrak §4). */
object AddressRules {
    private val required = setOf(
        AddressField.RecipientName,
        AddressField.Phone,
        AddressField.AddressLine,
        AddressField.City,
        AddressField.Province,
    )

    fun maxLength(field: AddressField): Int = when (field) {
        AddressField.Label -> 50
        AddressField.RecipientName -> 100
        AddressField.Phone -> 30
        AddressField.AddressLine -> 255
        AddressField.City -> 100
        AddressField.Province -> 100
        AddressField.PostalCode -> 10
        AddressField.Notes -> 255
    }

    fun isRequired(field: AddressField): Boolean = field in required

    fun validate(field: AddressField, raw: String): FieldIssue? {
        val value = raw.trim()
        val max = maxLength(field)
        return when {
            value.isEmpty() -> if (isRequired(field)) FieldIssue.Required else null
            value.characterCount() > max -> FieldIssue.TooLong(max)
            else -> null
        }
    }

    /** Hanya kolom yang bermasalah; kosong berarti formulir boleh dikirim. */
    fun validate(draft: AddressDraft): Map<AddressField, FieldIssue> =
        AddressField.entries.mapNotNull { field ->
            validate(field, draft.valueOf(field))?.let { field to it }
        }.toMap()
}
