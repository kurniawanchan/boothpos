package com.warkop.pos.ui.screens

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.SelfServiceOrderView
import com.warkop.pos.state.Load
import com.warkop.pos.state.PosStore
import com.warkop.pos.theme.pos
import com.warkop.pos.ui.*

/**
 * 090 — isi tab "Mandiri" di Pesanan: antrean pesanan yang dikirim pelanggan lewat QR meja (menunggu verifikasi atau sudah
 * terverifikasi, tertua dulu). Mengetuk baris membuka rincian + tindakan: Verifikasi, Tolak, Kirim ke dapur. Foto bukti bayar
 * hanya dilihat di web (Penjualan → Self-Service); tablet meminta nomor referensi pembayaran yang dicocokkan staf.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SelfServiceOrdersPane(store: PosStore, modifier: Modifier = Modifier) {
    LaunchedEffect(Unit) { store.loadSelfServiceQueue() }
    val state = store.selfServiceQueue
    Box(modifier.fillMaxWidth()) {
        when (state) {
            is Load.Failed -> ErrorState(friendlyLoadError(state.error), { store.loadSelfServiceQueue() }, Modifier.fillMaxSize(), offline = isOfflineError(state.error))
            is Load.Ready -> {
                val items = state.value.items
                if (items.isEmpty()) Text("Belum ada pesanan mandiri yang menunggu.", Modifier.align(Alignment.Center), color = MaterialTheme.colorScheme.onSurfaceVariant)
                else LazyColumn(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(items, key = { it.id }) { o -> SelfServiceRow(o) { store.openSelfServiceOrder(o) } }
                }
            }
            else -> CircularProgressIndicator(Modifier.align(Alignment.Center))
        }
    }
    store.selfServiceOpen?.let { open ->
        ModalBottomSheet(onDismissRequest = { store.closeSelfServiceOrder() }) {
            SelfServiceDetail(store, open, Modifier.padding(horizontal = 20.dp).padding(bottom = 24.dp))
        }
    }
}

@Composable
private fun SelfServiceRow(o: SelfServiceOrderView, onClick: () -> Unit) {
    Surface(modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(18.dp), color = MaterialTheme.colorScheme.surfaceContainerLow, onClick = onClick) {
        Row(Modifier.padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Meja ${o.tableName} · ${o.reference}", fontWeight = FontWeight.SemiBold, fontSize = 16.sp)
                Text("${o.itemCount} item · ${if (o.takeaway) "Take Away" else "Dine-In"}${o.customerName?.let { " · $it" }.orEmpty()}", fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            if (o.awaiting) Pill("Verifikasi", MaterialTheme.pos.warnContainer, MaterialTheme.pos.onWarnContainer)
            else Pill(o.statusLabel, MaterialTheme.colorScheme.primaryContainer, MaterialTheme.colorScheme.onPrimaryContainer)
            Text(o.totalLabel, Modifier.padding(start = 10.dp), fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
private fun SelfServiceDetail(store: PosStore, o: SelfServiceOrderView, modifier: Modifier = Modifier) {
    var verifying by remember { mutableStateOf(false) }
    var rejecting by remember { mutableStateOf(false) }
    Column(modifier.verticalScroll(rememberScrollState())) {
        Text("Meja ${o.tableName}", fontSize = 26.sp)
        Text("${o.reference} · ${o.statusLabel} · ${if (o.takeaway) "Take Away" else "Dine-In"}", Modifier.padding(top = 4.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
        o.customerName?.let { Text("Pelanggan: $it", Modifier.padding(top = 2.dp), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        Spacer(Modifier.height(10.dp))
        if (!o.detailLoaded) { LinearProgressIndicator(Modifier.fillMaxWidth()); Text("Memuat rincian…", Modifier.padding(top = 6.dp), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        o.lines.forEach { l ->
            Row(Modifier.fillMaxWidth().padding(top = 4.dp)) { Text("${l.qty}× ${l.name}", Modifier.weight(1f)); Text(l.totalLabel) }
            l.modifiers.forEach { Text(it, Modifier.padding(start = 16.dp), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            l.note?.let { Text("Catatan: $it", Modifier.padding(start = 16.dp), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        }
        HorizontalDivider(Modifier.padding(vertical = 8.dp))
        MoneyRow("Total (QRIS)", o.totalLabel, big = true)
        o.paymentReference?.let { Text("Referensi pembayaran: $it", Modifier.padding(top = 4.dp), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        o.rejectReason?.let { Text("Alasan ditolak: $it", Modifier.padding(top = 4.dp), fontSize = 13.sp, color = MaterialTheme.colorScheme.error) }
        o.posOrderNumber?.let { Text("Nomor pesanan: $it", Modifier.padding(top = 4.dp), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        if (o.awaiting) Text("Foto bukti pembayaran dilihat di web (Penjualan → Self-Service).", Modifier.padding(top = 8.dp), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Spacer(Modifier.height(16.dp))
        val enabled = o.detailLoaded && !store.selfServiceBusy
        if (o.awaiting) Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedPill("Tolak", { rejecting = true }, Modifier.weight(1f), enabled = enabled, danger = true)
            Button({ verifying = true }, Modifier.weight(1f), enabled = enabled) { Text("Verifikasi pembayaran") }
        }
        if (o.verified) Button({ store.dispatchSelfService(o.id) }, Modifier.fillMaxWidth(), enabled = enabled) { Text("Kirim ke dapur") }
    }
    if (verifying) ReasonDialog("Verifikasi pembayaran", "Nomor referensi pembayaran (min. 4 karakter)", "Verifikasi", { verifying = false }) { store.verifySelfService(o.id, it); verifying = false }
    if (rejecting) ReasonDialog("Tolak pesanan", "Alasan penolakan", "Tolak", { rejecting = false }) { store.rejectSelfService(o.id, it); rejecting = false }
}

@Composable
private fun ReasonDialog(title: String, label: String, confirm: String, onDismiss: () -> Unit, onConfirm: (String) -> Unit) {
    var text by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = { OutlinedTextField(text, { text = it }, label = { Text(label) }, singleLine = true, modifier = Modifier.fillMaxWidth()) },
        confirmButton = { TextButton({ onConfirm(text) }, enabled = text.trim().length >= 4) { Text(confirm) } },
        dismissButton = { TextButton(onDismiss) { Text("Batal") } },
    )
}
