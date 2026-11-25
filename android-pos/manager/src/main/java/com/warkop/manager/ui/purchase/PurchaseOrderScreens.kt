package com.warkop.manager.ui.purchase

import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedCard
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.createSavedStateHandle
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.PurchaseRepository
import com.warkop.manager.data.dto.PurchaseOrderDto
import com.warkop.manager.domain.Format
import com.warkop.manager.domain.PoLine
import com.warkop.manager.domain.PoStatus
import com.warkop.manager.ui.components.Choice
import com.warkop.manager.ui.components.ConfirmDialog
import com.warkop.manager.ui.components.DecimalInput
import com.warkop.manager.ui.components.FormMessage
import com.warkop.manager.ui.components.ListDetail
import com.warkop.manager.ui.components.LoadContent
import com.warkop.manager.ui.components.LoadingBox
import com.warkop.manager.ui.components.MessageBox
import com.warkop.manager.ui.components.MoneyInput
import com.warkop.manager.ui.components.PagedListContent
import com.warkop.manager.ui.components.SelectInput
import com.warkop.manager.ui.components.TextInput
import com.warkop.manager.ui.container
import com.warkop.manager.ui.managerViewModel
import com.warkop.manager.ui.products.Muted
import com.warkop.manager.ui.products.PaneHeader
import com.warkop.manager.ui.products.Section
import com.warkop.manager.ui.theme.ManagerColors

/** 094 / T082–T083 — PO: daftar per status; detail (transisi, bayar tunai); formulir. Kunci: "<id>", "<id>/edit", "new". */
@Composable
fun PurchaseOrdersScreen(onSessionError: (ApiError) -> Boolean) {
    val outlet = container().session.selectedOutlet() ?: run { MessageBox("Pilih outlet dulu"); return }
    val list = managerViewModel { c -> PurchaseOrdersViewModel(PurchaseRepository(c::api), createSavedStateHandle(), onSessionError) }
    ListDetail(
        list = { open ->
            val status by list.status.collectAsState(); val state by list.list.collectAsState()
            Box(Modifier.fillMaxSize()) {
                Column(Modifier.fillMaxSize()) {
                    Row(Modifier.horizontalScroll(rememberScrollState()).padding(12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        FilterChip(status == null, { list.setStatus(null) }, { Text("Semua") })
                        PoStatus.LABELS.forEach { (k, l) -> FilterChip(status == k, { list.setStatus(k) }, { Text(l) }) }
                    }
                    PagedListContent(state, list::reload, list::loadMore, "Belum ada purchase order.") { rows -> items(rows, key = { it.id }) { PoRow(it) { open(it.id.toString()) }; HorizontalDivider() } }
                }
                ExtendedFloatingActionButton(onClick = { open("new") }, icon = { Icon(Icons.Outlined.Add, null) }, text = { Text("PO Baru") }, modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp))
            }
        },
        detail = { key, close, open ->
            if (key == "new" || key.endsWith("/edit")) {
                val id = key.removeSuffix("/edit").toLongOrNull()
                val vm = managerViewModel(key = "po-form-$key") { c -> PurchaseOrderFormViewModel(PurchaseRepository(c::api), createSavedStateHandle(), id, outlet.id, onSessionError) }
                PoFormPane(vm, outlet.name, close, onSaved = { savedId -> list.reload(); open(savedId.toString()) })
            } else {
                val id = key.toLong()
                val vm = managerViewModel(key = "po-$id") { c -> PurchaseOrderDetailViewModel(PurchaseRepository(c::api), id, onSessionError) }
                PoDetailPane(vm, close, onEdit = { open("$id/edit") }, onChanged = list::reload, onDeleted = { list.reload(); close() })
            }
        },
    )
}

private fun statusColor(s: String) = when (s) { "ordered" -> ManagerColors.warning.fg; "paid" -> ManagerColors.success.fg; "cancelled" -> ManagerColors.danger.fg; else -> null }

