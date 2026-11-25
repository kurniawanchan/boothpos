package com.warkop.manager.ui.kitchen

import androidx.lifecycle.SavedStateHandle
import com.warkop.core.network.ApiClient
import com.warkop.manager.data.ManagerApi
import com.warkop.manager.data.dto.DataList
import com.warkop.manager.data.dto.ModifierGroupDto
import com.warkop.manager.data.dto.ModifierOptionDto
import com.warkop.manager.data.dto.OptionMaterialDto
import com.warkop.manager.data.dto.StationDto
import com.warkop.manager.data.dto.TicketDto
import com.warkop.manager.data.dto.TicketFeedDto
import com.warkop.manager.data.dto.TicketItemDto
import com.warkop.manager.domain.KitchenRules
import com.warkop.manager.domain.ModifierGroupForm
import com.warkop.manager.domain.OptionMaterial
import com.warkop.manager.domain.StationForm
import com.warkop.manager.domain.TableForm
import com.warkop.manager.domain.attachSummary
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.cancelAndJoin
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import kotlinx.coroutines.withTimeout
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import java.time.Instant

/** 094 / T089 — Dapur & Bar (tombol per status+izin, polling versi) dan aturan formulir Pengaturan Restoran. */
@OptIn(ExperimentalCoroutinesApi::class)
class KitchenViewModelTest {
    @Before fun setUp() = Dispatchers.setMain(UnconfinedTestDispatcher())
    @After fun tearDown() = Dispatchers.resetMain()

    @Test fun `tombol berikut sesuai status dan izin`() {
        val kitchen = setOf("kitchen"); val floor = setOf("floor")
        assertEquals("preparing", KitchenRules.nextAllowed("new", kitchen))
        assertEquals("ready", KitchenRules.nextAllowed("preparing", kitchen))
        assertEquals("served", KitchenRules.nextAllowed("ready", kitchen))
        assertNull(KitchenRules.nextAllowed("new", floor))       // pelayan tidak boleh memulai masak
        assertEquals("served", KitchenRules.nextAllowed("ready", floor))
        assertNull(KitchenRules.nextAllowed("served", kitchen)); assertNull(KitchenRules.nextAllowed("cancelled", kitchen))
    }

    @Test fun `terlambat bila 15 menit dan masih ada item baru atau dimasak`() {
        val now = Instant.parse("2026-11-25T12:20:00Z")
        val t = TicketDto(1, sentAt = "2026-11-25T12:04:00Z", items = listOf(TicketItemDto(1, status = "preparing")))
        assertTrue(KitchenRules.isLate(t, now))
        assertFalse(KitchenRules.isLate(t.copy(items = listOf(TicketItemDto(1, status = "ready"))), now))
        assertFalse(KitchenRules.isActive(t.copy(items = listOf(TicketItemDto(1, status = "served")))))
        assertEquals(5_000, KitchenRules.pollDelayMs(0)); assertEquals(60_000, KitchenRules.pollDelayMs(9))
    }

    @Test fun `polling memakai versi - respons unchanged tidak mengosongkan tiket, berhenti saat dibatalkan`() = runBlocking {
        val versions = java.util.Collections.synchronizedList(mutableListOf<String?>())
        val api = object : ManagerApi by unused() {
            override suspend fun stations(active: Int) = DataList(listOf(StationDto(7, "Dapur")))
            override suspend fun tickets(stationId: Long, version: String?): TicketFeedDto {
                versions += version
                return if (version == "v1") TicketFeedDto(unchanged = true, version = "v1") else TicketFeedDto(listOf(TicketDto(1, items = listOf(TicketItemDto(9)))), "v1")
            }
        }
        val vm = KitchenViewModel({ api }, SavedStateHandle())
        withTimeout(5_000) { vm.state.first { it.stationId == 7L } }
        assertTrue(vm.fetch(full = true))
        assertTrue(vm.fetch(full = false))
        assertEquals(listOf(null, "v1"), versions.toList())
        assertEquals(1, vm.state.value.tickets?.size) // unchanged mempertahankan tiket
        val job = launch(Dispatchers.Default) { vm.pollLoop() }
        withTimeout(5_000) { while (versions.size < 3) kotlinx.coroutines.delay(10) }
        job.cancelAndJoin() // layar ke belakang → putaran berhenti
        val n = versions.size
        kotlinx.coroutines.delay(300)
        assertEquals(n, versions.size)
    }

    @Test fun `meja - outlet_id hanya dikirim bila berubah, nama dipangkas, kursi 1-99`() {
        val f = TableForm(id = 3, name = " A1 ", seats = "4", outletId = 9, originalOutletId = 9)
        assertNull(f.toBody().outletId); assertEquals("A1", f.toBody().name)
        assertEquals(10L, f.copy(outletId = 10).toBody().outletId); assertTrue(f.copy(outletId = 10).moving)
        assertEquals(9L, TableForm(name = "B", outletId = 9).toBody().outletId) // buat: selalu
        assertTrue("seats" in f.copy(seats = "100").localErrors())
        assertNull(StationForm(id = 1, name = "Bar", outletId = 2, originalOutletId = 2).toBody().outletId)
    }

    @Test fun `grup modifier - kunci max_select dan materials hanya bila bermakna`() {
        val g = ModifierGroupDto(5, "Topping", selectionType = "multiple", minSelect = 0, maxSelect = null, options = listOf(
            ModifierOptionDto(11, "Keju", "3000.00", materials = listOf(OptionMaterialDto(1, "Keju", "gr", "20.0000"))),
            ModifierOptionDto(12, "Lama", isActive = false),
        ))
        val f = ModifierGroupForm.of(g)
        assertEquals(1, f.options.size) // opsi nonaktif tidak dimuat
        val json = f.toJson()
        assertEquals(JsonNull, json["max_select"]) // multi tanpa batas = null EKSPLISIT
        val opt = json["options"]!!.jsonArray[0].jsonObject
        assertFalse("materials" in opt) // bahan tidak berubah → kunci tidak ada
        val changed = f.copy(options = f.options.map { it.copy(materials = it.materials + OptionMaterial(2, "Saus", "ml", "5")) }).toJson()
        assertEquals(2, changed["options"]!!.jsonArray[0].jsonObject["materials"]!!.jsonArray.size)
        val single = ModifierGroupForm(name = "Suhu").copy(options = listOf(com.warkop.manager.domain.OptionRow(name = "Panas"))).toJson()
        assertFalse("max_select" in single); assertFalse("min_select" in single)
        assertTrue("options.0.materials.1.material_id" in f.copy(options = f.options.map { it.copy(materials = it.materials + it.materials) }).localErrors())
        assertTrue(ApiClient.json.encodeToString(kotlinx.serialization.json.JsonObject.serializer(), json).contains("\"display_order\":0"))
    }

    @Test fun `ringkasan Attach hanya bila 2 atau lebih dipilih, urutan sumber`() {
        val src = listOf(1L to "Kopi", 2L to "Teh", 3L to "Susu")
        assertNull(attachSummary(setOf(2L), src))
        assertEquals("Dipilih: Kopi, Susu", attachSummary(setOf(3L, 1L), src))
    }
}

private fun unused(): ManagerApi = java.lang.reflect.Proxy.newProxyInstance(
    ManagerApi::class.java.classLoader, arrayOf(ManagerApi::class.java),
) { _, m, _ -> error("ManagerApi.${m.name} tidak dipakai di tes ini") } as ManagerApi
