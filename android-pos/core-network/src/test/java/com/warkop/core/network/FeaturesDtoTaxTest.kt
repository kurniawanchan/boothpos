package com.warkop.core.network

import com.warkop.core.network.dto.FeaturesDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 083 — `tax_show` dari GET /settings/features: false menyembunyikan baris; server lama tanpa field = null (dianggap tampil). */
class FeaturesDtoTaxTest {
    private val json = ApiClient.json

    @Test fun `tax_show dibaca`() {
        assertEquals(false, json.decodeFromString(FeaturesDto.serializer(), """{"tax_label":"PPN","tax_rate":10,"tax_show":false}""").taxShow)
        assertEquals(true, json.decodeFromString(FeaturesDto.serializer(), """{"tax_show":true}""").taxShow)
    }

    @Test fun `server lama tanpa tax_show membaca null`() {
        assertNull(json.decodeFromString(FeaturesDto.serializer(), """{"tax_label":"PPN","tax_rate":10}""").taxShow)
    }
}