@Composable
private fun PoRow(po: PurchaseOrderDto, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(po.poNumber, fontWeight = FontWeight.SemiBold)
            Muted("${po.vendorName ?: "—"} · ${po.outletName ?: "Belum ada outlet"}")
        }
        Column(horizontalAlignment = Alignment.End) {
            Text(Format.rupiah(po.totalAmount), fontWeight = FontWeight.SemiBold)
            Text(PoStatus.LABELS[po.status] ?: po.status, style = MaterialTheme.typography.labelMedium, color = statusColor(po.status) ?: MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun PoFormPane(vm: PurchaseOrderFormViewModel, outletName: String, onClose: () -> Unit, onSaved: (Long) -> Unit) {
    val f by vm.form.value.collectAsState(); val s by vm.status.collectAsState(); val loading by vm.loading.collectAsState()
    val vendors by vm.vendors.collectAsState(); val materials by vm.materials.collectAsState()
    Column(Modifier.fillMaxSize()) {
        PaneHeader(if (f.id == null) "PO Baru" else "Edit Purchase Order", onClose)
        if (loading) { LoadingBox(); return@Column }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            SelectInput("Vendor", vendors.map { Choice(it.id, it.name) }, f.vendorId, { v -> vm.edit { it.copy(vendorId = v) } }, s.error("vendor_id"))
            TextInput("Outlet", outletName, {}, s.error("outlet_id"), enabled = false, helper = "Outlet yang dibelikan = outlet terpilih. Hanya BOM milik outlet ini yang boleh memakai baris-baris ini.")
            TextInput("Catatan", f.notes, { v -> vm.edit { it.copy(notes = v) } }, s.error("notes"), singleLine = false)
            Text("Item Pembelian", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
            if (f.linesLocked) Muted("Item hanya bisa diubah selama status masih Draft.")
            s.error("items")?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
            f.lines.forEachIndexed { i, l -> PoLineCard(vm, i, l, f.linesLocked, materials.map { Choice(it.id, "${it.name} (${it.unit})") }, s) }
            if (!f.linesLocked) OutlinedButton(onClick = { vm.edit { it.copy(lines = it.lines + PoLine()) } }) { Text("Tambah Baris") }
            FormMessage(s.message)
        }
        FooterSave(s.saving, onSave = { vm.save(onSaved) })
    }
}

@Composable
private fun PoLineCard(vm: PurchaseOrderFormViewModel, i: Int, l: PoLine, locked: Boolean, materials: List<Choice<Long>>, s: com.warkop.manager.domain.FormStatus) {
    fun upd(f: (PoLine) -> PoLine) = vm.edit { it.copy(lines = it.lines.map { x -> if (x.key == l.key) f(x) else x }) }
    OutlinedCard(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                SingleChoiceSegmentedButtonRow(Modifier.weight(1f)) {
                    listOf("material" to "Bahan Baku", "service" to "Jasa").forEachIndexed { k, (key, label) ->
                        SegmentedButton(selected = l.type == key, onClick = { if (!locked) upd { it.copy(type = key) } }, enabled = !locked, shape = SegmentedButtonDefaults.itemShape(k, 2)) { Text(label) }
                    }
                }
                if (!locked) IconButton(onClick = { vm.edit { it.copy(lines = it.lines - l) } }) { Icon(Icons.Outlined.Close, "Hapus baris") }
            }
            if (l.type == "material") {
                val choices = if (l.materialId != null && materials.none { it.value == l.materialId }) materials + Choice(l.materialId, l.materialName ?: "#${l.materialId}") else materials
                SelectInput("Bahan Baku", choices, l.materialId, { v -> upd { it.copy(materialId = v, materialName = choices.firstOrNull { c -> c.value == v }?.label) } }, s.error("items.$i.material_id"), enabled = !locked)
            } else TextInput("Deskripsi", l.description, { v -> upd { it.copy(description = v) } }, s.error("items.$i.description"), maxLength = 255, enabled = !locked)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Box(Modifier.weight(1f)) { DecimalInput("Jumlah", l.qty, { v -> upd { it.copy(qty = v) } }, s.error("items.$i.qty"), enabled = !locked) }
                Box(Modifier.weight(1f)) { MoneyInput("Harga Satuan", l.unitPrice, { v -> upd { it.copy(unitPrice = v) } }, s.error("items.$i.unit_price"), enabled = !locked) }
            }
        }
    }
}

