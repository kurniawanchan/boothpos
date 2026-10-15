package com.warkop.pos.ui.screens

import androidx.compose.foundation.background
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
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.*
import com.warkop.pos.state.Conn
import com.warkop.pos.state.Dest
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

/** S18 — hub shift: ringkasan angka perangkat + aksi kas/X-report/tutup (desain 21). */
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
        val sales = store.salesOrders
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) {
            Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.primaryContainer, contentColor = MaterialTheme.colorScheme.onPrimaryContainer) {
                Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                    Surface(Modifier.size(52.dp), shape = RoundedCornerShape(16.dp), color = MaterialTheme.colorScheme.primary) { Box(contentAlignment = Alignment.Center) { Icon(Icons.Filled.PointOfSale, null, tint = MaterialTheme.colorScheme.onPrimary, modifier = Modifier.size(34.dp)) } }
                    Spacer(Modifier.width(18.dp))
                    Column { Text("Shift 1 · ${store.user?.name}", fontSize = 24.sp); Text("Dibuka ${s.openedAt} · jadwal ${s.schedule.replace("-", "–")} · modal ${rp(s.float)}", fontSize = 14.sp) }
                }
            }
            Spacer(Modifier.height(12.dp))
            val cols = if (windowSize() == Size.COMPACT) 2 else 3
            listOf(
                Triple("Penjualan", rp(sales.sumOf { it.total }), "${sales.size} struk"), Triple("Tunai", rp(store.cashSales()), "Termasuk kembalian sudah dikurangi"), Triple("EDC BCA", rp(store.edcSales()), "Cocokkan dengan batch EDC"),
                Triple("QRIS GoPay", rp(store.qrisSales()), "Terverifikasi"), Triple("Kas masuk / keluar", "${rp(store.cashIn())} / ${rp(store.cashOut())}", "${store.cashMovements.size} catatan"), Triple("Void / batal", "${store.orders.count { it.cancelledReason != null }}", "baris item"),
            ).chunked(cols).forEach { row ->
                Row(Modifier.fillMaxWidth().padding(bottom = 8.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) { row.forEach { (l, v, n) -> Cell(l, v, n, Modifier.weight(1f)) } }
            }
            Row(Modifier.fillMaxWidth().padding(top = 2.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                HourlyCard(store, Modifier.weight(1.1f))
                MixCard(store, Modifier.weight(1f))
            }
            Spacer(Modifier.height(14.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.fillMaxWidth()) {
                TonalPill("Kas masuk", { store.show(Sheet.CashOut) }, Modifier.weight(1f), icon = Icons.Filled.ArrowDownward)
                TonalPill("Kas keluar", { store.show(Sheet.CashOut) }, Modifier.weight(1f), icon = Icons.Filled.ArrowUpward)
                OutlinedPill("X-report", { store.go(Dest.XREPORT) }, Modifier.weight(1f).height(56.dp), icon = Icons.Filled.Summarize)
                PrimaryPill("Tutup shift", { store.go(Dest.CLOSE_SHIFT) }, Modifier.weight(1f), icon = Icons.Filled.Lock)
            }
        }
    }
}

