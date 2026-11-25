package com.warkop.manager.domain

import androidx.lifecycle.SavedStateHandle
import com.warkop.manager.data.dto.AdjustItem
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 094 / T060–T061 — aturan koreksi, transfer baru, dan terima transfer (cermin dialog web). */
class StockFormsTest {
    private fun row(id: Long, delta: String) = AdjustRow(id, "SKU$id", "Item $id", onHand = 10, delta = delta)

    @Test fun `koreksi menyaring baris nol, alasan dipangkas, outlet dari outlet terpilih`() {
        val f = AdjustForm(reason = "  opname  ", rows = listOf(row(1, "3"), row(2, "0"), row(3, ""), row(4, "-2")))
        assertTrue(f.canSubmit)
        val body = f.toBody(outletId = 9)
        assertEquals("opname", body.reason)
        assertEquals(9L, body.outletId)
        assertEquals(listOf(AdjustItem(1, 3), AdjustItem(4, -2)), body.items)
    }

    @Test fun `koreksi butuh alasan dan minimal satu selisih bukan nol`() {
        assertFalse(AdjustForm(reason = " ", rows = listOf(row(1, "3"))).canSubmit)
        assertFalse(AdjustForm(reason = "x", rows = listOf(row(1, "0"))).canSubmit)
        // Varian yang sama tidak ditambah dua kali.
        assertEquals(1, AdjustForm().add(row(1, "")).add(row(1, "")).rows.size)
    }

    @Test fun `transfer item harus bulat dan tidak melebihi tersedia, bahan boleh desimal`() {
        assertTrue(TransferRow("variant", 1, "A", "S", "5", "5").valid())
        assertFalse(TransferRow("variant", 1, "A", "S", "5", "1.5").valid())
        assertFalse(TransferRow("variant", 1, "A", "S", "5", "6").valid())
        assertFalse(TransferRow("variant", 1, "A", "S", "5", "0").valid())
        assertTrue(TransferRow("material", 2, "Gula", "GL", "2.500", "1.25").valid())
        assertFalse(TransferRow("material", 2, "Gula", "GL", "2.500", "2.6").valid())
    }

    @Test fun `transfer butuh tujuan berbeda dari asal, catatan kosong jadi null`() {
        val rows = listOf(TransferRow("variant", 1, "A", "S", "5", "2"))
        assertFalse(TransferForm(toOutletId = 3, rows = rows).canSubmit(fromOutletId = 3))
        assertFalse(TransferForm(toOutletId = null, rows = rows).canSubmit(fromOutletId = 3))
        assertFalse(TransferForm(toOutletId = 4, rows = emptyList()).canSubmit(fromOutletId = 3))
        val f = TransferForm(toOutletId = 4, note = "  ", rows = rows)
        assertTrue(f.canSubmit(3))
        assertNull(f.toBody(3).note)
    }

    @Test fun `client_ref bertahan saat proses dibuat ulang (kirim ulang tidak menggandakan transfer)`() {
        val handle = SavedStateHandle()
        val first = SavedForm(handle, "t", TransferForm.serializer()) { TransferForm() }
        val ref = first.current.clientRef
        first.update { it.copy(note = "x") }
        val restored = SavedForm(handle, "t", TransferForm.serializer()) { TransferForm() }
        assertEquals(ref, restored.current.clientRef)
    }

    @Test fun `terima kurang wajib alasan, lebih dari dikirim ditolak, alasan hanya untuk baris kurang`() {
        val full = ReceiveRow(1, "A", "variant", qtySent = "5", qty = "5")
        val short = ReceiveRow(2, "B", "variant", qtySent = "5", qty = "3")
        assertTrue(full.valid())
        assertFalse(short.valid())
        assertTrue(short.copy(reason = "pecah").valid())
        assertFalse(full.copy(qty = "6").valid())
        val body = ReceiveForm(listOf(full.copy(reason = "abaikan"), short.copy(reason = " pecah "))).toBody()
        assertNull(body.lines[0].reason)
        assertEquals("pecah", body.lines[1].reason)
        assertEquals(3.0, body.lines[1].qtyReceived, 0.0)
    }

    @Test fun `label tipe pergerakan mengikuti web, tipe tak dikenal tampil mentah`() {
        assertEquals("Penyesuaian", MovementTypes.label("adjustment"))
        assertEquals("Stok keluar (lama)", MovementTypes.label("preorder_handover"))
        assertEquals("transfer_out", MovementTypes.label("transfer_out"))
    }
}
