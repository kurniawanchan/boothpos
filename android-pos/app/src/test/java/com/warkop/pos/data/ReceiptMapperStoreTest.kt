package com.warkop.pos.data

import com.warkop.core.network.ApiClient
import com.warkop.core.network.dto.ReceiptDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 059 / T014, T027 — identitas toko di struk: kontak, logo (dipasang ulang ke server), nama dan alamat dari payload. */
class ReceiptMapperStoreTest {
    private fun dto(json: String) = ApiClient.json.decodeFromString(ReceiptDto.serializer(), json)

    @Test fun `kontak toko terpetakan`() {
        val r = ReceiptMapper.map(dto("""{"store_contact_person":"Pak Budi","store_contact_phone":"0812","store_contact_email":"toko@x.id"}"""))
        assertEquals("Pak Budi", r.storeContactPerson)
        assertEquals("0812", r.storeContactPhone)
        assertEquals("toko@x.id", r.storeContactEmail)
    }

    @Test fun `kontak kosong atau spasi menjadi null`() {
        val r = ReceiptMapper.map(dto("""{"store_contact_person":"  ","store_contact_phone":"","store_contact_email":" "}"""))
        assertNull(r.storeContactPerson)
        assertNull(r.storeContactPhone)
        assertNull(r.storeContactEmail)
    }

    @Test fun `logo null tetap null`() {
        assertNull(ReceiptMapper.map(dto("""{"store_logo_url":null}"""), serverBase = "http://192.168.1.219:8010").storeLogoUrl)
    }

    @Test fun `logo localhost dipasang ulang ke server`() {
        val r = ReceiptMapper.map(
            dto("""{"store_logo_url":"http://localhost/storage/logo.png?v=3"}"""),
            serverBase = "http://192.168.1.219:8010",
        )
        assertEquals("http://192.168.1.219:8010/storage/logo.png?v=3", r.storeLogoUrl)
    }

    @Test fun `nama dan alamat toko dibaca dari payload apa adanya`() {
        // Tidak ada penimpaan di tablet: nama dan alamat dari pengaturan admin untuk mode aktif (T027).
        val r = ReceiptMapper.map(dto("""{"store_name":"Warkop Baru","store_address":"Jl. Baru 9"}"""))
        assertEquals("Warkop Baru", r.storeName)
        assertEquals("Jl. Baru 9", r.storeAddress)
    }

    @Test fun `payload yang sama menghasilkan struk yang sama untuk cetak ulang`() {
        val json = """{"store_name":"Warkop","store_contact_phone":"0812","order_number":"O-7"}"""
        assertEquals(ReceiptMapper.map(dto(json)), ReceiptMapper.map(dto(json)))
    }
}
