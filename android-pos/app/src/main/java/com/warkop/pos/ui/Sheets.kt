package com.warkop.pos.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.*
import com.warkop.pos.state.Load
import com.warkop.pos.state.PosStore
import com.warkop.pos.state.Sheet
import com.warkop.pos.theme.pos

/** Satu titik masuk untuk semua lembar/dialog: lembar samping di layar lebar, lembar bawah di compact, dialog di tengah. */
@Composable
fun SheetHost(store: PosStore) {
    val sheet = store.sheet ?: return
    val compact = windowSize() == Size.COMPACT
    val scrim = MaterialTheme.colorScheme.scrim
    Box(Modifier.fillMaxSize().background(scrim).clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { store.show(null) }) {
        val consume = Modifier.clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) {}
        when (sheet) {
            is Sheet.Modifier, is Sheet.LineActions, Sheet.OrderActions, Sheet.Split, is Sheet.Discount, is Sheet.CashOut ->
                if (compact) Surface(Modifier.align(Alignment.BottomCenter).fillMaxWidth().heightIn(max = 640.dp).then(consume), shape = RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp), color = MaterialTheme.colorScheme.surfaceContainer) { SheetBody(store, sheet) }
                else Surface(Modifier.align(Alignment.CenterEnd).fillMaxHeight().width(if (windowSize() == Size.MEDIUM) 400.dp else 440.dp).then(consume), shape = RoundedCornerShape(topStart = 28.dp, bottomStart = 28.dp), color = MaterialTheme.colorScheme.surfaceContainer) { SheetBody(store, sheet) }
            else -> Surface(Modifier.align(Alignment.Center).widthIn(max = 480.dp).fillMaxWidth(0.92f).then(consume), shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.surfaceContainer) { SheetBody(store, sheet) }
        }
    }
}

@Composable
private fun SheetBody(store: PosStore, s: Sheet) {
    if (s is Sheet.Modifier) { ModifierSheet(store, s); return }
    if (s is Sheet.CashOut) { CashOutSheet(store, s.inflow); return }
    when (s) {
        is Sheet.LineActions -> { LineActionsSheet(store, s); return }
        Sheet.OrderActions -> { OrderActionsSheet(store); return }
        Sheet.Split -> { SplitSheet(store); return }
        is Sheet.Discount -> { DiscountSheet(store, s); return }
        else -> Unit
    }
    Column(Modifier.statusBarsPadding().navigationBarsPadding().padding(24.dp).verticalScroll(rememberScrollState())) {
        when (s) {
            is Sheet.Pax -> PaxSheet(store, s)
            is Sheet.Modifier -> Unit
            is Sheet.LineActions, Sheet.OrderActions, Sheet.Split, is Sheet.Discount -> Unit
            is Sheet.Refund -> RefundDialog(store, s)
            is Sheet.Void -> VoidDialog(store, s)
            is Sheet.Override -> OverrideDialog(store, s)
            is Sheet.SoldOut -> SoldOutDialog(store, s)
            Sheet.Edc -> EdcDialog(store)
            Sheet.QrisRecord -> QrisRecordDialog(store)
            is Sheet.QrisVerify -> QrisVerifyDialog(store, s)
            is Sheet.Receipt -> ReceiptDialog(store, s)
            is Sheet.CashOut -> Unit
            is Sheet.Confirm -> ConfirmDialog(store, s)
        }
    }
}

/** Kerangka lembar samping desain: judul + isi yang bergulir + (opsional) tombol utama yang tertambat di bawah. */
@Composable
private fun SheetScaffold(title: String, subtitle: String?, onClose: () -> Unit, footer: (@Composable () -> Unit)? = null, content: @Composable ColumnScope.() -> Unit) {
    Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding()) {
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(start = 24.dp, end = 24.dp, top = 20.dp)) {
            Row(verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f)) { Text(title, fontSize = 26.sp); if (subtitle != null) Text(subtitle, fontSize = 15.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(top = 2.dp)) }
                IconButton(onClose) { Icon(Icons.Filled.Close, "Tutup") }
            }
            Spacer(Modifier.height(12.dp))
            content()
            Spacer(Modifier.height(16.dp))
        }
        if (footer != null) { HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant); Box(Modifier.padding(horizontal = 24.dp, vertical = 12.dp)) { footer() } }
    }
}

/** Kontrol segmen dua pilihan (desain: Per item | Rata, Belum dibuat | Sudah dibuat). */
@Composable
private fun Segmented(options: List<String>, selected: Int, onSelect: (Int) -> Unit) {
    Row(Modifier.fillMaxWidth().height(52.dp).clip(RoundedCornerShape(50)).border(1.dp, MaterialTheme.colorScheme.outline, RoundedCornerShape(50))) {
        options.forEachIndexed { i, label ->
            Box(Modifier.weight(1f).fillMaxHeight().background(if (i == selected) MaterialTheme.colorScheme.primaryContainer else Color.Transparent).clickable { onSelect(i) }, contentAlignment = Alignment.Center) {
                Text(label, fontWeight = FontWeight.SemiBold, fontSize = 16.sp)
            }
        }
    }
}

