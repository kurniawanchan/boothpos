package com.warkop.pos.data

import com.warkop.core.network.ApiClient
import com.warkop.core.network.dto.TabDto
import com.warkop.pos.model.discountLabel
import org.junit.Assert.assertEquals
import org.junit.Test

/** Label diskon hanya memakai persen dari server; tanpa persen → jumlah saja (tidak pernah "0%"). */
class DiscountLabelTest {
    @Test fun `tanpa persen dari server label hanya Diskon`() {
        assertEquals("Diskon", discountLabel(0))
    }

    @Test fun `persen dari server ditampilkan`() {
        assertEquals("Diskon 10%", discountLabel(10))
    }

    @Test fun `tab server dengan jumlah diskon tanpa persen tidak menampilkan 0 persen`() {
        val tab = ApiClient.json.decodeFromString(
            TabDto.serializer(),
            """{"id":2050,"status":"completed","subtotal":"60000.00","discount_amount":"6000.00","tax_amount":"5400.00","total_amount":"59400.00","paid_amount":"59400.00"}""",
        )
        val o = OrderMapper.tab(tab, myDeviceCode = null)
        assertEquals(6000, o.discount)
        assertEquals(0, o.discPct)
        assertEquals("Diskon", discountLabel(o.discPct))
    }
}
