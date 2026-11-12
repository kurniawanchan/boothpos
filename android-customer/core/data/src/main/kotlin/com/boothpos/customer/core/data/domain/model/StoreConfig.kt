package com.boothpos.customer.core.data.domain.model

enum class FulfilmentMethod(val wireValue: String) {
    Pickup("pickup"),
    Courier("courier"),
    ;

    companion object {
        /** Nilai tak dikenal diabaikan (null), supaya metode baru di server tidak merusak aplikasi lama. */
        fun fromWire(value: String?): FulfilmentMethod? = entries.firstOrNull { it.wireValue == value }
    }
}

/** Konfigurasi publik toko (`GET /storefront/config`). Semua nilainya ditentukan server. */
data class StoreConfig(
    val enabled: Boolean,
    val storeName: String,
    val currency: String,
    val fulfilmentMethods: Set<FulfilmentMethod>,
    val pickupInstructions: String?,
    val courierInstructions: String?,
    val maxQtyPerLine: Int,
    val maxLinesPerOrder: Int,
) {
    companion object {
        // Nilai bawaan kontrak §4; hanya dipakai sebelum konfigurasi pertama kali berhasil diambil.
        const val DEFAULT_MAX_QTY_PER_LINE = 20
        const val DEFAULT_MAX_LINES_PER_ORDER = 50
    }
}
