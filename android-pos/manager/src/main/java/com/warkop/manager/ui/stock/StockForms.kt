package com.warkop.manager.ui.stock

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.Button
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedCard
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.warkop.manager.domain.Format
import com.warkop.manager.domain.TransferForm
import com.warkop.manager.ui.components.Choice
import com.warkop.manager.ui.components.ConfirmDialog
import com.warkop.manager.ui.components.DecimalInput
import com.warkop.manager.ui.components.FormMessage
import com.warkop.manager.ui.components.IntInput
import com.warkop.manager.ui.components.LoadContent
import com.warkop.manager.ui.components.SelectInput
import com.warkop.manager.ui.components.TextInput
import com.warkop.manager.ui.theme.ManagerColors

@Composable
private fun Pane(title: String, onClose: () -> Unit, footer: @Composable () -> Unit, body: @Composable ColumnScope.() -> Unit) {
    Column(Modifier.fillMaxSize()) {
        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(4.dp)) {
            IconButton(onClick = onClose) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, "Kembali") }
            Text(title, style = MaterialTheme.typography.titleLarge)
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp), content = body)
        HorizontalDivider()
        Row(Modifier.fillMaxWidth().padding(12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.End), verticalAlignment = Alignment.CenterVertically) { footer() }
    }
}

@Composable
private fun <T> SearchBox(query: String, onQuery: (String) -> Unit, placeholder: String, results: List<T>, label: (T) -> Pair<String, String>, onPick: (T) -> Unit) {
    OutlinedTextField(query, onQuery, singleLine = true, leadingIcon = { Icon(Icons.Outlined.Search, null) }, placeholder = { Text(placeholder) }, modifier = Modifier.fillMaxWidth())
    if (results.isNotEmpty()) OutlinedCard(Modifier.fillMaxWidth()) {
        results.forEachIndexed { i, r ->
            if (i > 0) HorizontalDivider()
            val (title, sub) = label(r)
            Column(Modifier.fillMaxWidth().clickable { onPick(r) }.padding(12.dp)) {
                Text(title, style = MaterialTheme.typography.bodyMedium)
                Muted(sub)
            }
        }
    }
}

/** 094 / T060 — koreksi stok, cermin dialog koreksi `StockView.vue`. */
@Composable
fun StockAdjustPane(vm: StockAdjustViewModel, outletName: String, onClose: () -> Unit, onDone: () -> Unit) {
    val f by vm.form.value.collectAsState()
    val q by vm.query.collectAsState()
    val results by vm.results.collectAsState()
    val status by vm.status.collectAsState()
    Pane("Koreksi stok", onClose, footer = {
        Button(onClick = { vm.submit(onDone) }, enabled = !status.saving && f.canSubmit) { Text(if (status.saving) "Menyimpan…" else "Simpan koreksi") }
    }) {
        Muted("Outlet: $outletName. Isi selisih (positif menambah, negatif mengurangi). Baris bernilai nol tidak dikirim.")
        TextInput("Alasan", f.reason, vm::setReason, status.error("reason"), maxLength = 255, singleLine = false)
        SearchBox(q, vm::setQuery, "Cari SKU atau nama item", results, { "${it.label}" to "${it.sku} · stok ${it.onHand ?: "—"}" }, vm::add)
        status.errorUnder("items")?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
        f.rows.forEachIndexed { i, r ->
            OutlinedCard(Modifier.fillMaxWidth()) {
                Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Column(Modifier.weight(1f)) {
                        Text(r.label, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                        val after = r.delta.toIntOrNull()?.let { d -> r.onHand?.let { "→ ${it + d}" } }
                        Muted(listOfNotNull(r.sku, "stok ${r.onHand ?: "—"}", after).joinToString(" · "))
                        status.errorUnder("items.$i")?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
                    }
                    Box(Modifier.width(110.dp)) { IntInput("Selisih", r.delta, { v -> vm.setDelta(r.variantId, v) }, signed = true) }
                    IconButton(onClick = { vm.remove(r.variantId) }) { Icon(Icons.Outlined.Close, "Hapus baris") }
                }
            }
        }
        FormMessage(status.message)
    }
}

/** 094 / T061 — transfer baru, cermin `NewTransferDrawer.vue`. Asal = outlet terpilih. */
@Composable
fun NewTransferPane(vm: NewTransferViewModel, fromName: String, onClose: () -> Unit, onDone: () -> Unit) {
    val f by vm.form.value.collectAsState()
    val kind by vm.kind.collectAsState()
    val q by vm.query.collectAsState()
    val results by vm.results.collectAsState()
    val dests by vm.destinations.collectAsState()
    val status by vm.status.collectAsState()
    var sent by remember { mutableStateOf<String?>(null) }
    Pane("Transfer baru", onClose, footer = {
        Button(onClick = { vm.submit { number -> sent = number; onDone() } }, enabled = !status.saving && f.canSubmit(vm.fromOutletId)) { Text(if (status.saving) "Mengirim…" else "Kirim") }
    }) {
        TextInput("Dari outlet", fromName, {}, enabled = false, helper = "Outlet terpilih. Ganti outlet lewat menu akun untuk mengirim dari outlet lain.")
        SelectInput("Ke outlet", dests.map { Choice(it.id, it.name) }, f.toOutletId, vm::setTo, status.error("to_outlet_id"))
        TextInput("Catatan", f.note, vm::setNote, status.error("note"), maxLength = TransferForm.NOTE_MAX)
        SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
            listOf("variant" to "Item", "material" to "Bahan").forEachIndexed { i, (k, label) ->
                SegmentedButton(selected = kind == k, onClick = { vm.setKind(k) }, shape = SegmentedButtonDefaults.itemShape(i, 2)) { Text(label) }
            }
        }
        SearchBox(q, vm::setQuery, "Cari item / bahan", results, { it.label to "${it.sku} · tersedia ${Format.qty(it.available)}" }, vm::add)
        f.rows.forEachIndexed { i, r ->
            OutlinedCard(Modifier.fillMaxWidth()) {
                Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Column(Modifier.weight(1f)) {
                        Text(r.label, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                        Muted("${if (r.kind == "material") "Bahan" else "Item"} · tersedia ${Format.qty(r.available)}")
                        val err = status.errorUnder("lines.$i") ?: if (r.qty.isNotEmpty() && !r.valid()) (if (r.kind == "variant") "Jumlah bulat, > 0, tidak melebihi tersedia." else "Jumlah > 0, tidak melebihi tersedia.") else null
                        err?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
                    }
                    Box(Modifier.width(110.dp)) {
                        if (r.kind == "variant") IntInput("Jumlah", r.qty, { vm.setQty(i, it) }) else DecimalInput("Jumlah", r.qty, { vm.setQty(i, it) })
                    }
                    IconButton(onClick = { vm.remove(i) }) { Icon(Icons.Outlined.Close, "Hapus baris") }
                }
            }
        }
        FormMessage(status.message ?: status.errorUnder("lines"))
    }
}