@Composable
private fun ColumnScope.Title(text: String, sub: String? = null, onClose: (() -> Unit)? = null) {
    Row(verticalAlignment = Alignment.Top) {
        Column(Modifier.weight(1f)) { Text(text, fontSize = 22.sp); if (sub != null) Text(sub, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        if (onClose != null) IconButton(onClose) { Icon(Icons.Filled.Close, "Tutup") }
    }
    Spacer(Modifier.height(12.dp))
}

@Composable
private fun ColumnScope.PaxSheet(store: PosStore, s: Sheet.Pax) {
    Title("Meja ${s.table}", "Ketuk jumlah tamu untuk membuka order meja.", { store.show(null) })
    (1..8).chunked(4).forEach { row ->
        Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            row.forEach { n -> Surface(modifier = Modifier.weight(1f).height(64.dp), shape = RoundedCornerShape(16.dp), color = MaterialTheme.colorScheme.surfaceContainerHigh, onClick = { store.openTable(s.table, n) }) { Box(contentAlignment = Alignment.Center) { Text("$n", fontSize = 22.sp, fontWeight = FontWeight.Medium) } } }
        }
    }
}

@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
@Composable
private fun ModifierSheet(store: PosStore, s: Sheet.Modifier) {
    val p = s.product
    val picks = remember { mutableStateMapOf<String, List<ModOption>>() }
    var note by remember { mutableStateOf("") }
    var qty by remember { mutableIntStateOf(1) }
    var firstMissing by remember { mutableStateOf<String?>(null) }
    val catalogGroups = store.menu?.groups.orEmpty()
    val groups = p.groups.mapNotNull { catalogGroups[it] }
    val chosen = groups.flatMap { picks[it.id].orEmpty() }
    val unit = p.price + chosen.sumOf { it.delta }
    Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding()) {
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(start = 24.dp, end = 24.dp, top = 24.dp)) {
            Title(p.name, rp(p.price), { store.show(null) })
            groups.forEach { g ->
                val sel = picks[g.id].orEmpty()
                val rule = if (g.max == 1) "Pilih 1" else if (g.min == 0) "Hingga ${g.max}" else "Pilih ${g.min}–${g.max}"
                Row(Modifier.fillMaxWidth().padding(top = 14.dp, bottom = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(g.name, Modifier.weight(1f), fontSize = 20.sp, fontWeight = FontWeight.Medium)
                    Text(rule, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant); Spacer(Modifier.width(8.dp))
                    when { g.min == 0 -> Text("Opsional", fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant); sel.size >= g.min -> Pill("Terpilih", MaterialTheme.pos.okContainer, MaterialTheme.pos.onOkContainer); else -> Pill("Wajib", MaterialTheme.colorScheme.errorContainer, MaterialTheme.colorScheme.onErrorContainer) }
                }
                FlowRow(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    g.options.forEach { o ->
                        val on = o in sel
                        val single = g.max == 1
                        val missing = firstMissing == g.id && sel.size < g.min
                        Surface(
                            onClick = { picks[g.id] = if (single) listOf(o) else if (on) sel - o else if (sel.size < g.max) sel + o else sel; firstMissing = null },
                            shape = RoundedCornerShape(12.dp),
                            color = if (on) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surfaceContainerLowest,
                            border = BorderStroke(if (on || missing) 2.dp else 1.dp, if (missing) MaterialTheme.colorScheme.error else if (on) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outline),
                        ) {
                            Row(Modifier.padding(horizontal = 14.dp, vertical = 14.dp), verticalAlignment = Alignment.CenterVertically) {
                                if (single) RadioButton(on, null, Modifier.size(22.dp)) else Checkbox(on, null, Modifier.size(22.dp))
                                Spacer(Modifier.width(10.dp)); Text(o.name, fontWeight = FontWeight.SemiBold, fontSize = 16.sp)
                                if (o.delta > 0) { Spacer(Modifier.width(8.dp)); Text("+${num(o.delta)}", fontSize = 16.sp, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                            }
                        }
                    }
                }
            }
            SectionLabel("Catatan untuk dapur", Modifier.padding(top = 22.dp, bottom = 8.dp, start = 4.dp))
            OutlinedTextField(note, { if (it.length <= 100) note = it }, Modifier.fillMaxWidth(), placeholder = { Text("mis. telur setengah matang") }, shape = RoundedCornerShape(8.dp))
            Text("${note.length}/100", Modifier.fillMaxWidth().padding(top = 4.dp), fontSize = 13.sp, textAlign = TextAlign.End, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(Modifier.height(16.dp))
        }
        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
        Column(Modifier.padding(horizontal = 24.dp, vertical = 12.dp)) {
            firstMissing?.let { id -> Row(Modifier.padding(bottom = 8.dp), verticalAlignment = Alignment.CenterVertically) { Icon(Icons.Filled.WarningAmber, null, Modifier.size(18.dp), tint = MaterialTheme.colorScheme.error); Spacer(Modifier.width(8.dp)); Text("Pilih ${catalogGroups[id]?.name?.lowercase()} dulu.", color = MaterialTheme.colorScheme.error, fontSize = 14.sp) } }
            Row(verticalAlignment = Alignment.CenterVertically) {
                QtyStepper(qty, { if (qty > 1) qty-- }, { qty++ }, Modifier.height(56.dp))
                Spacer(Modifier.width(14.dp))
                PrimaryPill("Tambah", {
                    val bad = groups.firstOrNull { (picks[it.id]?.size ?: 0) < it.min }
                    if (bad != null) firstMissing = bad.id else store.addLine(p, chosen, note, qty)
                }, Modifier.weight(1f), trailing = rp(unit * qty))
            }
        }
    }
}

@Composable
private fun LineActionsSheet(store: PosStore, s: Sheet.LineActions) {
    val l = store.active?.lines?.firstOrNull { it.id == s.lineId } ?: run { store.show(null); return }
    var qty by remember { mutableIntStateOf(l.qty) }
    var note by remember { mutableStateOf(l.note) }
    val sub = (if (l.mods.isNotEmpty()) l.modLabel + " · " else "") + rp(l.unit)
    if (l.fired) {
        SheetScaffold(l.name, "$sub · sudah dikirim ke dapur", { store.show(null) }, footer = {
            OutlinedPill("Void item", { store.show(Sheet.Void(l.id)) }, Modifier.fillMaxWidth(), icon = if (store.user?.isSupervisor == true) Icons.Filled.Block else Icons.Filled.Lock, danger = true)
        }) { if (l.note.isNotBlank()) Text("“${l.note}”", color = MaterialTheme.colorScheme.onSurfaceVariant); Text("Perubahan jumlah dilakukan lewat void.", Modifier.padding(top = 8.dp), color = MaterialTheme.colorScheme.onSurfaceVariant) }
        return
    }
    SheetScaffold(l.name, sub, { store.show(null) }, footer = {
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            OutlinedPill("Hapus", { store.removeLine(l.id) }, Modifier.weight(1f).height(56.dp), icon = Icons.Filled.Delete, danger = true)
            PrimaryPill("Simpan", { store.saveLine(l.id, qty, note) }, Modifier.weight(1.5f))
        }
    }) {
        Row(verticalAlignment = Alignment.CenterVertically) { Text("Jumlah", Modifier.weight(1f), fontSize = 18.sp); QtyStepper(qty, { if (qty > 1) qty-- }, { qty++ }, Modifier.height(56.dp)) }
        SectionLabel("Catatan", Modifier.padding(top = 18.dp, bottom = 8.dp, start = 4.dp))
        OutlinedTextField(note, { if (it.length <= 100) note = it }, Modifier.fillMaxWidth(), shape = RoundedCornerShape(8.dp))
        Text("${note.length}/100", Modifier.fillMaxWidth().padding(top = 4.dp), fontSize = 13.sp, textAlign = TextAlign.End, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Spacer(Modifier.height(14.dp))
        OutlinedPill("Diskon item…", { store.show(Sheet.Discount(l.id)) }, Modifier.fillMaxWidth().height(52.dp), icon = Icons.Filled.Percent)
    }
}

@Composable
private fun ActionRow(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, sub: String, danger: Boolean = false, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).clickable(onClick = onClick).padding(vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Surface(Modifier.size(56.dp), shape = androidx.compose.foundation.shape.CircleShape, color = MaterialTheme.colorScheme.primaryContainer) { Box(contentAlignment = Alignment.Center) { Icon(icon, null, Modifier.size(26.dp)) } }
        Column(Modifier.weight(1f).padding(horizontal = 16.dp)) { Text(title, fontWeight = FontWeight.Medium, fontSize = 20.sp, color = if (danger) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface); Text(sub, fontSize = 15.sp, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        Icon(Icons.Filled.ChevronRight, null, tint = if (danger) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface)
    }
}

@Composable
private fun OrderActionsSheet(store: PosStore) {
    val o = store.active ?: return
    SheetScaffold(o.label, "Aksi order", { store.show(null) }) {
        ActionRow(Icons.Filled.Receipt, "Cetak pre-bill", "Tagihan sementara dengan tanda BELUM LUNAS") { store.printPrebill() }
        // 050 / T054 — KOT tidak dicetak ulang dari server; hanya tiket yang sudah pernah dicetak untuk tab ini.
        if (o.lines.any { it.fired }) ActionRow(Icons.Filled.Receipt, "Cetak ulang KOT", "Tiket dapur yang sudah dikirim, untuk dapur") { store.reprintKot() }
        ActionRow(Icons.Filled.Percent, "Diskon order", "Alasan dan persentase dari server") { store.show(Sheet.Discount()) }
        ActionRow(Icons.Filled.CallSplit, "Bagi bill", "Per item atau rata per orang") { store.show(Sheet.Split) }
        ActionRow(Icons.Filled.Cancel, "Batalkan order", "Perlu alasan", danger = true) { store.show(Sheet.Void(null)) }
    }
}

@Composable
private fun DiscountSheet(store: PosStore, s: Sheet.Discount) {
    val o = store.active ?: return
    val line = s.lineId?.let { id -> o.lines.firstOrNull { it.id == id } }
    var percent by remember(s) { mutableIntStateOf(10) }
    var reason by remember(s) { mutableStateOf<ReasonCode?>(null) }
    LaunchedEffect(s) { store.loadDiscountReasons() }
    val title = if (line == null) "Diskon order" else "Diskon item"
    val sub = if (line == null) "${o.label} · subtotal ${rp(o.gross)}" else "${line.qty}× ${line.name} · ${rp(line.net)}"
    SheetScaffold(title, sub, { store.show(null) }, footer = {
        PrimaryPill("Terapkan diskon", { reason?.let { r -> store.applyDiscount(percent.toDouble(), r, s.lineId) } }, Modifier.fillMaxWidth(), enabled = reason != null)
    }) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Potongan", Modifier.weight(1f), fontSize = 18.sp)
            QtyStepper(percent, { if (percent > 1) percent-- }, { if (percent < 100) percent++ }, Modifier.height(56.dp))
        }
        Text("$percent%", Modifier.padding(top = 4.dp, bottom = 8.dp), fontSize = 24.sp)
        SectionLabel("Alasan", Modifier.padding(top = 10.dp, bottom = 8.dp, start = 4.dp))
        when (val r = store.discountReasons) {
            is Load.Ready -> if (r.value.isEmpty()) {
                // Tanpa daftar dari server, diskon tidak diterapkan (alasan tidak dikarang di klien).
                Text("Server belum punya alasan diskon aktif (kategori discount). Diskon belum bisa diterapkan dari sini.", color = MaterialTheme.colorScheme.error, fontSize = 15.sp)
            } else r.value.forEach { ra -> RadioRow(ra.label, reason?.id == ra.id, { reason = ra }, big = true) }
            is Load.Failed -> ErrorState(r.error.message, { store.loadDiscountReasons() }, Modifier.fillMaxWidth())
            else -> Text("Memuat alasan…", color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        if (line == null && (o.serverDiscount ?: 0) > 0) TextButton({ store.clearOrderDiscount() }) { Text("Hapus diskon order") }
        Text("Batas diskon per peran ditentukan server; di atas batas diminta persetujuan. Total yang tampil dari server.", Modifier.padding(top = 10.dp), fontSize = 15.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun SplitSheet(store: PosStore) {
    val o = store.active ?: return
    var equal by remember { mutableStateOf(false) }
    var parts by remember { mutableIntStateOf(2) }
    val picked = remember { mutableStateListOf<Int>() }
    // Angka dari server (bagian dan sisa); tidak pernah dihitung ulang di klien.
    val equalResult = (store.splitResult as? Load.Ready)?.value
    val itemResult = (store.itemSplitResult as? Load.Ready)?.value
    val busy = store.splitResult is Load.Loading || store.itemSplitResult is Load.Loading
    SheetScaffold("Bagi bill", "${o.label} · total ${rp(o.total)}", { store.show(null) }, footer = {
        if (!equal) {
            if (itemResult == null) PrimaryPill("Bayar ${picked.size} item tunai", { store.splitByItem(picked.toSet()) }, Modifier.fillMaxWidth(), enabled = picked.isNotEmpty() && !busy)
            else PrimaryPill("Selesai", { store.show(null) }, Modifier.fillMaxWidth())
        } else if (equalResult == null) PrimaryPill("Bagi rata $parts orang", { store.splitEqual(parts) }, Modifier.fillMaxWidth(), enabled = !busy)
        else PrimaryPill("Selesai", { store.show(null) }, Modifier.fillMaxWidth())
    }) {
        Segmented(listOf("Per item", "Rata"), if (equal) 1 else 0) { equal = it == 1 }
        if (!equal) {
            if (itemResult == null) {
                Text("Pilih item yang dipindah ke bill baru, lalu bayar bagian itu.", Modifier.padding(top = 14.dp, bottom = 8.dp), fontSize = 16.sp)
                o.live.forEach { l ->
                    Row(Modifier.fillMaxWidth().clickable { if (l.id in picked) picked.remove(l.id) else picked.add(l.id) }.padding(vertical = 14.dp), verticalAlignment = Alignment.CenterVertically) {
                        Checkbox(l.id in picked, null); Text("${l.qty}× ${l.name}", Modifier.weight(1f).padding(start = 14.dp), fontSize = 18.sp); Text(num(l.net), fontSize = 18.sp)
                    }
                }
            } else {
                Row(Modifier.fillMaxWidth().padding(top = 14.dp, bottom = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text("Bagian dibayar", Modifier.weight(1f), fontSize = 18.sp); Text(rp(itemResult.paid.total), fontSize = 18.sp)
                }
                Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text("Sisa bill", Modifier.weight(1f), fontSize = 18.sp); Text(rp(itemResult.remaining.total), fontSize = 18.sp)
                }
            }
        } else if (equalResult == null) {
            Row(Modifier.padding(top = 14.dp), verticalAlignment = Alignment.CenterVertically) { Text("Jumlah orang", Modifier.weight(1f), fontSize = 18.sp); QtyStepper(parts, { if (parts > 2) parts-- }, { if (parts < 5) parts++ }, Modifier.height(56.dp)) }
            Text("Jumlah tiap bagian ditentukan server. Tidak ada pembayaran di tahap ini.", Modifier.padding(top = 12.dp), fontSize = 15.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
        } else {
            Text("${equalResult.size} bagian dari server", Modifier.padding(top = 14.dp, bottom = 8.dp), fontSize = 18.sp)
            equalResult.forEach { p ->
                Row(Modifier.fillMaxWidth().padding(vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text("Bagian ${p.index}", Modifier.weight(1f), fontSize = 18.sp); Text(rp(p.amount), fontSize = 18.sp)
                }
            }
        }
    }
}

@Composable
private fun ColumnScope.RefundDialog(store: PosStore, s: Sheet.Refund) {
    var reason by remember(s) { mutableStateOf<ReasonCode?>(null) }
    var note by remember(s) { mutableStateOf("") }
    LaunchedEffect(s) { store.loadRefundReasons() }
    Icon(Icons.Filled.WarningAmber, null, Modifier.align(Alignment.CenterHorizontally).size(36.dp), tint = MaterialTheme.colorScheme.primary)
    Text("Refund order penuh?", Modifier.align(Alignment.CenterHorizontally).padding(top = 6.dp, bottom = 6.dp), fontSize = 26.sp)
    Text("Stok dikembalikan dan kas tunai shift dibalik. Hanya untuk order yang sudah lunas.", Modifier.padding(bottom = 10.dp), fontSize = 16.sp)
    when (val r = store.refundReasons) {
        is Load.Ready -> if (r.value.isEmpty()) {
            Text("Server belum punya alasan refund aktif (kategori refund). Refund belum bisa diproses dari sini.", color = MaterialTheme.colorScheme.error, fontSize = 15.sp)
        } else r.value.forEach { ra -> RadioRow(ra.label, reason?.id == ra.id, { reason = ra }, big = true) }
        is Load.Failed -> ErrorState(r.error.message, { store.loadRefundReasons() }, Modifier.fillMaxWidth())
        else -> Text("Memuat alasan…", color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
    OutlinedTextField(note, { if (it.length <= 200) note = it }, Modifier.fillMaxWidth().padding(top = 10.dp), shape = RoundedCornerShape(8.dp), label = { Text("Catatan (opsional)") })
    Spacer(Modifier.height(16.dp))
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End, verticalAlignment = Alignment.CenterVertically) {
        TextButton({ store.show(null) }) { Text("Batal", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }
        Spacer(Modifier.width(12.dp))
        Button({ reason?.let { r -> store.refundOrder(s.orderId, r, note) } }, Modifier.height(52.dp), enabled = reason != null) {
            Text("Refund", fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
        }
    }
}

@Composable
private fun ColumnScope.VoidDialog(store: PosStore, s: Sheet.Void) {
    val cancel = s.lineId == null
    val reasons = if (cancel) PosCatalog.cancelReasons else PosCatalog.voidReasons
    var reason by remember { mutableStateOf<String?>(reasons.first()) }
    var made by remember { mutableStateOf(false) }
    val line = s.lineId?.let { id -> store.active?.lines?.firstOrNull { it.id == id } }
    Text(if (cancel) "Batalkan order?" else "Void ${line?.qty ?: 1}× ${line?.name ?: "item"}", fontSize = 26.sp, lineHeight = 32.sp)
    Text(if (cancel) "Item terkirim diproses sebagai void." else "Item sudah dikirim ke dapur. Slip BATAL masuk antrean cetak dapur.", Modifier.padding(top = 14.dp, bottom = 10.dp), fontSize = 17.sp)
    reasons.forEach { r -> RadioRow(r, reason == r, { reason = r }, big = true) }
    if (!cancel) {
        Text("Status di dapur", Modifier.padding(top = 16.dp, bottom = 10.dp), fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
        Segmented(listOf("Belum dibuat", "Sudah dibuat (wastage)"), if (made) 1 else 0) { made = it == 1 }
    }
    Spacer(Modifier.height(18.dp))
    Row(horizontalArrangement = Arrangement.End, verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth()) {
        TextButton({ store.show(null) }) { Text("Kembali", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }
        Spacer(Modifier.width(12.dp))
        Button({ reason?.let { r -> if (cancel) store.cancelOrder(r) else store.voidLine(s.lineId!!, r, made) } }, Modifier.height(52.dp), enabled = reason != null) {
            if (store.user?.isSupervisor != true) { Icon(Icons.Filled.Lock, null, Modifier.size(18.dp)); Spacer(Modifier.width(8.dp)) }
            Text(if (cancel) "Batalkan order" else "Void", fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
        }
    }
}

@Composable
private fun ColumnScope.SoldOutDialog(store: PosStore, s: Sheet.SoldOut) {
    var reason by remember(s) { mutableStateOf<ReasonCode?>(null) }
    Icon(Icons.Filled.WarningAmber, null, Modifier.align(Alignment.CenterHorizontally).size(36.dp), tint = MaterialTheme.colorScheme.primary)
    Text("Tandai ${s.product.name} habis?", Modifier.align(Alignment.CenterHorizontally).padding(top = 6.dp, bottom = 6.dp), fontSize = 26.sp)
    Text("Pilih alasan. Item tidak bisa dipesan sampai dibuka lagi.", Modifier.padding(bottom = 10.dp), fontSize = 16.sp)
    when (val r = store.soldOutReasons) {
        is Load.Ready -> if (r.value.isEmpty()) {
            // Tidak ada alasan di server: item TIDAK ditandai habis (tidak ada id yang dikarang).
            Text("Server belum punya alasan 86 aktif (kategori sold_out). Item tidak bisa ditandai habis dari sini.", color = MaterialTheme.colorScheme.error, fontSize = 15.sp)
        } else {
            r.value.forEach { ra -> RadioRow(ra.label, reason?.id == ra.id, { reason = ra }, big = true) }
        }
        is Load.Failed -> ErrorState(r.error.message, { store.loadSoldOutReasons() }, Modifier.fillMaxWidth())
        else -> Text("Memuat alasan…", color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
    Spacer(Modifier.height(16.dp))
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End, verticalAlignment = Alignment.CenterVertically) {
        TextButton({ store.show(null) }) { Text("Batal", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }
        Spacer(Modifier.width(12.dp))
        Button({ reason?.let { store.confirmSoldOut(s.product, it.id) } }, Modifier.height(52.dp), enabled = reason != null) {
            Text("Tandai habis", fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
        }
    }
}

@Composable
private fun ColumnScope.OverrideDialog(store: PosStore, s: Sheet.Override) {
    val me = store.user
    var who by remember(s) { mutableStateOf<Approver?>(null) }
    var pin by remember(s) { mutableStateOf("") }
    var err by remember(s) { mutableStateOf<String?>(null) }
    var busy by remember(s) { mutableStateOf(false) }
    LaunchedEffect(s.action) { store.loadApprovers(s.action) }
    val loaded = (store.approvers as? Load.Ready)?.value.orEmpty()
    // Penyetuju pertama dipilih otomatis; pilihan yang sudah tak ada di daftar dibuang.
    LaunchedEffect(loaded) { if (loaded.none { it.id == who?.id }) who = loaded.firstOrNull() }
    Icon(Icons.Filled.VerifiedUser, null, Modifier.align(Alignment.CenterHorizontally).size(40.dp), tint = MaterialTheme.colorScheme.primary)
    Text("Perlu persetujuan", Modifier.align(Alignment.CenterHorizontally).padding(top = 6.dp, bottom = 10.dp), fontSize = 28.sp)
    Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.surfaceContainerLowest) { Text("${s.summary}. Diminta oleh ${me?.name}.", Modifier.padding(14.dp), fontSize = 15.sp) }
    when (val a = store.approvers) {
        is Load.Ready -> if (a.value.isEmpty()) {
            Text("Tidak ada penyetuju yang memenuhi syarat untuk aksi ini.", Modifier.padding(vertical = 14.dp), color = MaterialTheme.colorScheme.error)
        } else {
            Row(Modifier.fillMaxWidth().padding(vertical = 10.dp), horizontalArrangement = Arrangement.spacedBy(12.dp, Alignment.CenterHorizontally)) {
                a.value.forEach { ap ->
                    val on = who?.id == ap.id
                    Surface(onClick = { who = ap; pin = ""; err = null }, shape = RoundedCornerShape(50), color = if (on) MaterialTheme.colorScheme.primaryContainer else Color.Transparent, border = BorderStroke(1.dp, MaterialTheme.colorScheme.outline)) {
                        Row(Modifier.padding(horizontal = 6.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                            Surface(Modifier.size(38.dp), shape = androidx.compose.foundation.shape.CircleShape, color = MaterialTheme.colorScheme.tertiaryContainer) { Box(contentAlignment = Alignment.Center) { Text(ap.name.take(2).uppercase(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, color = MaterialTheme.colorScheme.onTertiaryContainer) } }
                            Column(Modifier.padding(start = 10.dp, end = 12.dp)) {
                                Text(ap.name, fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
                                Text(ap.role, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                    }
                }
            }
        }
        is Load.Failed -> ErrorState(a.error.message, { store.loadApprovers(s.action) }, Modifier.fillMaxWidth().padding(vertical = 8.dp))
        else -> Text("Memuat penyetuju…", Modifier.padding(vertical = 14.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
    Row(Modifier.fillMaxWidth().height(22.dp), horizontalArrangement = Arrangement.Center) { err?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 14.sp) } }
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterHorizontally)) { repeat(6) { i -> Box(Modifier.size(15.dp).clip(androidx.compose.foundation.shape.CircleShape).background(if (i < pin.length) MaterialTheme.colorScheme.onSurface else Color.Transparent).border(1.5.dp, if (i < pin.length) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.outline, androidx.compose.foundation.shape.CircleShape)) } }
    Keypad({ d -> if (pin.length < 6 && !busy) pin += d }, { pin = pin.dropLast(1) }, Modifier.fillMaxWidth(), keyHeight = 50.dp, light = true)
    Row(Modifier.fillMaxWidth().padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Text("Persetujuan berlaku untuk satu aksi ini saja dan dicatat.", Modifier.weight(1f), fontSize = 15.sp)
        TextButton({ store.show(null) }) { Text("Batal", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }
        Button(onClick = {
            val approver = who ?: return@Button
            val secret = pin
            pin = ""; err = null; busy = true
            store.requestOverride(s, approver.id, secret) { msg -> busy = false; err = msg }
        }, enabled = who != null && pin.length == 6 && !busy) { Text("Setujui", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }
    }
}

@Composable
private fun ColumnScope.EdcDialog(store: PosStore) {
    val o = store.active ?: return
    var code by remember { mutableStateOf("") }
    var err by remember { mutableStateOf(false) }
    var picked by remember { mutableStateOf<BankChannel?>(null) }
    LaunchedEffect(Unit) { store.loadEdcBanks() }
    val banks = (store.edcBanks as? Load.Ready)?.value.orEmpty()
    // Satu bank: dipilih otomatis. Lebih dari satu: kasir memilih dulu; kode approval baru bisa diisi setelah itu.
    val bank = if (banks.size == 1) banks.single() else picked?.takeIf { p -> banks.any { it.id == p.id } }
    Icon(Icons.Filled.CreditCard, null, Modifier.size(32.dp), tint = MaterialTheme.colorScheme.primary)
    Text("EDC", Modifier.padding(top = 10.dp), fontSize = 30.sp)
    Text("Masukkan nominal ini di mesin EDC", Modifier.padding(top = 18.dp), fontSize = 17.sp)
    Text(rp(o.remaining), Modifier.padding(vertical = 6.dp), fontSize = 56.sp, fontWeight = FontWeight.Light)
    when (val loaded = store.edcBanks) {
        is Load.Failed -> ErrorState(loaded.error.message, { store.loadEdcBanks() }, Modifier.fillMaxWidth())
        is Load.Ready -> if (loaded.value.isEmpty()) {
            // Tanpa kanal bank di server, pembayaran EDC tidak dikirim (tidak ada channel_id yang dikarang).
            Text("Server belum punya kanal bank (bank_transfer). Pembayaran EDC tidak bisa dicatat dari sini.", color = MaterialTheme.colorScheme.error, fontSize = 15.sp)
        } else {
            if (banks.size > 1) {
                SectionLabel("Bank mesin EDC", Modifier.padding(start = 8.dp, top = 10.dp, bottom = 6.dp))
                banks.forEach { b -> RadioRow(b.name, bank?.id == b.id, { picked = b }, big = true) }
            }
            if (bank != null) {
                SectionLabel("Kode approval dari slip EDC", Modifier.padding(start = 8.dp, top = 10.dp, bottom = 6.dp))
                OutlinedTextField(code, { code = it.uppercase().filter { c -> c.isLetterOrDigit() }.take(12); err = false }, Modifier.fillMaxWidth(), isError = err, singleLine = true, shape = RoundedCornerShape(8.dp),
                    supportingText = { Text(if (err) "Kode approval 6-12 huruf atau angka." else "Nomor kartu tidak pernah dicatat di aplikasi.") })
            } else Text("Pilih bank mesin EDC dulu, lalu isi kode approval.", Modifier.padding(top = 10.dp), fontSize = 15.sp)
        }
        else -> Text("Memuat bank…", Modifier.padding(top = 10.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
    Spacer(Modifier.height(14.dp))
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End, verticalAlignment = Alignment.CenterVertically) {
        TextButton({ store.show(null) }) { Text("Batal", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }; Spacer(Modifier.width(12.dp))
        Button({
            val b = bank ?: return@Button
            if (code.length in 6..12) store.payEdc(b.id, code) else err = true
        }, Modifier.height(52.dp), enabled = bank != null) { Text("Simpan pembayaran", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }
    }
}

@Composable
private fun ColumnScope.QrisRecordDialog(store: PosStore) {
    val o = store.active ?: return
    Icon(Icons.Filled.QrCode2, null, Modifier.size(32.dp), tint = MaterialTheme.colorScheme.primary)
    Text("${store.qrisLabel} (statis)", Modifier.padding(top = 10.dp, bottom = 16.dp), fontSize = 30.sp)
    Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.errorContainer, contentColor = MaterialTheme.colorScheme.onErrorContainer) {
        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) { Icon(Icons.Filled.WarningAmber, null, Modifier.size(22.dp)); Spacer(Modifier.width(12.dp)); Text("Jangan terima bukti dari layar ponsel pelanggan.", fontWeight = FontWeight.Medium, fontSize = 16.sp) }
    }
    Row(Modifier.fillMaxWidth().padding(vertical = 16.dp), verticalAlignment = Alignment.Top) {
        Surface(Modifier.width(210.dp), shape = RoundedCornerShape(14.dp), color = Color.White, border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant)) {
            Column {
                Box(Modifier.fillMaxWidth().background(MaterialTheme.colorScheme.primary).padding(horizontal = 14.dp, vertical = 10.dp)) { Text(store.qrisLabel, color = MaterialTheme.colorScheme.onPrimary, fontWeight = FontWeight.Bold, fontSize = 15.sp) }
                Icon(Icons.Filled.QrCode2, null, Modifier.padding(10.dp).fillMaxWidth().aspectRatio(1f), tint = Color(0xFF201E1D))
                Text("WARKOP WJM 3 · NMID ID10••••", Modifier.padding(start = 14.dp, end = 14.dp, bottom = 12.dp), fontSize = 12.sp, color = Color(0xFF201E1D))
            }
        }
        Column(Modifier.weight(1f).padding(start = 20.dp, top = 30.dp)) {
            Text("Standee QRIS statis di meja kasir", fontSize = 14.sp)
            Text(rp(o.remaining), Modifier.padding(vertical = 6.dp), fontSize = 40.sp, fontWeight = FontWeight.Light)
            Text("Catat sebagai menunggu verifikasi, lalu cek transaksi di aplikasi ${store.qrisLabel}.", fontSize = 16.sp)
        }
    }
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End, verticalAlignment = Alignment.CenterVertically) {
        TextButton({ store.show(null) }) { Text("Tutup", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }; Spacer(Modifier.width(12.dp))
        Button({ store.recordQris() }, Modifier.height(52.dp)) { Text("Catat · menunggu verifikasi", fontWeight = FontWeight.SemiBold, fontSize = 17.sp); Spacer(Modifier.width(10.dp)); Icon(Icons.Filled.ArrowForward, null, Modifier.size(18.dp)) }
    }
}

@Composable
private fun ColumnScope.QrisVerifyDialog(store: PosStore, s: Sheet.QrisVerify) {
    val t = store.active?.tenders?.firstOrNull { it.id == s.tenderId } ?: return
    var ref by remember { mutableStateOf("") }
    Icon(Icons.Filled.QrCode2, null, Modifier.size(32.dp), tint = MaterialTheme.colorScheme.primary)
    Text("Verifikasi QRIS", Modifier.padding(top = 10.dp, bottom = 16.dp), fontSize = 30.sp)
    Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.errorContainer, contentColor = MaterialTheme.colorScheme.onErrorContainer) {
        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) { Icon(Icons.Filled.WarningAmber, null, Modifier.size(22.dp)); Spacer(Modifier.width(12.dp)); Text("Jangan terima bukti dari layar ponsel pelanggan.", fontWeight = FontWeight.Medium, fontSize = 16.sp) }
    }
    Text(androidx.compose.ui.text.buildAnnotatedString { append("Cek transaksi "); pushStyle(androidx.compose.ui.text.SpanStyle(fontWeight = FontWeight.Bold)); append(rp(t.amount)); pop(); append(" di aplikasi ${store.qrisLabel}, lalu masukkan referensinya.") }, Modifier.padding(vertical = 14.dp), fontSize = 17.sp)
    SectionLabel("Referensi transaksi", Modifier.padding(start = 8.dp, bottom = 6.dp))
    OutlinedTextField(ref, { ref = it }, Modifier.fillMaxWidth(), singleLine = true, shape = RoundedCornerShape(8.dp))
    Spacer(Modifier.height(14.dp))
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End, verticalAlignment = Alignment.CenterVertically) {
        TextButton({ store.show(null) }) { Text("Tutup", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }
        TextButton({ store.failQris(t.id) }) { Text("Tidak ditemukan", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }
        Spacer(Modifier.width(8.dp))
        Button({ store.verifyQris(t.id, ref) }, Modifier.height(52.dp), enabled = ref.length >= 4) { Text("Verifikasi", fontWeight = FontWeight.SemiBold, fontSize = 17.sp); if (store.user?.isSupervisor != true) { Spacer(Modifier.width(8.dp)); Icon(Icons.Filled.Lock, null, Modifier.size(18.dp)) } }
    }
}

@Composable
private fun ColumnScope.ConfirmDialog(store: PosStore, s: Sheet.Confirm) {
    Title(s.title)
    Text(s.body, color = MaterialTheme.colorScheme.onSurfaceVariant)
    Spacer(Modifier.height(16.dp))
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) { TextButton({ store.show(null) }) { Text("Batal") }; Spacer(Modifier.width(8.dp)); Button({ s.onConfirm() }) { Text(s.confirm) } }
}

@Composable
private fun CashOutSheet(store: PosStore, startInflow: Boolean) {
    // Kode alasan dikirim ke server apa adanya (CashMovement::REASONS); label hanya untuk layar.
    var inflow by remember(startInflow) { mutableStateOf(startInflow) }
    var reason by remember { mutableStateOf<String?>(null) }
    var note by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }
    val value = amount.toIntOrNull() ?: 0
    val reasons = if (inflow) PosCatalog.cashInReasons else PosCatalog.cashOutReasons
    val threshold = store.limits?.cashOutThreshold
    val needsNote = reason == "other"
    val canSave = reason != null && value > 0 && (!needsNote || note.isNotBlank())
    Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding()) {
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(start = 24.dp, end = 24.dp, top = 20.dp)) {
            Title("Kas ${if (inflow) "masuk" else "keluar"}", "Dicatat ke shift · ${store.user?.name}", { store.show(null) })
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(inflow, { inflow = true; reason = null }, { Text("Kas masuk") }, shape = RoundedCornerShape(50))
                FilterChip(!inflow, { inflow = false; reason = null }, { Text("Kas keluar") }, shape = RoundedCornerShape(50))
            }
            SectionLabel("Alasan", Modifier.padding(top = 12.dp, bottom = 2.dp))
            reasons.forEach { (code, label) -> RadioRow(label, reason == code, { reason = code }) }
            if (needsNote) {
                OutlinedTextField(note, { note = it.take(200) }, Modifier.fillMaxWidth().padding(top = 8.dp), singleLine = true,
                    label = { Text("Catatan (wajib)") }, shape = RoundedCornerShape(8.dp))
            }
            Text(rp(value), Modifier.fillMaxWidth().padding(vertical = 6.dp), fontSize = 30.sp, textAlign = TextAlign.End, fontWeight = FontWeight.Light)
            // Batas dari server. Bila belum terbaca, tidak ada angka tebakan; server tetap menolak di atas ambang.
            if (!inflow && threshold != null && value > threshold) {
                Text("Di atas ${rp(threshold)}: perlu persetujuan. Persetujuan belum tersedia di aplikasi ini.", color = MaterialTheme.colorScheme.error, fontSize = 13.sp, modifier = Modifier.padding(bottom = 6.dp))
            } else if (!inflow && threshold == null) {
                Text("Kas keluar di atas batas server perlu persetujuan.", color = MaterialTheme.colorScheme.onSurfaceVariant, fontSize = 13.sp, modifier = Modifier.padding(bottom = 6.dp))
            }
            Keypad({ d -> if (amount.length < 9) amount = (amount + d).trimStart('0') }, { amount = amount.dropLast(1) }, Modifier.fillMaxWidth(), extraKey = "000", keyHeight = 40.dp, shape = RoundedCornerShape(12.dp))
            Spacer(Modifier.height(12.dp))
        }
        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
        PrimaryPill("Simpan", { reason?.let { store.cashMove(inflow, it, note.takeIf { _ -> needsNote }?.trim(), value) } }, Modifier.fillMaxWidth().padding(horizontal = 24.dp, vertical = 12.dp), enabled = canSave)
    }
}

@Composable
private fun ColumnScope.ReceiptDialog(store: PosStore, s: Sheet.Receipt) {
    // T038: struk dari server (GET /orders/{id}/receipt). Tidak ada angka dari daftar order lokal.
    LaunchedEffect(s.orderId) { store.loadReceipt(s.orderId) }
    when (val r = store.receipts[s.orderId] ?: Load.Idle) {
        is Load.Ready -> {
            ReceiptPaper(r.value)
            // 050 / T051 — cetak struk ke printer; salinan 1 sudah otomatis saat bayar, jadi ini cetak ulang atau retry.
            if (store.printer != null) PrimaryPill("Cetak struk", { store.printReceipt(s.orderId) }, Modifier.fillMaxWidth().padding(top = 12.dp), icon = Icons.Filled.Print)
        }
        is Load.Failed -> ErrorState(friendlyLoadError(r.error), { store.loadReceipt(s.orderId) }, Modifier.fillMaxWidth(), offline = isOfflineError(r.error))
        else -> Text("Memuat struk…", Modifier.padding(24.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun ReceiptPaper(r: com.warkop.pos.model.ReceiptView) {
    val mono = androidx.compose.ui.text.font.FontFamily.Monospace
    val ink = Color(0xFF201E1D)
    @Composable fun Line(l: String, rightText: String, bold: Boolean = false, size: Int = 15) {
        Row(Modifier.fillMaxWidth().padding(vertical = 1.dp)) {
            Text(l, Modifier.weight(1f), fontFamily = mono, fontSize = size.sp, fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal, color = ink)
            Text(rightText, fontFamily = mono, fontSize = size.sp, fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal, color = ink)
        }
    }
    @Composable fun Dash() = Text("- ".repeat(26).trim(), fontFamily = mono, fontSize = 14.sp, color = ink, maxLines = 1, modifier = Modifier.padding(vertical = 6.dp))
    Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(14.dp), color = Color.White) {
        Column(Modifier.padding(horizontal = 20.dp, vertical = 14.dp)) {
            if (r.copyNo > 1) Box(Modifier.border(2.dp, ink).padding(horizontal = 12.dp, vertical = 6.dp)) { Text("SALINAN ${r.copyNo}", fontFamily = mono, fontWeight = FontWeight.Bold, color = ink, fontSize = 16.sp) }
            Text(r.storeName.uppercase(), Modifier.padding(top = 10.dp), fontFamily = mono, fontWeight = FontWeight.Bold, fontSize = 20.sp, color = ink)
            r.storeAddress?.let { Text(it, Modifier.padding(top = 6.dp), fontFamily = mono, fontSize = 15.sp, color = ink) }
            Dash()
            Text(r.receiptNo ?: r.orderNumber, fontFamily = mono, fontSize = 15.sp, color = ink, modifier = Modifier.padding(vertical = 1.dp))
            Text(listOfNotNull(r.paidAtText, r.cashier?.let { "Kasir $it" }).joinToString(" · "), fontFamily = mono, fontSize = 15.sp, color = ink, modifier = Modifier.padding(vertical = 3.dp))
            r.queueNo?.let { Text("Antrean $it", fontFamily = mono, fontSize = 15.sp, color = ink, modifier = Modifier.padding(vertical = 3.dp)) }
            Dash()
            r.items.forEach { l -> Line("${l.qty}× ${l.name}", rp(l.lineTotal)) }
            Dash()
            Line("Subtotal", rp(r.subtotal))
            if (r.discount > 0) Line("Diskon", "-" + rp(r.discount))
            Line("${r.taxLabel} ${r.taxRatePercent}%", rp(r.tax))
            Line("TOTAL", rp(r.total), bold = true, size = 17)
            r.payments.forEach { (method, amount) -> Line(method, rp(amount)) }
            if (r.change > 0) Line("Kembali", rp(r.change))
            Text("Terima kasih", Modifier.padding(top = 14.dp), fontFamily = mono, fontSize = 15.sp, color = ink)
        }
    }
}
