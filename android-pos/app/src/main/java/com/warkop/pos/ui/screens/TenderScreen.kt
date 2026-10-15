package com.warkop.pos.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.*
import com.warkop.pos.state.Conn
import com.warkop.pos.state.Dest
import com.warkop.pos.state.PosStore
import com.warkop.pos.state.Sheet
import com.warkop.pos.theme.pos
import com.warkop.pos.ui.*

/** S11 — layar bayar: sisa, riwayat tender, metode, uang cepat, keypad (desain 13/14/15/16, 32 phone). */
@Composable
fun TenderScreen(store: PosStore) {
    val o = store.active ?: return
    var method by remember { mutableStateOf(TenderMethod.CASH) }
    var amount by remember { mutableStateOf("") }
    val value = amount.toIntOrNull() ?: 0
    val compact = windowSize() == Size.COMPACT
    val pending = o.hasPendingQris

    val left: @Composable (Modifier) -> Unit = { m ->
        Surface(m, shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
            Column(Modifier.padding(20.dp).then(if (compact) Modifier else Modifier.verticalScroll(rememberScrollState()))) {
                OutlinedPill("Kembali ke order", { store.go(Dest.EDITOR) }, icon = Icons.Filled.ArrowBack)
                Text("${o.label} · Total ${rp(o.total)}", Modifier.padding(top = 14.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
                Text("Sisa", Modifier.padding(top = 14.dp), fontSize = 16.sp)
                Text(rp(o.remaining), fontSize = if (compact) 44.sp else 64.sp, fontWeight = FontWeight.Light)
                Text("Terbayar ${rp(o.paid)}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                Spacer(Modifier.height(14.dp))
                o.tenders.forEach { t ->
                    Surface(Modifier.fillMaxWidth().padding(vertical = 3.dp), shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.surfaceContainerLowest) {
                        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(t.method.label, fontWeight = FontWeight.SemiBold, maxLines = 1, softWrap = false); t.ref?.let { Text(it, fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                                if (t.status == TenderStatus.PENDING && store.conn == Conn.ONLINE) FilledTonalButton({ store.show(Sheet.QrisVerify(t.id)) }, Modifier.padding(top = 6.dp), colors = ButtonDefaults.filledTonalButtonColors(containerColor = MaterialTheme.colorScheme.primaryContainer)) { Text("Verifikasi", fontWeight = FontWeight.SemiBold) }
                            }
                            when (t.status) {
                                TenderStatus.OK -> Pill("Lunas", MaterialTheme.pos.okContainer, MaterialTheme.pos.onOkContainer)
                                TenderStatus.PENDING -> Pill("Menunggu verifikasi", MaterialTheme.pos.warnContainer, MaterialTheme.pos.onWarnContainer)
                                TenderStatus.FAIL -> Pill("Gagal", MaterialTheme.colorScheme.errorContainer, MaterialTheme.colorScheme.onErrorContainer)
                            }
                            Text(num(t.amount), Modifier.padding(start = 10.dp), fontWeight = FontWeight.SemiBold)
                        }
                    }
                }
                Spacer(Modifier.height(16.dp))
                Spacer(Modifier.height(if (compact) 0.dp else 260.dp))
                Surface(shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.surfaceContainerLowest) {
                    Row(Modifier.padding(12.dp)) { Icon(Icons.Filled.Info, null, Modifier.size(20.dp)); Spacer(Modifier.width(10.dp)); Text("Tunai memberi kembalian. EDC dan QRIS tidak boleh melebihi sisa tagihan.", fontSize = 14.sp) }
                }
            }
        }
    }

    val right: @Composable (Modifier) -> Unit = { m ->
        Column(if (compact) m else m.verticalScroll(rememberScrollState())) {
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                MethodTile(Icons.Filled.Payments, "Tunai", method == TenderMethod.CASH, true, Modifier.weight(1f)) { method = TenderMethod.CASH }
                MethodTile(Icons.Filled.CreditCard, "EDC BCA", method == TenderMethod.EDC, !pending, Modifier.weight(1f)) { method = TenderMethod.EDC; store.show(Sheet.Edc) }
                MethodTile(Icons.Filled.QrCode2, "QRIS GoPay", method == TenderMethod.QRIS, !pending && store.conn == Conn.ONLINE, Modifier.weight(1f)) { method = TenderMethod.QRIS; store.show(Sheet.QrisRecord) }
            }
            Spacer(Modifier.height(10.dp))
            if (method == TenderMethod.CASH) {
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    quickCash(o.remaining).forEachIndexed { i, v ->
                        Surface(modifier = Modifier.weight(1f), shape = RoundedCornerShape(16.dp), color = MaterialTheme.colorScheme.primaryContainer, contentColor = MaterialTheme.colorScheme.onPrimaryContainer, onClick = { store.payCash(v) }) {
                            Column(Modifier.padding(12.dp)) { Text(if (i == 0) "Uang pas" else "Tunai", fontSize = 12.sp); Text(num(v), fontSize = 18.sp, fontWeight = FontWeight.Bold) }
                        }
                    }
                    repeat(4 - quickCash(o.remaining).size) { Spacer(Modifier.weight(1f)) }
                }
                Spacer(Modifier.height(10.dp))
                Surface(Modifier.fillMaxWidth().height(60.dp), shape = RoundedCornerShape(16.dp), color = MaterialTheme.colorScheme.surfaceContainerHigh) {
                    Row(Modifier.padding(horizontal = 16.dp), verticalAlignment = Alignment.CenterVertically) { Text("Nominal lain", Modifier.weight(1f), fontSize = 14.sp); Text(rp(value), fontSize = 28.sp) }
                }
                Spacer(Modifier.height(10.dp))
                Keypad({ d -> if (amount.length < 9) amount = (amount + d).trimStart('0') }, { amount = amount.dropLast(1) }, Modifier.fillMaxWidth(), extraKey = "000", keyHeight = if (compact) 48.dp else 60.dp, shape = RoundedCornerShape(16.dp))
                Spacer(Modifier.height(10.dp))
                PrimaryPill("Terima tunai", { store.payCash(value); amount = "" }, Modifier.fillMaxWidth(), enabled = value > 0, icon = Icons.Filled.Payments, trailing = rp(value))
            }
            if (pending) Surface(Modifier.fillMaxWidth().padding(top = 10.dp), shape = RoundedCornerShape(14.dp), color = MaterialTheme.pos.warnContainer, contentColor = MaterialTheme.pos.onWarnContainer) {
                Text("QRIS menunggu verifikasi memblokir pembayaran non-tunai lain dan penutupan shift.", Modifier.padding(16.dp), fontSize = 16.sp, fontWeight = FontWeight.Medium)
            }
        }
    }

    PosShell(store, title = "Pembayaran") {
        if (compact) Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) { left(Modifier.fillMaxWidth()); Spacer(Modifier.height(10.dp)); right(Modifier.fillMaxWidth()) }
        else Row(Modifier.fillMaxSize()) { left(Modifier.weight(0.85f).fillMaxHeight().padding(end = 16.dp)); right(Modifier.weight(1.15f).fillMaxHeight()) }
    }
}

