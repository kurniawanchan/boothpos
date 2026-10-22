package com.warkop.pos.data

import com.warkop.core.network.ApiClient
import com.warkop.core.network.dto.FloorTabDto
import com.warkop.core.network.dto.HeartbeatResponseDto
import com.warkop.core.network.dto.TabDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 064 — terminal dikenali lewat NAMA: label "terminal lain" memakai nama, dibandingkan lewat id; tanpa kode T01. */
class OrderMapperTerminalTest {
    private fun tab(device: String?) = ApiClient.json.decodeFromString(
        TabDto.serializer(),
        """{"id":7,"status":"open","subtotal":"0.00","total_amount":"0.00"${device?.let { ",\"device\":$it" } ?: ""}}""",
    )

    @Test fun `tab terminal ini tidak diberi label terminal lain tetapi namanya tetap terbaca`() {
        val o = OrderMapper.tab(tab("""{"id":5,"name":"Kasir Depan"}"""), myDeviceId = 5)
        assertNull(o.otherDevice)
        assertEquals("Kasir Depan", o.terminalName)
    }

    @Test fun `tab terminal lain diberi label berupa nama terminalnya`() {
        val o = OrderMapper.tab(tab("""{"id":6,"name":"Kasir Belakang"}"""), myDeviceId = 5)
        assertEquals("Kasir Belakang", o.otherDevice)
        assertEquals("Kasir Belakang", o.terminalName)
    }

    @Test fun `tab tanpa terminal tidak punya nama maupun label`() {
        val o = OrderMapper.tab(tab(null), myDeviceId = 5)
        assertNull(o.otherDevice)
        assertNull(o.terminalName)
    }

    @Test fun `nama terminal kosong tidak menjadi label kosong`() {
        val o = OrderMapper.tab(tab("""{"id":6,"name":"  "}"""), myDeviceId = 5)
        assertNull(o.otherDevice)
        assertNull(o.terminalName.takeIf { !it.isNullOrBlank() })
    }

    @Test fun `denah memberi label nama hanya untuk terminal lain`() {
        fun floor(id: Long?, name: String?) = ApiClient.json.decodeFromString(
            FloorTabDto.serializer(),
            """{"id":1,"total_amount":"0.00","paid_amount":"0.00"${id?.let { ",\"device_id\":$it" } ?: ""}${name?.let { ",\"device_name\":\"$it\"" } ?: ""}}""",
        )
        assertNull(OrderMapper.floorTab(floor(5, "Kasir Depan"), myDeviceId = 5, tableName = null).otherDevice)
        assertEquals("Kasir Belakang", OrderMapper.floorTab(floor(6, "Kasir Belakang"), myDeviceId = 5, tableName = null).otherDevice)
        assertNull(OrderMapper.floorTab(floor(null, null), myDeviceId = 5, tableName = null).otherDevice)
    }

    @Test fun `heartbeat membawa nama terminal untuk tablet yang dipasang sebelum fitur ini`() {
        val dto = ApiClient.json.decodeFromString(HeartbeatResponseDto.serializer(), """{"server_time_ms":1,"skew_seconds":0,"name":" Kasir Depan "}""")
        assertEquals("Kasir Depan", HeartbeatMapper.result(dto).terminalName)
        val old = ApiClient.json.decodeFromString(HeartbeatResponseDto.serializer(), """{"server_time_ms":1,"skew_seconds":0}""")
        assertNull(HeartbeatMapper.result(old).terminalName)
    }
}
