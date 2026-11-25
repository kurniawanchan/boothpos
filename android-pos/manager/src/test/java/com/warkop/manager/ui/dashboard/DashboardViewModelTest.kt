package com.warkop.manager.ui.dashboard

import androidx.lifecycle.SavedStateHandle
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.DashboardRepository
import com.warkop.manager.data.ManagerApi
import com.warkop.manager.data.dto.AttentionDto
import com.warkop.manager.data.dto.DashboardOrdersDto
import com.warkop.manager.data.dto.DashboardSummaryDto
import com.warkop.manager.data.dto.KpiDto
import com.warkop.manager.data.dto.OpenShiftsDto
import com.warkop.manager.domain.DashboardModel
import com.warkop.manager.domain.MoneyField
import com.warkop.manager.domain.Period
import com.warkop.manager.domain.Tone
import com.warkop.manager.ui.components.Load
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.withTimeout
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** 094 / T054 — Dashboard: tidak menghitung angka, rupiah bahan mengikuti server, periode memuat ulang. */
@OptIn(ExperimentalCoroutinesApi::class)
class DashboardViewModelTest {
    private val periodsAsked = java.util.Collections.synchronizedList(mutableListOf<String>())
    private var failSummary = false
    private var materials: JsonObject = materialsJson(withMoney = true)

    private val api = object : ManagerApi by unused() {
        override suspend fun dashboardSummary(period: String): DashboardSummaryDto {
            periodsAsked += period
            if (failSummary) throw ApiError.Network()
            return DashboardSummaryDto(
                period = period,
                kpis = mapOf(
                    "net_sales" to KpiDto(value = JsonPrimitive("1250000.00"), changePct = 6.83),
                    "low_stock" to KpiDto(value = JsonPrimitive(4), changeAbs = 2, scope = "organisation"),
                ),
            )
        }
        override suspend fun dashboardAttention() = AttentionDto()
        override suspend fun dashboardShifts() = OpenShiftsDto()
        override suspend fun dashboardMaterials(period: String) = materials
        override suspend fun dashboardOrders(period: String, tab: String, page: Int, perPage: Int) = DashboardOrdersDto()
    }

    @Before fun setUp() = Dispatchers.setMain(UnconfinedTestDispatcher())
    @After fun tearDown() = Dispatchers.resetMain()

    private fun vm() = DashboardViewModel(DashboardRepository { api }, SavedStateHandle())

    /** `apiCall` berjalan di Dispatchers.IO (utas sungguhan): tunggu sampai keadaan memenuhi syarat. */
    private fun DashboardViewModel.until(pred: (DashboardState) -> Boolean): DashboardState =
        runBlocking { withTimeout(5_000) { state.first(pred) } }

    private fun DashboardViewModel.settled() = until { s -> listOf(s.summary, s.materials, s.attention, s.shifts, s.orders).none { it is Load.Loading || (it is Load.Ready<*> && it.refreshing) } }

    @Test fun `kartu memformat angka server tanpa menghitung ulang`() {
        val summary = (vm().settled().summary as Load.Ready).data
        val net = summary.cards.first { it.key == "net_sales" }
        assertEquals("Rp 1.250.000", net.value)
        assertEquals("↑ +6,8%", net.change.text)
        assertEquals(Tone.GOOD, net.change.tone)
        // Stok menipis naik = BURUK, dan cakupannya seluruh organisasi.
        val low = summary.cards.first { it.key == "low_stock" }
        assertEquals("4", low.value)
        assertEquals(Tone.BAD, low.change.tone)
        assertTrue(low.orgScope)
        // KPI yang tidak dikirim server tidak dibuat-buat.
        assertEquals(listOf("net_sales", "low_stock"), summary.cards.map { it.key })
    }

    @Test fun `chip mengikuti angka yang DITAMPILKAN, bukan angka mentah`() {
        assertEquals(Tone.NEUTRAL, DashboardModel.change(0.04, percent = true, goodWhenUp = true).tone)
        assertEquals("→ 0,0%", DashboardModel.change(0.04, percent = true, goodWhenUp = true).text)
        assertEquals("Tidak ada pembanding", DashboardModel.change(null, percent = true, goodWhenUp = true).text)
        assertEquals(Tone.GOOD, DashboardModel.change(-3.0, percent = false, goodWhenUp = false).tone)
        assertEquals("↓ −3 item", DashboardModel.change(-3.0, percent = false, goodWhenUp = false).text)
    }

    @Test fun `rupiah bahan tidak tampil bila server tidak mengirim kuncinya (tanpa menu reports)`() {
        materials = materialsJson(withMoney = false)
        val m = (vm().settled().materials as Load.Ready).data
        assertEquals(MoneyField.Hidden, m.stockValue)
        assertEquals(MoneyField.Hidden, m.wastedValue)
        assertEquals(2, m.dto.counts.negative)
    }

    @Test fun `rupiah bahan tampil bila dikirim, null jadi tak diketahui`() {
        val m = (vm().settled().materials as Load.Ready).data
        assertEquals(MoneyField.Shown("Rp 3.400.000"), m.stockValue)
        assertEquals(MoneyField.Unknown, m.wastedValue)
    }

    @Test fun `ganti periode memuat ulang wilayah periode, periode sama tidak`() {
        val vm = vm().also { it.settled() }
        vm.setPeriod(Period.D7)
        vm.setPeriod(Period.D7)
        vm.settled()
        assertEquals(listOf("today", "7d"), periodsAsked.toList())
        assertEquals(Period.D7, vm.state.value.period)
    }

    @Test fun `penyegaran senyap yang gagal mempertahankan angka lama`() {
        val vm = vm().also { it.settled() }
        failSummary = true
        vm.refresh()
        val s = vm.settled().summary
        assertTrue(s is Load.Ready && !s.refreshing)
    }

    @Test fun `muat pertama yang gagal menampilkan galat`() {
        failSummary = true
        assertTrue(vm().settled().summary is Load.Failed)
    }

    private fun materialsJson(withMoney: Boolean) = buildJsonObject {
        putJsonObject("counts") { put("negative", 2); put("out", 1); put("low", 3); put("at_risk_products", 1) }
        put("low_stock_products", 4)
        if (withMoney) { put("stock_value", "3400000.00"); put("wasted_value", null as String?) }
        putJsonObject("params") { put("window_days", 7); put("risk_days", 3) }
    }
}

private fun unused(): ManagerApi = java.lang.reflect.Proxy.newProxyInstance(
    ManagerApi::class.java.classLoader, arrayOf(ManagerApi::class.java),
) { _, m, _ -> error("ManagerApi.${m.name} tidak dipakai di tes ini") } as ManagerApi
