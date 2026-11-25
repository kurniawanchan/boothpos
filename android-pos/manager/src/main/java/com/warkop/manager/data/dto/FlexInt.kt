package com.warkop.manager.data.dto

import kotlinx.serialization.KSerializer
import kotlinx.serialization.descriptors.PrimitiveKind
import kotlinx.serialization.descriptors.PrimitiveSerialDescriptor
import kotlinx.serialization.json.JsonDecoder
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.doubleOrNull
import kotlinx.serialization.encoding.Decoder
import kotlinx.serialization.encoding.Encoder

/**
 * Bilangan bulat yang server kirim TANPA cast model (mis. `prep_minutes`, `low_stock_alert`): tergantung driver PDO bisa
 * angka atau string, dan `outlet_stock` menu beresep bisa desimal. Diterima semuanya (dibulatkan ke bawah) supaya satu
 * kolom tidak menggagalkan seluruh detail produk.
 */
object FlexIntSerializer : KSerializer<Int?> {
    override val descriptor = PrimitiveSerialDescriptor("FlexInt", PrimitiveKind.INT)

    override fun deserialize(decoder: Decoder): Int? {
        val el = (decoder as? JsonDecoder)?.decodeJsonElement() ?: return decoder.decodeInt()
        if (el is JsonNull || el !is JsonPrimitive) return null
        return el.content.toIntOrNull() ?: el.doubleOrNull?.let { kotlin.math.floor(it).toInt() } ?: el.content.toDoubleOrNull()?.let { kotlin.math.floor(it).toInt() }
    }

    override fun serialize(encoder: Encoder, value: Int?) {
        if (value == null) encoder.encodeNull() else encoder.encodeInt(value)
    }
}