@Composable
private fun MethodTile(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, selected: Boolean, enabled: Boolean, modifier: Modifier, onClick: () -> Unit) {
    Surface(modifier = modifier.height(96.dp), shape = RoundedCornerShape(20.dp), enabled = enabled, onClick = onClick,
        color = if (selected) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surfaceContainerLow,
        border = BorderStroke(if (selected) 2.dp else 1.dp, if (selected) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.outline),
        contentColor = if (enabled) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.onSurface.copy(alpha = 0.38f)) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.SpaceBetween) { Icon(icon, null); Text(label, fontWeight = FontWeight.SemiBold, fontSize = 16.sp) }
    }
}

/** S14 — pembayaran selesai: kembalian, status cetak/sinkron, nomor antrean (desain 17, 33 phone). */
@Composable
fun DoneScreen(store: PosStore) {
    val o = store.active ?: return
    val change = o.tenders.sumOf { (it.received ?: it.amount) - it.amount }
    val compact = windowSize() == Size.COMPACT
    PosShell(store, title = "Pembayaran selesai") {
        val main: @Composable (Modifier) -> Unit = { m ->
            Surface(m, shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
                Column(Modifier.padding(24.dp)) {
                    Box(Modifier.size(56.dp), contentAlignment = Alignment.Center) { Surface(shape = RoundedCornerShape(50), color = MaterialTheme.pos.okContainer, modifier = Modifier.fillMaxSize()) { Icon(Icons.Filled.Check, null, Modifier.padding(14.dp), tint = MaterialTheme.pos.onOkContainer) } }
                    Spacer(Modifier.height(10.dp))
                    Pill("Lunas · ${o.receiptNo}", MaterialTheme.pos.okContainer, MaterialTheme.pos.onOkContainer)
                    Text(if (change > 0) "Kembalian" else "Total dibayar", Modifier.padding(top = 14.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Text(rp(if (change > 0) change else o.total), fontSize = 56.sp, fontWeight = FontWeight.Light)
                    Text("Diterima ${rp(o.tenders.sumOf { it.received ?: it.amount })}${if (o.tenders.all { it.method == TenderMethod.CASH }) " tunai" else ""} · Total ${rp(o.total)}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Spacer(Modifier.height(14.dp))
                    StatusLine(Icons.Filled.Print, "Struk tercetak di printer Kasir")
                    StatusLine(Icons.Filled.CloudDone, if (store.conn == Conn.ONLINE) "Tersinkron ke server" else "Tersimpan di perangkat · dikirim saat online")
                    Spacer(Modifier.weight(1f, fill = !compact)); Spacer(Modifier.height(14.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        PrimaryPill(if (o.kind == OrderKind.COUNTER) "Order counter baru" else "Kembali ke meja", { if (o.kind == OrderKind.COUNTER) store.newCounter() else store.go(Dest.TABLES) }, icon = Icons.Filled.Add)
                        OutlinedPill("Lihat struk", { store.show(Sheet.Receipt(o.id)) }, icon = Icons.Filled.Receipt)
                    }
                }
            }
        }
        if (o.queue != null) {
            val queue: @Composable (Modifier) -> Unit = { m ->
                Surface(m, shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.primaryContainer, contentColor = MaterialTheme.colorScheme.onPrimaryContainer) {
                    Column(Modifier.padding(28.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) { Icon(Icons.Filled.Campaign, null); Spacer(Modifier.width(10.dp)); Text("Panggil pelanggan", fontWeight = FontWeight.SemiBold, fontSize = 18.sp) }
                        Spacer(Modifier.weight(1f))
                        Text(o.queue, fontSize = if (compact) 88.sp else 168.sp, fontWeight = FontWeight.Light, maxLines = 1, softWrap = false)
                        Spacer(Modifier.weight(1f))
                        Text("Nomor antrean juga tercetak di struk dan KOT.", fontSize = 15.sp)
                    }
                }
            }
            if (compact) Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) { main(Modifier.fillMaxWidth()); Spacer(Modifier.height(10.dp)); queue(Modifier.fillMaxWidth().height(200.dp)) }
            else Row(Modifier.fillMaxSize()) { main(Modifier.weight(1.2f).fillMaxHeight().padding(end = 16.dp)); queue(Modifier.weight(1f).fillMaxHeight()) }
        } else main(Modifier.fillMaxSize())
    }
}

@Composable
private fun StatusLine(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String) {
    Row(Modifier.padding(vertical = 3.dp), verticalAlignment = Alignment.CenterVertically) { Icon(icon, null, Modifier.size(20.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant); Spacer(Modifier.width(10.dp)); Text(text) }
}
