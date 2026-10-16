package com.warkop.pos.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.*
import com.warkop.pos.state.Conn
import com.warkop.pos.state.Dest
import com.warkop.pos.state.Load
import com.warkop.pos.state.PosStore
import com.warkop.pos.state.Sheet
import com.warkop.pos.theme.pos
import com.warkop.pos.ui.*

@Composable
private fun Cell(label: String, value: String, note: String, modifier: Modifier) {
    Surface(modifier, shape = RoundedCornerShape(20.dp), color = MaterialTheme.colorScheme.surfaceContainerLow, border = androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant)) {
        Column(Modifier.padding(horizontal = 18.dp, vertical = 12.dp)) {
            Text(label, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant); Text(value, fontSize = 22.sp, fontWeight = FontWeight.Medium, modifier = Modifier.padding(vertical = 2.dp))
            Text(note, fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

/** Keadaan memuat/galat untuk layar shift: tak pernah menampilkan angka lama sebagai angka sekarang. */
@Composable
private fun ReportGate(state: Load<ShiftReport>, onRetry: () -> Unit, content: @Composable (ShiftReport) -> Unit) {
    when (state) {
        is Load.Ready -> content(state.value)
        is Load.Failed -> ErrorState(state.error.message, onRetry, Modifier.fillMaxWidth())
        else -> Box(Modifier.fillMaxWidth().padding(vertical = 48.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
    }
}

/** S18 — hub shift: angka dari server (X-report + daftar kas), aksi kas/X-report/tutup (desain 21). */
@Composable
fun ShiftHubScreen(store: PosStore) {
    val s = store.shift
    PosShell(store, title = "Shift") {
        if (s == null) {
            Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.Center, horizontalAlignment = Alignment.CenterHorizontally) {
                Text("Belum ada shift", fontSize = 24.sp); Text("Buka shift untuk menerima pembayaran di terminal ini.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                Spacer(Modifier.height(16.dp)); PrimaryPill("Buka shift", { store.go(Dest.OPEN_SHIFT) })
            }
            return@PosShell
        }
        LaunchedEffect(s.id) { store.refreshShiftReport(); store.refreshCashMovements() }
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) {
            Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.primaryContainer, contentColor = MaterialTheme.colorScheme.onPrimaryContainer) {
                Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                    Surface(Modifier.size(52.dp), shape = RoundedCornerShape(16.dp), color = MaterialTheme.colorScheme.primary) { Box(contentAlignment = Alignment.Center) { Icon(Icons.Filled.PointOfSale, null, tint = MaterialTheme.colorScheme.onPrimary, modifier = Modifier.size(34.dp)) } }
                    Spacer(Modifier.width(18.dp))
                    Column { Text("Shift · ${store.user?.name}", fontSize = 24.sp); Text("Dibuka ${s.openedAt} · modal ${rp(s.float)}", fontSize = 14.sp) }
                }
            }
            Spacer(Modifier.height(12.dp))
            ReportGate(store.report, { store.refreshShiftReport() }) { r ->
                val movements = (store.cashMovements as? Load.Ready)?.value?.size?.let { "$it catatan" } ?: "memuat…"
                val cols = if (windowSize() == Size.COMPACT) 2 else 3
                listOf(
                    Triple("Penjualan", rp(r.totalSales), "${r.orderCount} struk"),
                    Triple("Tunai bersih", rp(r.netCash), "Setelah kembalian ${rp(r.changeGiven)}"),
                    Triple("Transfer", rp(r.amountOf("bank_transfer")), "Non-tunai"),
                    Triple("QRIS", rp(r.amountOf("qr_ewallet")), "Sudah terverifikasi"),
                    Triple("Kas masuk / keluar", "${rp(r.cashIn)} / ${rp(r.cashOut)}", movements),
                    Triple("Void / batal", "${r.voidCount}", rp(r.voidTotal)),
                ).chunked(cols).forEach { row ->
                    Row(Modifier.fillMaxWidth().padding(bottom = 8.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) { row.forEach { (l, v, n) -> Cell(l, v, n, Modifier.weight(1f)) } }
                }
                Row(Modifier.fillMaxWidth().padding(top = 2.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    HourlyCard(r, Modifier.weight(1.1f))
                    MixCard(r, Modifier.weight(1f))
                }
            }
            Spacer(Modifier.height(14.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.fillMaxWidth()) {
                TonalPill("Kas masuk", { store.show(Sheet.CashOut(inflow = true)) }, Modifier.weight(1f), icon = Icons.Filled.ArrowDownward)
                TonalPill("Kas keluar", { store.show(Sheet.CashOut(inflow = false)) }, Modifier.weight(1f), icon = Icons.Filled.ArrowUpward)
                OutlinedPill("X-report", { store.go(Dest.XREPORT) }, Modifier.weight(1f).height(56.dp), icon = Icons.Filled.Summarize)
                PrimaryPill("Tutup shift", { store.go(Dest.CLOSE_SHIFT) }, Modifier.weight(1f), icon = Icons.Filled.Lock)
            }
        }
    }
}

@Composable
private fun HourlyCard(r: ShiftReport, modifier: Modifier) {
    val max = (r.hourly.maxOfOrNull { it.total } ?: 0).coerceAtLeast(1)
    val now = java.util.Calendar.getInstance().get(java.util.Calendar.HOUR_OF_DAY)
    Surface(modifier, shape = RoundedCornerShape(24.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
        Column(Modifier.padding(16.dp)) {
            Text("Penjualan per jam", fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
            if (r.hourly.isEmpty()) {
                Text("Belum ada penjualan di shift ini.", Modifier.padding(top = 12.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
                return@Column
            }
            Row(Modifier.fillMaxWidth().padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Bottom) {
                r.hourly.forEach { h ->
                    Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                        // Area batang tinggi tetap (rata bawah) supaya label jam sejajar di semua kolom.
                        Column(Modifier.height(118.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Bottom) {
                            Text("${h.total / 1000}rb", fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            Box(Modifier.fillMaxWidth().height((6 + 80 * h.total / max).dp).background(if (h.hour == now) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.primaryContainer, RoundedCornerShape(topStart = 8.dp, topEnd = 8.dp)))
                        }
                        Text(h.hour.toString().padStart(2, '0'), Modifier.padding(top = 6.dp), fontSize = 13.sp)
                    }
                }
            }
        }
    }
}

@Composable
private fun MixCard(r: ShiftReport, modifier: Modifier) {
    val parts = listOf(
        Triple("Tunai bersih", r.netCash, MaterialTheme.colorScheme.primaryContainer),
        Triple("Transfer", r.amountOf("bank_transfer"), MaterialTheme.colorScheme.primary),
        Triple("QRIS", r.amountOf("qr_ewallet"), MaterialTheme.colorScheme.secondaryContainer),
    )
    val total = parts.sumOf { it.second }.coerceAtLeast(1)
    Surface(modifier, shape = RoundedCornerShape(24.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
        Column(Modifier.padding(16.dp)) {
            Text("Komposisi pembayaran", fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
            Row(Modifier.fillMaxWidth().padding(vertical = 10.dp).height(14.dp).clip(RoundedCornerShape(50)).background(MaterialTheme.colorScheme.surfaceContainerHighest)) {
                parts.forEach { (_, v, c) -> if (v > 0) Box(Modifier.weight(v.toFloat()).fillMaxHeight().background(c)) }
            }
            parts.forEach { (l, v, c) ->
                Row(Modifier.fillMaxWidth().padding(vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(14.dp).background(c, CircleShape)); Spacer(Modifier.width(12.dp))
                    Text(l, Modifier.weight(1f), fontSize = 17.sp); Text("${v * 100 / total}%", Modifier.padding(end = 16.dp), color = MaterialTheme.colorScheme.onSurfaceVariant); Text(rp(v), fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}

/**
 * S20 — X-report: angka server, shift tetap terbuka (desain 23). Sengaja TANPA kas seharusnya: angka itu hanya
 * muncul di hasil tutup shift, supaya hitungan buta tidak bisa dicocokkan dulu dari X-report.
 */
@Composable
fun XReportScreen(store: PosStore) {
    LaunchedEffect(Unit) { store.refreshShiftReport() }
    PosShell(store, title = "X-report") {
        Surface(Modifier.widthIn(max = 1080.dp).fillMaxWidth().fillMaxHeight(), shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
            Column(Modifier.padding(horizontal = 28.dp, vertical = 20.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    IconButton({ store.go(Dest.SHIFT) }) { Icon(Icons.Filled.ArrowBack, "Kembali") }
                    Text("X-report", Modifier.weight(1f).padding(start = 8.dp), fontSize = 32.sp)
                    Pill("Shift tetap terbuka", MaterialTheme.colorScheme.primaryContainer, MaterialTheme.colorScheme.onPrimaryContainer)
                }
                ReportGate(store.report, { store.refreshShiftReport() }) { r ->
                    val rows = listOf(
                        "Outlet / terminal" to (store.config?.let { "${it.outletName} · ${it.deviceCode}" } ?: "—"),
                        "Kasir / shift" to "${store.user?.name} · dibuka ${store.shift?.openedAt ?: "—"}",
                        "Modal awal" to rp(r.openingCash),
                        "Jumlah struk" to "${r.orderCount}",
                        "Penjualan" to rp(r.totalSales),
                        "Kembalian diberikan" to rp(r.changeGiven),
                        "Tunai diterima" to rp(r.cashReceived),
                        "Transfer" to rp(r.amountOf("bank_transfer")),
                        "QRIS" to rp(r.amountOf("qr_ewallet")),
                        "Kas masuk" to rp(r.cashIn),
                        "Kas keluar" to rp(r.cashOut),
                        "Void / batal" to "${r.voidCount} · ${rp(r.voidTotal)}",
                    )
                    Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(top = 8.dp)) {
                        rows.forEach { (k, v) -> Row(Modifier.fillMaxWidth().padding(vertical = 13.dp)) { Text(k, Modifier.weight(1f), fontSize = 18.sp, color = MaterialTheme.colorScheme.onSurfaceVariant); Text(v, fontSize = 18.sp, fontWeight = FontWeight.Medium) }; HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant) }
                        Text(if (store.conn == Conn.ONLINE) "Angka dihitung server dari transaksi shift ini. Shift tetap terbuka." else "Offline: angka terakhir dari server, mungkin sudah berubah.", Modifier.padding(top = 14.dp), fontSize = 15.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
                Spacer(Modifier.height(8.dp))
                PrimaryPill("Cetak X-report", { store.say("Cetak belum tersedia: printer belum tersambung.") }, icon = Icons.Filled.Print)
            }
        }
    }
}

/** S21 — tutup shift dengan hitung buta. Kasir hanya mengisi hitungan; nilai seharusnya TIDAK ditampilkan di sini. */
@Composable
fun CloseShiftScreen(store: PosStore) {
    val counts = remember { mutableStateMapOf<Int, Int>() }
    var edc by remember { mutableStateOf("") }
    val counted = PosCatalog.denominations.sumOf { it * (counts[it] ?: 0) }
    val online = store.conn == Conn.ONLINE
    val noPending = !store.hasPendingPayment()
    val ready = online && noPending
    val compact = windowSize() == Size.COMPACT
    PosShell(store, title = "Tutup shift") {
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) {
            val gates = listOf(
                Triple(online, "Online", if (online) "Server terjangkau" else "Tutup shift perlu koneksi ke server"),
                Triple(noPending, "Tidak ada pembayaran tertunda", if (noPending) "Siap" else "Verifikasi dulu sebelum menutup shift"),
            )
            if (compact) gates.forEach { (ok, t, sub) -> Gate(ok, t, sub, Modifier.fillMaxWidth().padding(bottom = 8.dp)) }
            else Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(14.dp)) { gates.forEach { (ok, t, sub) -> Gate(ok, t, sub, Modifier.weight(1f)) } }
            Text("Hitung uang per pecahan. Server menjumlahkan hitungan ini; nilai seharusnya baru tampil setelah dikirim.", Modifier.padding(vertical = 8.dp), fontSize = 16.sp, fontWeight = FontWeight.Medium)
            Surface(Modifier.fillMaxWidth().alpha(if (ready) 1f else 0.45f), shape = RoundedCornerShape(24.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
                val perRow = if (compact) 1 else 3
                Column(Modifier.padding(horizontal = 22.dp, vertical = 6.dp)) {
                    PosCatalog.denominations.chunked(perRow).forEach { rowItems ->
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(36.dp)) {
                            rowItems.forEach { d ->
                                val c = counts[d] ?: 0
                                Column(Modifier.weight(1f)) {
                                    Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                                        Text(if (d >= 1000) "Rp ${num(d)}" else "Koin Rp ${num(d)}", Modifier.weight(1f), fontSize = 17.sp, maxLines = 1, softWrap = false)
                                        CircleStepper(c, { if (ready && c > 0) counts[d] = c - 1 }, { if (ready) counts[d] = c + 1 })
                                    }
                                    HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                                }
                            }
                            repeat(perRow - rowItems.size) { Spacer(Modifier.weight(1f)) }
                        }
                    }
                }
            }
            SectionLabel("Total batch EDC (dari mesin EDC)", Modifier.padding(start = 6.dp, top = 10.dp, bottom = 4.dp))
            OutlinedTextField(edc, { if (ready) edc = it.filter(Char::isDigit) }, Modifier.fillMaxWidth(if (compact) 1f else 0.5f), singleLine = true, enabled = ready, shape = RoundedCornerShape(8.dp))
            Text("Pencocokan EDC dengan sistem belum tersedia; isi total batch sesuai mesin.", Modifier.padding(start = 6.dp, top = 4.dp, bottom = 8.dp), fontSize = 14.sp)
            Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(24.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
                Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) { Text("Total hitungan Anda", fontSize = 14.sp, color = MaterialTheme.colorScheme.onSurfaceVariant); Text(rp(counted), fontSize = 34.sp, fontWeight = FontWeight.Light) }
                    PrimaryPill("Kirim hitungan", {
                        store.show(Sheet.Confirm("Kirim hitungan ${rp(counted)}?", "Hitungan tidak dapat diubah setelah dikirim.", "Kirim") { store.submitCount(counts.toMap(), edc.toIntOrNull()) })
                    }, enabled = ready, icon = Icons.Filled.Check)
                }
            }
        }
    }
}

@Composable
private fun Gate(ok: Boolean, title: String, sub: String, modifier: Modifier) {
    Surface(modifier, shape = RoundedCornerShape(20.dp), color = MaterialTheme.colorScheme.surfaceContainerLow, border = androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant)) {
        Column(Modifier.padding(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(if (ok) Icons.Filled.Check else Icons.Filled.WarningAmber, null, Modifier.size(22.dp), tint = if (ok) MaterialTheme.pos.ok else MaterialTheme.colorScheme.error)
                Spacer(Modifier.width(10.dp)); Text(title, fontWeight = FontWeight.SemiBold, fontSize = 18.sp, color = if (ok) MaterialTheme.pos.ok else MaterialTheme.colorScheme.error)
            }
            Text(sub, Modifier.padding(top = 8.dp), fontSize = 15.sp)
        }
    }
}

/** S22 — hasil tutup shift dari server: seharusnya, dihitung, selisih, dan toleransi server (desain 25). */
@Composable
fun CloseResultScreen(store: PosStore) {
    val r = store.closeResult ?: return
    val diffText = if (r.variance == 0) "Rp 0 · sesuai" else (if (r.variance > 0) "Lebih " else "Kurang ") + rp(kotlin.math.abs(r.variance))
    // Tanpa toleransi dari server, tidak ada vonis "sesuai/perlu persetujuan" yang dikarang di klien.
    val within = r.tolerance?.let { kotlin.math.abs(r.variance) <= it }
    PosShell(store, title = "Tutup shift") {
        Surface(Modifier.widthIn(max = 1000.dp).fillMaxWidth(), shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
            Column(Modifier.padding(24.dp)) {
                Surface(Modifier.size(64.dp), shape = CircleShape, color = MaterialTheme.pos.okContainer) { Box(contentAlignment = Alignment.Center) { Icon(Icons.Filled.LockClock, null, Modifier.size(32.dp), tint = MaterialTheme.pos.onOkContainer) } }
                Text("Shift ditutup", Modifier.padding(vertical = 16.dp), fontSize = 32.sp)
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    listOf(Triple("Seharusnya", rp(r.expected), false), Triple("Dihitung", rp(r.counted), false), Triple("Selisih", diffText, true)).forEach { (l, v, diff) ->
                        Surface(Modifier.weight(1f), shape = RoundedCornerShape(20.dp), color = MaterialTheme.colorScheme.surfaceContainerLowest) {
                            Column(Modifier.padding(20.dp)) {
                                Text(l, fontSize = 15.sp); Text(v, Modifier.padding(top = 6.dp), fontSize = 30.sp, fontWeight = FontWeight.Medium,
                                    color = if (diff && within != null) (if (within) MaterialTheme.pos.ok else MaterialTheme.colorScheme.error) else MaterialTheme.colorScheme.onSurface)
                            }
                        }
                    }
                }
                r.tolerance?.let { Text("Toleransi server: ${rp(it)}.", Modifier.padding(top = 10.dp), fontSize = 15.sp, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                if (within == false) Text("Selisih melewati toleransi — perlu persetujuan. Persetujuan belum tersedia di aplikasi ini.", Modifier.padding(top = 6.dp), color = MaterialTheme.colorScheme.error)
                r.edcBatch?.let { Text("Total batch EDC: ${rp(it)}", Modifier.padding(top = 18.dp), fontSize = 17.sp) }
                Text("Z-report tersimpan di server. Cetak belum tersedia: printer belum tersambung.", Modifier.padding(vertical = 18.dp), fontSize = 17.sp)
                PrimaryPill("Selesai", { store.finishShift() })
            }
        }
    }
}

/**
 * S23/S25 — perangkat & sinkron. Hanya nilai dari server (heartbeat). Tidak ada antrean atau cetak tiruan:
 * yang belum ada di server ditampilkan apa adanya ("Belum ada rilis", "Belum tersedia"), bukan angka karangan.
 */
@Composable
fun SyncScreen(store: PosStore) {
    val hb = store.heartbeat
    PosShell(store, title = "Perangkat & sinkron") {
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) {
            Row(Modifier.fillMaxWidth().padding(bottom = 12.dp), horizontalArrangement = Arrangement.End) {
                PrimaryPill("Periksa koneksi", { store.heartbeatNow() }, icon = Icons.Filled.Sync)
            }
            val cells = listOf(
                Triple("Koneksi", syncConnectionLabel(hb), syncConnectionNote(hb)),
                Triple("Jam perangkat", syncSkewLabel(hb.skewSeconds), syncSkewNote(hb.skewSeconds)),
                Triple("Rilis menu", hb.menuRelease ?: "Belum ada rilis", if (hb.menuRelease == null) "Server belum mengirim rilis menu" else "Rilis dari server"),
                Triple("Printer", "Belum tersedia", "Cetak belum tersambung ke perangkat ini"),
                Triple("Siap EOD (perangkat ini)", if (store.shift == null) "Siap" else "Belum", "EOD dijalankan Outlet Manager di back office"),
            )
            cells.chunked(if (windowSize() == Size.COMPACT) 2 else 4).forEach { row -> Row(Modifier.fillMaxWidth().padding(bottom = 10.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) { row.forEach { (a, b, c) -> Cell(a, b, c, Modifier.weight(1f).heightIn(min = 118.dp)) } } }
        }
    }
}

private fun syncConnectionLabel(hb: com.warkop.pos.state.HeartbeatState): String = when {
    hb.lastOkAtMs == null -> "Belum terhubung"
    hb.reachable -> "Terhubung"
    else -> "Terputus"
}

private fun syncConnectionNote(hb: com.warkop.pos.state.HeartbeatState): String {
    val last = hb.lastOkAtMs ?: return "Belum ada heartbeat yang berhasil"
    val time = java.time.Instant.ofEpochMilli(last).atZone(com.warkop.pos.data.OrderMapper.STORE_ZONE).format(java.time.format.DateTimeFormatter.ofPattern("HH:mm:ss"))
    return if (hb.reachable) "Heartbeat terakhir OK $time" else "Heartbeat terakhir OK $time · server tidak terjangkau"
}

private fun syncSkewLabel(skew: Int?): String = if (skew == null) "—" else "$skew dtk"

private fun syncSkewNote(skew: Int?): String = when {
    skew == null -> "Belum ada data dari server"
    com.warkop.pos.state.skewWarns(skew) -> "Selisih lebih dari ${com.warkop.pos.state.SKEW_WARN_SECONDS} dtk — koreksi jam perangkat"
    else -> "Dalam batas ±${com.warkop.pos.state.SKEW_WARN_SECONDS} dtk"
}