@Composable
private fun PoDetailPane(vm: PurchaseOrderDetailViewModel, onClose: () -> Unit, onEdit: () -> Unit, onChanged: () -> Unit, onDeleted: () -> Unit) {
    val state by vm.po.collectAsState(); val s by vm.status.collectAsState()
    var cancelAsk by remember { mutableStateOf(false) }
    var cancelReason by remember { mutableStateOf("") }
    var deleteAsk by remember { mutableStateOf(false) }
    var payAmount by remember { mutableStateOf("") }
    var payNotes by remember { mutableStateOf("") }
    LaunchedEffect(Unit) { vm.load() }
    LoadContent(state, onRetry = vm::load) { po ->
        Column(Modifier.fillMaxSize()) {
            PaneHeader(po.poNumber, onClose) { TextButton(onClick = onEdit) { Text("Edit") } }
            Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(PoStatus.LABELS[po.status] ?: po.status, fontWeight = FontWeight.Bold, color = statusColor(po.status) ?: MaterialTheme.colorScheme.onSurface)
                Muted("${po.vendorName ?: "—"} · ${po.outletName ?: "Belum ada outlet"}")
                po.cancelReason?.takeIf { it.isNotBlank() }?.let { Muted("Alasan batal: $it") }
                Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    PoStatus.next(po.status).forEach { to ->
                        val label = PoStatus.ACTIONS[to] ?: to
                        if (to == "cancelled") OutlinedButton(onClick = { cancelAsk = true }, enabled = !s.saving) { Text(label, color = MaterialTheme.colorScheme.error) }
                        else Button(onClick = { vm.transition(to, null, onChanged) }, enabled = !s.saving) { Text(label) }
                    }
                    if (po.status == "draft") TextButton(onClick = { deleteAsk = true }, enabled = !s.saving) { Text("Hapus", color = MaterialTheme.colorScheme.error) }
                }
                FormMessage(s.message)
                Section("Item Pembelian") {
                    po.items.forEach { it -> Muted("${Format.qty(it.qty)}× ${it.materialName ?: it.description ?: "—"} — ${Format.rupiah(it.lineTotal)}${if (it.usedInBomCount > 0) " · dipakai di ${it.usedInBomCount} baris BOM" else ""}") }
                    HorizontalDivider()
                    Text("Total ${Format.rupiah(po.totalAmount)} · Dibayar ${Format.rupiah(po.paidAmount)}", fontWeight = FontWeight.SemiBold)
                }
                Section("Riwayat Pembayaran") {
                    if (po.payments.isEmpty()) Muted("Belum ada pembayaran.")
                    po.payments.forEach { p -> Muted("${if (p.method == "cash") "Tunai" else p.method} · ${Format.rupiah(p.amount)}${p.notes?.let { " · $it" } ?: ""}") }
                    if (PoStatus.canPay(po.status)) {
                        Muted("Metode: Tunai. (Transfer/QRIS butuh kanal dan bukti pembayaran yang belum tersedia di aplikasi.)")
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                            Box(Modifier.width(160.dp)) { MoneyInput("Nominal", payAmount, { payAmount = it }, s.error("amount")) }
                            Box(Modifier.weight(1f)) { TextInput("Catatan (opsional)", payNotes, { payNotes = it }, s.error("notes"), maxLength = 1000) }
                        }
                        OutlinedButton(onClick = { vm.pay(payAmount, payNotes) { payAmount = ""; payNotes = ""; onChanged() } }, enabled = !s.saving) { Text("Catat Pembayaran") }
                    }
                }
            }
        }
    }
    if (cancelAsk) AlertDialog(
        onDismissRequest = { cancelAsk = false },
        title = { Text("Batalkan purchase order?") },
        text = { TextInput("Alasan pembatalan (opsional)", cancelReason, { cancelReason = it }, maxLength = 255) },
        confirmButton = { TextButton(onClick = { cancelAsk = false; vm.transition("cancelled", cancelReason, onChanged); cancelReason = "" }) { Text("Batalkan PO", color = MaterialTheme.colorScheme.error) } },
        dismissButton = { TextButton(onClick = { cancelAsk = false }) { Text("Kembali") } },
    )
    if (deleteAsk) ConfirmDialog("Hapus Purchase Order", "Yakin ingin menghapus purchase order ini?", "Hapus", { deleteAsk = false; vm.delete(onDeleted) }, { deleteAsk = false }, destructive = true)
}