@Composable
private fun HourlyCard(store: PosStore, modifier: Modifier) {
    val hours = (7..14).toList()
    val perHour = hours.associateWith { h -> store.salesOrders.filter { (it.closedAt ?: "").take(2).toIntOrNull() == h }.sumOf { it.total } }
    val max = (perHour.values.maxOrNull() ?: 0).coerceAtLeast(1)
    val now = java.util.Calendar.getInstance().get(java.util.Calendar.HOUR_OF_DAY)
    Surface(modifier, shape = RoundedCornerShape(24.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
        Column(Modifier.padding(16.dp)) {
            Text("Penjualan per jam", fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
            Row(Modifier.fillMaxWidth().padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Bottom) {
                hours.forEach { h ->
                    val v = perHour.getValue(h)
                    Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                        // Area batang tinggi tetap (rata bawah) supaya label jam sejajar di semua kolom.
                        Column(Modifier.height(118.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Bottom) {
                            if (v > 0) Text("${v / 1000}rb", fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            Box(Modifier.fillMaxWidth().height((6 + 80 * v / max).dp).background(if (h == now) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.primaryContainer, RoundedCornerShape(topStart = 8.dp, topEnd = 8.dp)))
                        }
                        Text(h.toString().padStart(2, '0'), Modifier.padding(top = 6.dp), fontSize = 13.sp)
                    }
                }
            }
        }
    }
}

@Composable
private fun MixCard(store: PosStore, modifier: Modifier) {
    val parts = listOf(Triple("Tunai", store.cashSales(), MaterialTheme.colorScheme.primaryContainer), Triple("EDC BCA", store.edcSales(), MaterialTheme.colorScheme.primary), Triple("QRIS", store.qrisSales(), MaterialTheme.colorScheme.secondaryContainer))
    val total = parts.sumOf { it.second }.coerceAtLeast(1)
    Surface(modifier, shape = RoundedCornerShape(24.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
        Column(Modifier.padding(16.dp)) {
            Text("Komposisi pembayaran", fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
            Row(Modifier.fillMaxWidth().padding(vertical = 10.dp).height(14.dp).clip(RoundedCornerShape(50)).background(MaterialTheme.colorScheme.surfaceContainerHighest)) {
                parts.forEach { (_, v, c) -> if (v > 0) Box(Modifier.weight(v.toFloat()).fillMaxHeight().background(c)) }
            }
            parts.forEach { (l, v, c) ->
                Row(Modifier.fillMaxWidth().padding(vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(14.dp).background(c, androidx.compose.foundation.shape.CircleShape)); Spacer(Modifier.width(12.dp))
                    Text(l, Modifier.weight(1f), fontSize = 17.sp); Text("${v * 100 / total}%", Modifier.padding(end = 16.dp), color = MaterialTheme.colorScheme.onSurfaceVariant); Text(rp(v), fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}

/** S20 — X-report: angka perangkat, shift tetap terbuka (desain 23). */
@Composable
fun XReportScreen(store: PosStore) {
    val sales = store.salesOrders
    val rows = listOf(
        "Outlet / terminal" to "Warkop WJM 3 · T01", "Kasir / shift" to "${store.user?.name} · Shift 1 sejak ${store.shift?.openedAt ?: "-"}", "Jumlah struk" to "${sales.size}",
        "Penjualan kotor" to rp(sales.sumOf { it.gross }), "Diskon" to rp(sales.sumOf { it.discount }), "Pajak" to rp(sales.sumOf { it.tax }),
        "Tunai" to rp(store.cashSales()), "EDC BCA" to rp(store.edcSales()), "QRIS GoPay" to rp(store.qrisSales()),
        "Kas masuk" to rp(store.cashIn()), "Kas keluar" to rp(store.cashOut()), "Refund tunai" to rp(0), "Void / batal (baris)" to "${store.orders.count { it.cancelledReason != null }}",
    )
    PosShell(store, title = "X-report") {
        Surface(Modifier.widthIn(max = 1080.dp).fillMaxWidth().fillMaxHeight(), shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
            Column(Modifier.padding(horizontal = 28.dp, vertical = 20.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    IconButton({ store.go(Dest.SHIFT) }) { Icon(Icons.Filled.ArrowBack, "Kembali") }
                    Text("X-report", Modifier.weight(1f).padding(start = 8.dp), fontSize = 32.sp)
                    Pill("Shift tetap terbuka", MaterialTheme.colorScheme.primaryContainer, MaterialTheme.colorScheme.onPrimaryContainer)
                }
                Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(top = 8.dp)) {
                    rows.forEach { (k, v) -> Row(Modifier.fillMaxWidth().padding(vertical = 13.dp)) { Text(k, Modifier.weight(1f), fontSize = 18.sp, color = MaterialTheme.colorScheme.onSurfaceVariant); Text(v, fontSize = 18.sp, fontWeight = FontWeight.Medium) }; HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant) }
                    Text(if (store.conn == Conn.ONLINE) "Angka perangkat ini. Laporan harian lengkap ada di back office." else "Offline: angka perangkat, belum tersinkron.", Modifier.padding(top = 14.dp), fontSize = 15.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                Spacer(Modifier.height(8.dp))
                PrimaryPill("Cetak X-report", { store.say("X-report tercetak. Shift tetap terbuka.") }, icon = Icons.Filled.Print)
            }
        }
    }
}

/** S21 — tutup shift dengan hitung buta: tiga gerbang dulu, baru boleh menghitung (desain 24). */
@Composable
fun CloseShiftScreen(store: PosStore) {
    val counts = remember { mutableStateMapOf<Int, Int>() }
    var edc by remember { mutableStateOf("") }
    val counted = PosCatalog.denominations.sumOf { it * (counts[it] ?: 0) }
    val online = store.conn == Conn.ONLINE
    val noPending = !store.hasPendingPayment()
    val queueEmpty = store.pendingEvents.isEmpty()
    val ready = online && noPending && queueEmpty
    val edcCount = store.salesOrders.sumOf { o -> o.tenders.count { it.method == com.warkop.pos.model.TenderMethod.EDC && it.status == com.warkop.pos.model.TenderStatus.OK } }
    val compact = windowSize() == Size.COMPACT
    PosShell(store, title = "Tutup shift") {
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) {
            val gates = listOf(
                Triple(online, "Online", if (online) "Server terjangkau" else "Tutup shift perlu koneksi ke server"),
                Triple(queueEmpty, if (queueEmpty) "Antrean sinkron kosong" else "${store.pendingEvents.size} transaksi belum terkirim", if (queueEmpty) "Semua transaksi tersinkron" else "Sinkronkan dulu"),
                Triple(noPending, "Tidak ada pembayaran tertunda", if (noPending) "Siap" else "Verifikasi dulu, atau Outlet Manager mengalihkan di back office"),
            )
            if (compact) gates.forEach { (ok, t, sub) -> Gate(ok, t, sub, Modifier.fillMaxWidth().padding(bottom = 8.dp)) }
            else Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(14.dp)) { gates.forEach { (ok, t, sub) -> Gate(ok, t, sub, Modifier.weight(1f)) } }
            Text("Hitung kas per pecahan. Nilai seharusnya baru tampil setelah hitungan dikirim.", Modifier.padding(vertical = 8.dp), fontSize = 16.sp, fontWeight = FontWeight.Medium)
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
            SectionLabel("Total batch EDC BCA · TID 0091", Modifier.padding(start = 6.dp, top = 10.dp, bottom = 4.dp))
            OutlinedTextField(edc, { if (ready) edc = it.filter(Char::isDigit) }, Modifier.fillMaxWidth(if (compact) 1f else 0.5f), singleLine = true, enabled = ready, shape = RoundedCornerShape(8.dp))
            Text("Sistem mencatat $edcCount transaksi EDC (nilai disembunyikan sampai dikirim)", Modifier.padding(start = 6.dp, top = 4.dp, bottom = 8.dp), fontSize = 14.sp)
            Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(24.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
                Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) { Text("Total hitungan (nilai sistem disembunyikan)", fontSize = 14.sp, color = MaterialTheme.colorScheme.onSurfaceVariant); Text(rp(counted), fontSize = 34.sp, fontWeight = FontWeight.Light) }
                    PrimaryPill("Kirim hitungan", {
                        store.show(Sheet.Confirm("Kirim hitungan ${rp(counted)}?", "Hitungan tidak dapat diubah setelah dikirim.", "Kirim") { store.submitCount(counted, edc.toIntOrNull() ?: 0) })
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

/** S22 — hasil tutup shift: selisih terhadap toleransi Rp 5.000 (desain 25). */
@Composable
fun CloseResultScreen(store: PosStore) {
    val r = store.closeResult ?: return
    val diffText = if (r.variance == 0) "Rp 0 · sesuai" else (if (r.variance > 0) "Lebih " else "Kurang ") + rp(kotlin.math.abs(r.variance))
    PosShell(store, title = "Tutup shift") {
        Surface(Modifier.widthIn(max = 1000.dp).fillMaxWidth(), shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
            Column(Modifier.padding(24.dp)) {
                Surface(Modifier.size(64.dp), shape = androidx.compose.foundation.shape.CircleShape, color = MaterialTheme.pos.okContainer) { Box(contentAlignment = Alignment.Center) { Icon(Icons.Filled.LockClock, null, Modifier.size(32.dp), tint = MaterialTheme.pos.onOkContainer) } }
                Text("Shift ditutup", Modifier.padding(vertical = 16.dp), fontSize = 32.sp)
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    listOf(Triple("Seharusnya", rp(r.expected), false), Triple("Dihitung", rp(r.counted), false), Triple("Selisih", diffText, true)).forEach { (l, v, diff) ->
                        Surface(Modifier.weight(1f), shape = RoundedCornerShape(20.dp), color = MaterialTheme.colorScheme.surfaceContainerLowest) {
                            Column(Modifier.padding(20.dp)) {
                                Text(l, fontSize = 15.sp); Text(v, Modifier.padding(top = 6.dp), fontSize = 30.sp, fontWeight = FontWeight.Medium,
                                    color = if (diff) (if (r.withinTolerance) MaterialTheme.pos.ok else MaterialTheme.colorScheme.error) else MaterialTheme.colorScheme.onSurface)
                            }
                        }
                    }
                }
                if (!r.withinTolerance) Text("Selisih melewati toleransi Rp 5.000 — perlu persetujuan Outlet Manager.", Modifier.padding(top = 10.dp), color = MaterialTheme.colorScheme.error)
                Text("EDC: sistem ${rp(r.edcSystem)} · batch ${rp(r.edcBatch)} · ${if (r.edcSystem == r.edcBatch) "cocok" else "selisih"}", Modifier.padding(top = 18.dp), fontSize = 17.sp)
                Text("Z-report dikirim ke printer Kasir. Outlet Manager menutup hari bisnis di back office.", Modifier.padding(vertical = 18.dp), fontSize = 17.sp)
                PrimaryPill("Selesai", { store.finishShift() })
            }
        }
    }
}

/** S23/S25 — perangkat & sinkron (desain 26). Antrean mencerminkan status perangkat; outbox sungguhan menunggu api-gap B16. */
@Composable
fun SyncScreen(store: PosStore) {
    val online = store.conn == Conn.ONLINE
    val pending = store.pendingEvents
    PosShell(store, title = "Perangkat & sinkron") {
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) {
            Row(Modifier.fillMaxWidth().padding(bottom = 12.dp), horizontalArrangement = Arrangement.End) {
                PrimaryPill("Sinkronkan sekarang", { store.say(if (store.syncNow()) "Tersinkron." else "Masih offline. Transaksi tetap aman tersimpan.") }, icon = Icons.Filled.Sync)
            }
            val exposure = store.cashSales()
            val cells = listOf(
                Triple("Koneksi", store.conn.label, if (online) "Heartbeat setiap 60 dtk" else "Diukur dengan jam monotonic"),
                Triple("Antrean", "${pending.size} event", if (pending.isEmpty()) "Kosong" else "Tertua ${pending.first().at}"),
                Triple("Terkirim sesi ini", "${store.outbox.count { it.synced }}", "Batch maks. 50 event"),
                Triple("Rilis menu", "Versi 17", "Diterapkan 06:58"),
                Triple("Eksposur tunai offline", rp(exposure), "Batas Rp 3.000.000 · peringatan 50%"),
                Triple("Jam perangkat", "Selisih 4 dtk", "Peringatan bila > 5 mnt"),
                Triple("Printer", "${store.printJobs.count { !it.ok }} gagal", "Kasir LAN 80 mm · Dapur LAN 80 mm"),
                Triple("Siap EOD (perangkat ini)", if (store.shift == null) "Siap" else "Belum", "EOD dijalankan Outlet Manager di back office"),
            )
            cells.chunked(if (windowSize() == Size.COMPACT) 2 else 4).forEach { row -> Row(Modifier.fillMaxWidth().padding(bottom = 10.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) { row.forEach { (a, b, c) -> Cell(a, b, c, Modifier.weight(1f).heightIn(min = 110.dp)) } } }
            Row(Modifier.fillMaxWidth().padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                QueueCard("Antrean sinkron", Modifier.weight(1f)) {
                    if (store.outbox.isEmpty()) Text("Belum ada event.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    store.outbox.takeLast(4).reversed().forEach { e -> QueueRow(Icons.Filled.Schedule, e.title, e.type, e.at, if (e.synced) MaterialTheme.pos.okContainer else MaterialTheme.pos.warnContainer) }
                }
                QueueCard("Antrean cetak", Modifier.weight(1f)) {
                    if (store.printJobs.isEmpty()) Text("Belum ada pekerjaan cetak.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    store.printJobs.takeLast(4).reversed().forEach { j -> QueueRow(Icons.Filled.Print, j.title, "${j.at} · ${if (j.ok) "Berhasil" else "Gagal"}", null, if (j.ok) MaterialTheme.pos.okContainer else MaterialTheme.colorScheme.errorContainer) }
                }
            }
        }
    }
}

@Composable
private fun QueueCard(title: String, modifier: Modifier, content: @Composable ColumnScope.() -> Unit) {
    Surface(modifier, shape = RoundedCornerShape(24.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
        Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) { Text(title, fontWeight = FontWeight.SemiBold, fontSize = 18.sp); content() }
    }
}

@Composable
private fun QueueRow(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, sub: String, time: String?, tint: androidx.compose.ui.graphics.Color) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Surface(Modifier.size(48.dp), shape = androidx.compose.foundation.shape.CircleShape, color = tint) { Box(contentAlignment = Alignment.Center) { Icon(icon, null, Modifier.size(24.dp)) } }
        Spacer(Modifier.width(14.dp))
        Column(Modifier.weight(1f)) { Text(title, fontWeight = FontWeight.Medium, fontSize = 16.sp); Text(sub, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        if (time != null) Text(time, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}
