package com.warkop.manager.ui.purchase

import com.warkop.core.domain.ApiError
import com.warkop.core.network.ApiClient
import com.warkop.manager.data.ManagerApi
import com.warkop.manager.data.PurchaseRepository
import com.warkop.manager.data.dto.MaterialBody
import com.warkop.manager.data.dto.MaterialDto
import com.warkop.manager.data.dto.MaterialOutletBalance
import com.warkop.manager.data.dto.PoStatusBody
import com.warkop.manager.data.dto.PurchaseOrderDto
import com.warkop.manager.domain.MaterialBalance
import com.warkop.manager.domain.MaterialForm
import com.warkop.manager.domain.PoLine
import com.warkop.manager.domain.PoStatus
import com.warkop.manager.domain.PurchaseOrderForm
import com.warkop.manager.domain.VendorForm
import com.warkop.manager.domain.cleanCode20
import com.warkop.manager.ui.components.Load
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import kotlinx.coroutines.withTimeout
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import java.math.BigDecimal

/** 094 / T084 — PO (transisi, 409), Bahan (`standard_cost`, saldo outlet), Vendor. */
@OptIn(ExperimentalCoroutinesApi::class)
class PurchaseFormsTest {
    @Before fun setUp() = Dispatchers.setMain(UnconfinedTestDispatcher())
    @After fun tearDown() = Dispatchers.resetMain()

    @Test fun `tombol transisi mengikuti graf server`() {
        assertEquals(listOf("ordered", "cancelled"), PoStatus.next("draft"))
        assertEquals(listOf("received", "cancelled"), PoStatus.next("ordered"))
        assertEquals(listOf("paid"), PoStatus.next("received"))
        assertTrue(PoStatus.next("paid").isEmpty() && PoStatus.next("cancelled").isEmpty())
        assertFalse(PoStatus.canPay("ordered")); assertTrue(PoStatus.canPay("received")); assertTrue(PoStatus.canPay("paid"))
    }

    @Test fun `409 transisi tidak sah ditampilkan lalu data dimuat ulang`() = runBlocking {
        var loads = 0
        val api = object : ManagerApi by unused() {
            override suspend fun purchaseOrder(id: Long): PurchaseOrderDto { loads++; return PurchaseOrderDto(id, status = "received") }
            override suspend fun setPurchaseOrderStatus(id: Long, body: PoStatusBody): PurchaseOrderDto = throw ApiError.Conflict("Tidak bisa mengubah status dari received ke cancelled.", null)
        }
        val vm = PurchaseOrderDetailViewModel(PurchaseRepository { api }, 5)
        vm.load()
        withTimeout(5_000) { vm.po.first { it is Load.Ready } }
        vm.transition("cancelled", "x") {}
        val st = withTimeout(5_000) { vm.status.first { !it.saving && it.message != null } }
        assertEquals("Tidak bisa mengubah status dari received ke cancelled.", st.message)
        withTimeout(5_000) { while (loads < 2) kotlinx.coroutines.delay(20) }
        assertEquals("received", (vm.po.value as Load.Ready).data.status)
    }

    @Test fun `baris PO terkunci setelah draft - items tidak dikirim`() {
        val draft = PurchaseOrderForm(id = 1, status = "draft", vendorId = 2, lines = listOf(PoLine(materialId = 3, qty = "2", unitPrice = "5000")))
        assertEquals(1, draft.toBody(9).items!!.size)
        assertEquals(9L, draft.toBody(9).outletId)
        val ordered = draft.copy(status = "ordered", lines = emptyList())
        assertNull(ordered.toBody(9).items)
        assertTrue(ordered.localErrors().isEmpty()) // baris tidak divalidasi saat terkunci
    }

    @Test fun `validasi baris PO - bahan wajib untuk material, deskripsi untuk jasa, qty minimal 0,001`() {
        val f = PurchaseOrderForm(vendorId = 1, lines = listOf(PoLine(type = "material"), PoLine(type = "service", qty = "0.0001")))
        val e = f.localErrors()
        assertTrue("items.0.material_id" in e); assertTrue("items.1.description" in e); assertTrue("items.1.qty" in e)
        val body = PurchaseOrderForm(vendorId = 1, lines = listOf(PoLine(type = "service", materialId = 7, description = " Ongkir "))).toBody(1)
        assertNull(body.items!![0].materialId); assertEquals("Ongkir", body.items!![0].description)
    }

    @Test fun `standard_cost hanya terkirim untuk semi_finished`() {
        fun json(b: MaterialBody) = ApiClient.json.encodeToString(MaterialBody.serializer(), b)
        val semi = MaterialForm(code = "sr", name = "Sirup", unit = "ml", kind = "semi_finished", standardCost = "12.5").toBody()
        assertEquals(12.5, semi.standardCost!!, 0.0)
        assertTrue("standard_cost" in json(semi))
        val ing = MaterialForm(code = "gl", name = "Gula", unit = "gram", kind = "ingredient", standardCost = "9").toBody()
        assertFalse("standard_cost" in json(ing))
        assertEquals("GL", ing.code)
        assertNull(MaterialForm(id = 1, code = "GL", name = "Gula", unit = "gram").toBody().code)
    }

    @Test fun `saldo bahan = outlet terpilih atau 0, tidak pernah total`() {
        val m = MaterialDto(1, name = "Gula", unit = "kg", currentStock = "15.000", outletStock = listOf(MaterialOutletBalance(3, "WJ3", onHand = "15.000")))
        assertEquals(0, MaterialBalance.of(m, outletId = 4).compareTo(BigDecimal.ZERO))
        assertEquals("15 kg", MaterialBalance.text(m, 3))
        assertEquals("−2,5 kg", MaterialBalance.text(m.copy(outletStock = listOf(MaterialOutletBalance(3, onHand = "-2.500"))), 3))
    }

    @Test fun `satuan di luar preset membuka mode lainnya`() {
        assertTrue(MaterialForm.of(MaterialDto(1, name = "X", unit = "lusin")).customUnit)
        assertFalse(MaterialForm.of(MaterialDto(1, name = "X", unit = "kg")).customUnit)
    }

    @Test fun `vendor - kode alpha_dash huruf besar maks 20, surel dicek, kode tidak dikirim saat ubah`() {
        assertEquals("SUP-01_A", cleanCode20("sup-01_a!"))
        assertEquals(20, cleanCode20("a".repeat(30)).length)
        assertTrue("contact_email" in VendorForm(code = "A", name = "B", email = "bukan-surel").localErrors())
        assertNull(VendorForm(id = 1, code = "A", name = "B").toBody().code)
        assertNull(VendorForm(code = "A", name = "B", phone = " ").toBody().contactPhone)
    }
}

private fun unused(): ManagerApi = java.lang.reflect.Proxy.newProxyInstance(
    ManagerApi::class.java.classLoader, arrayOf(ManagerApi::class.java),
) { _, m, _ -> error("ManagerApi.${m.name} tidak dipakai di tes ini") } as ManagerApi
