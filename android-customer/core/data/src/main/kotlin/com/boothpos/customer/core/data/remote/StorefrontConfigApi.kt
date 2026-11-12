package com.boothpos.customer.core.data.remote

import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.domain.model.StoreConfig
import com.boothpos.customer.core.network.dto.DataEnvelope
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import retrofit2.http.GET

@Serializable
data class StoreConfigDto(
    val enabled: Boolean = false,
    @SerialName("store_name") val storeName: String = "",
    val currency: String = "IDR",
    @SerialName("fulfilment_methods") val fulfilmentMethods: List<String> = emptyList(),
    @SerialName("pickup_instructions") val pickupInstructions: String? = null,
    @SerialName("courier_instructions") val courierInstructions: String? = null,
    @SerialName("max_qty_per_line") val maxQtyPerLine: Int = StoreConfig.DEFAULT_MAX_QTY_PER_LINE,
    @SerialName("max_lines_per_order") val maxLinesPerOrder: Int = StoreConfig.DEFAULT_MAX_LINES_PER_ORDER,
)

fun StoreConfigDto.toDomain() = StoreConfig(
    enabled = enabled,
    storeName = storeName,
    currency = currency,
    fulfilmentMethods = fulfilmentMethods.mapNotNull(FulfilmentMethod::fromWire).toSet(),
    pickupInstructions = pickupInstructions?.takeIf { it.isNotBlank() },
    courierInstructions = courierInstructions?.takeIf { it.isNotBlank() },
    // Nilai dari server tetap dijaga masuk akal: batas nol/negatif akan membuat keranjang tak bisa dipakai.
    maxQtyPerLine = maxQtyPerLine.coerceAtLeast(1),
    maxLinesPerOrder = maxLinesPerOrder.coerceAtLeast(1),
)

interface StorefrontConfigApi {
    /** Tidak digerbangi feature flag (kontrak §2), tetapi tetap di belakang gerbang lisensi (423). */
    @GET("storefront/config")
    suspend fun config(): DataEnvelope<StoreConfigDto>
}
