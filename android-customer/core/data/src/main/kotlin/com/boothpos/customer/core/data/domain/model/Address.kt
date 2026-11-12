package com.boothpos.customer.core.data.domain.model

/** Alamat kirim tersimpan milik akun (kontrak §7). */
data class Address(
    val id: Long,
    val label: String?,
    val recipientName: String,
    val phone: String,
    val addressLine: String,
    val city: String,
    val province: String,
    val postalCode: String?,
    val notes: String?,
    val isDefault: Boolean,
) {
    // Alamat dan kontak penerima adalah data pribadi: tidak boleh tercetak di log/laporan crash.
    override fun toString(): String = "Address(id=$id, isDefault=$isDefault)"
}

/** Isian alamat untuk buat/ubah. Server yang memvalidasi dan menegakkan satu alamat utama. */
data class AddressInput(
    val label: String?,
    val recipientName: String,
    val phone: String,
    val addressLine: String,
    val city: String,
    val province: String,
    val postalCode: String?,
    val notes: String?,
    val isDefault: Boolean,
) {
    // Seperti [Address]: isian alamat tidak boleh tercetak di log/laporan crash.
    override fun toString(): String = "AddressInput(isDefault=$isDefault)"
}