/** 094 / T061 — detail transfer + terima + batal (cermin `TransferDetail.vue`, `ReceiveTransferDialog.vue`). */
@Composable
fun TransferDetailPane(vm: TransferDetailViewModel, outletName: String, onClose: () -> Unit, onChanged: () -> Unit) {
    val state by vm.detail.collectAsState()
    val recv by vm.receive.value.collectAsState()
    val status by vm.status.collectAsState()
    var confirmCancel by remember { mutableStateOf(false) }
    LoadContent(state, onRetry = vm::load) { d ->
        val canReceive = d.can.receive && d.status == "in_transit"
        Pane("Transfer ${d.number}", onClose, footer = {
            if (d.can.cancel && d.status == "in_transit") TextButton(onClick = { confirmCancel = true }, enabled = !status.saving) { Text("Batalkan", color = MaterialTheme.colorScheme.error) }
            if (canReceive) Button(onClick = { vm.submitReceive(onChanged) }, enabled = !status.saving && recv.canSubmit) { Text(if (status.saving) "Menyimpan…" else "Terima") }
        }) {
            Text("${d.fromOutlet?.name ?: "—"} → ${d.toOutlet?.name ?: "—"}", style = MaterialTheme.typography.titleMedium)
            Muted(listOfNotNull(TRANSFER_STATUS_LABELS[d.status] ?: d.status, if (d.hasDiscrepancy) "Ada selisih" else null).joinToString(" · "))
            d.note?.takeIf { it.isNotBlank() }?.let { Muted("Catatan: $it") }
            d.steps.forEach { s -> Muted("${mapOf("dispatched" to "Dikirim", "received" to "Diterima", "cancelled" to "Dibatalkan")[s.step] ?: s.step}: ${s.by ?: "—"} · ${dateTime(s.at) ?: ""}") }
            if (d.status == "in_transit" && !d.can.receive && d.toOutlet != null) {
                Muted("Untuk menerima, pilih outlet ${d.toOutlet.name} di menu akun (saat ini: $outletName) dan pastikan peran Anda punya izin terima transfer.")
            }
            if (canReceive) Muted("Isi jumlah yang benar-benar tiba. Kurang dari yang dikirim wajib beralasan.")
            d.lines.forEach { l ->
                val row = recv.rows.firstOrNull { it.lineId == l.id }
                OutlinedCard(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(l.item?.name ?: "#${l.id}", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                        Muted(listOfNotNull(l.item?.sku, "dikirim ${Format.qty(l.qtySent)}", l.qtyReceived?.let { "diterima ${Format.qty(it)}" }).joinToString(" · "))
                        l.discrepancyReason?.let { Muted("Kurang ${Format.qty(l.shortfall)}: $it") }
                        if ("not_carried_at_destination" in l.warnings) Text("Tidak dijual di outlet tujuan — tambahkan ke menu di sana agar bisa dijual.", color = ManagerColors.warning.fg, style = MaterialTheme.typography.bodySmall)
                        if (canReceive && row != null) {
                            val idx = recv.rows.indexOf(row)
                            if (l.kind == "variant") IntInput("Jumlah diterima", row.qty, { vm.setQty(l.id, it) }, status.errorUnder("lines.$idx.qty_received"))
                            else DecimalInput("Jumlah diterima", row.qty, { vm.setQty(l.id, it) }, status.errorUnder("lines.$idx.qty_received"))
                            if (row.short) TextInput("Alasan selisih", row.reason, { vm.setReason(l.id, it) }, status.errorUnder("lines.$idx.reason") ?: if (row.reason.isBlank()) "Wajib karena jumlah kurang dari yang dikirim." else null, maxLength = 255)
                        }
                    }
                }
            }
            FormMessage(status.message)
        }
    }
    if (confirmCancel && state is com.warkop.manager.ui.components.Load.Ready) {
        val number = (state as com.warkop.manager.ui.components.Load.Ready).data.number
        ConfirmDialog("Batalkan transfer?", "Barang transfer $number kembali ke outlet asal.", "Batalkan transfer", onConfirm = { confirmCancel = false; vm.cancel(onChanged) }, onDismiss = { confirmCancel = false }, destructive = true)
    }
}
