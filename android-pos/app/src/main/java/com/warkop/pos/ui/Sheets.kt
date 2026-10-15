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
            is Sheet.Modifier, is Sheet.LineActions, Sheet.OrderActions, Sheet.Split, Sheet.Discount, Sheet.CashOut ->
                if (compact) Surface(Modifier.align(Alignment.BottomCenter).fillMaxWidth().heightIn(max = 640.dp).then(consume), shape = RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp), color = MaterialTheme.colorScheme.surfaceContainer) { SheetBody(store, sheet) }
                else Surface(Modifier.align(Alignment.CenterEnd).fillMaxHeight().width(if (windowSize() == Size.MEDIUM) 400.dp else 440.dp).then(consume), shape = RoundedCornerShape(topStart = 28.dp, bottomStart = 28.dp), color = MaterialTheme.colorScheme.surfaceContainer) { SheetBody(store, sheet) }
            else -> Surface(Modifier.align(Alignment.Center).widthIn(max = 480.dp).fillMaxWidth(0.92f).then(consume), shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.surfaceContainer) { SheetBody(store, sheet) }
        }
    }
}

@Composable
private fun SheetBody(store: PosStore, s: Sheet) {
    if (s is Sheet.Modifier) { ModifierSheet(store, s); return }
    if (s is Sheet.CashOut) { CashOutSheet(store); return }
    when (s) {
        is Sheet.LineActions -> { LineActionsSheet(store, s); return }
        Sheet.OrderActions -> { OrderActionsSheet(store); return }
        Sheet.Split -> { SplitSheet(store); return }
        Sheet.Discount -> { DiscountSheet(store); return }
        else -> Unit
    }
    Column(Modifier.statusBarsPadding().navigationBarsPadding().padding(24.dp).verticalScroll(rememberScrollState())) {
        when (s) {
            is Sheet.Pax -> PaxSheet(store, s)
            is Sheet.Modifier -> Unit
            is Sheet.LineActions, Sheet.OrderActions, Sheet.Split, Sheet.Discount -> Unit
            is Sheet.Void -> VoidDialog(store, s)
            is Sheet.Override -> OverrideDialog(store, s)
            Sheet.Edc -> EdcDialog(store)
            Sheet.QrisRecord -> QrisRecordDialog(store)
            is Sheet.QrisVerify -> QrisVerifyDialog(store, s)
            is Sheet.Receipt -> ReceiptDialog(store, s)
            Sheet.CashOut -> Unit
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
    var disc by remember { mutableIntStateOf(l.discPct) }
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
            PrimaryPill("Simpan", { store.saveLine(l.id, qty, note, disc) }, Modifier.weight(1.5f))
        }
    }) {
        Row(verticalAlignment = Alignment.CenterVertically) { Text("Jumlah", Modifier.weight(1f), fontSize = 18.sp); QtyStepper(qty, { if (qty > 1) qty-- }, { qty++ }, Modifier.height(56.dp)) }
        SectionLabel("Catatan", Modifier.padding(top = 18.dp, bottom = 8.dp, start = 4.dp))
        OutlinedTextField(note, { if (it.length <= 100) note = it }, Modifier.fillMaxWidth(), shape = RoundedCornerShape(8.dp))
        Text("${note.length}/100", Modifier.fillMaxWidth().padding(top = 4.dp), fontSize = 13.sp, textAlign = TextAlign.End, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text("Diskon item", Modifier.padding(top = 14.dp, bottom = 10.dp), fontSize = 18.sp, fontWeight = FontWeight.Medium)
        @OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            (listOf(0 to "Tanpa") + PosCatalog.discountPresets.map { it.pct to "${it.label} ${it.pct}%" }).forEach { (v, t) ->
                Surface(onClick = { disc = v }, shape = RoundedCornerShape(10.dp), color = if (disc == v) MaterialTheme.colorScheme.primaryContainer else Color.Transparent, border = BorderStroke(1.dp, if (disc == v) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outline)) {
                    Text(t, Modifier.padding(horizontal = 16.dp, vertical = 12.dp), fontWeight = FontWeight.SemiBold)
                }
            }
        }
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
        ActionRow(Icons.Filled.Percent, "Diskon order", "Pilih preset diskon beralasan") { store.show(Sheet.Discount) }
        ActionRow(Icons.Filled.CallSplit, "Bagi bill", "Per item atau rata per orang") { store.show(Sheet.Split) }
        ActionRow(Icons.Filled.Cancel, "Batalkan order", "Perlu alasan", danger = true) { store.show(Sheet.Void(null)) }
    }
}

@Composable
private fun DiscountSheet(store: PosStore) {
    val o = store.active ?: return
    val limit = PosCatalog.discountLimit[store.user?.code] ?: 0
    SheetScaffold("Diskon order", "${o.label} · subtotal ${rp(o.gross)}", { store.show(null) }) {
        PosCatalog.discountPresets.forEach { d ->
            Surface(modifier = Modifier.fillMaxWidth().padding(bottom = 10.dp), shape = RoundedCornerShape(16.dp), color = MaterialTheme.colorScheme.surfaceContainerLowest, border = BorderStroke(if (o.discPct == d.pct) 2.dp else 1.dp, if (o.discPct == d.pct) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outline), onClick = { store.applyOrderDiscount(d) }) {
                Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Surface(Modifier.size(width = 96.dp, height = 78.dp), shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.primaryContainer) { Box(contentAlignment = Alignment.Center) { Text("${d.pct}%", fontSize = 26.sp, fontWeight = FontWeight.Medium, color = MaterialTheme.colorScheme.onPrimaryContainer) } }
                    Column(Modifier.weight(1f).padding(horizontal = 16.dp)) {
                        Text(d.label, fontWeight = FontWeight.Medium, fontSize = 20.sp)
                        Text("Alasan tercatat: ${d.reason}" + if (d.pct > limit) " · perlu persetujuan" else "", fontSize = 14.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    if (d.pct > limit) Icon(Icons.Filled.Lock, "Perlu persetujuan", Modifier.size(22.dp))
                }
            }
        }
        if (o.discPct > 0) TextButton({ store.applyOrderDiscount(null) }) { Text("Hapus diskon order") }
        Text("Batas diskon per peran mengikuti PRD §8.4 (nilai contoh).", Modifier.padding(top = 4.dp, start = 4.dp), fontSize = 15.sp)
    }
}

@Composable
private fun SplitSheet(store: PosStore) {
    val o = store.active ?: return
    var equal by remember { mutableStateOf(false) }
    var parts by remember { mutableIntStateOf(2) }
    val picked = remember { mutableStateListOf<Int>() }
    SheetScaffold("Bagi bill", "${o.label} · total ${rp(o.total)}", { store.show(null) }, footer = {
        if (!equal) PrimaryPill("Pindahkan ${picked.size} item ke bill baru", { store.splitByItem(picked.toSet()) }, Modifier.fillMaxWidth(), enabled = picked.isNotEmpty())
        else PrimaryPill("Lanjut ke pembayaran", { store.show(null); store.goPay() }, Modifier.fillMaxWidth())
    }) {
        Segmented(listOf("Per item", "Rata"), if (equal) 1 else 0) { equal = it == 1 }
        if (!equal) {
            Text("Pilih item yang dipindah ke bill baru.", Modifier.padding(top = 14.dp, bottom = 8.dp), fontSize = 16.sp)
            o.live.forEach { l ->
                Row(Modifier.fillMaxWidth().clickable { if (l.id in picked) picked.remove(l.id) else picked.add(l.id) }.padding(vertical = 14.dp), verticalAlignment = Alignment.CenterVertically) {
                    Checkbox(l.id in picked, null); Text("${l.qty}× ${l.name}", Modifier.weight(1f).padding(start = 14.dp), fontSize = 18.sp); Text(num(l.net), fontSize = 18.sp)
                }
            }
        } else {
            Row(Modifier.padding(top = 14.dp), verticalAlignment = Alignment.CenterVertically) { Text("Jumlah orang", Modifier.weight(1f), fontSize = 18.sp); QtyStepper(parts, { if (parts > 2) parts-- }, { if (parts < 5) parts++ }, Modifier.height(56.dp)) }
            Text("${rp(kotlin.math.ceil(o.total / parts.toDouble()).toInt())} / orang", Modifier.padding(vertical = 12.dp), fontSize = 24.sp)
            Text("Setiap orang membayar bagiannya di layar bayar.", fontSize = 15.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
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
    Text(if (cancel) "Item terkirim diproses sebagai void." else "Item sudah dikirim ke dapur. Slip BATAL dicetak ke station.", Modifier.padding(top = 14.dp, bottom = 10.dp), fontSize = 17.sp)
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
private fun ColumnScope.OverrideDialog(store: PosStore, s: Sheet.Override) {
    val me = store.user
    val approvers = PosCatalog.users.filter { it.isSupervisor && it.id != me?.id }
    var who by remember { mutableStateOf(approvers.firstOrNull()) }
    var pin by remember { mutableStateOf("") }
    var err by remember { mutableStateOf<String?>(null) }
    Icon(Icons.Filled.VerifiedUser, null, Modifier.align(Alignment.CenterHorizontally).size(40.dp), tint = MaterialTheme.colorScheme.primary)
    Text("Perlu persetujuan", Modifier.align(Alignment.CenterHorizontally).padding(top = 6.dp, bottom = 10.dp), fontSize = 28.sp)
    Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.surfaceContainerLowest) { Text("${s.summary}. Diminta oleh ${me?.name}.", Modifier.padding(14.dp), fontSize = 15.sp) }
    Row(Modifier.fillMaxWidth().padding(vertical = 10.dp), horizontalArrangement = Arrangement.spacedBy(12.dp, Alignment.CenterHorizontally)) {
        approvers.forEach { a ->
            val on = who == a
            Surface(onClick = { who = a; pin = ""; err = null }, shape = RoundedCornerShape(50), color = if (on) MaterialTheme.colorScheme.primaryContainer else Color.Transparent, border = BorderStroke(1.dp, MaterialTheme.colorScheme.outline)) {
                Row(Modifier.padding(horizontal = 6.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Surface(Modifier.size(38.dp), shape = androidx.compose.foundation.shape.CircleShape, color = MaterialTheme.colorScheme.tertiaryContainer) { Box(contentAlignment = Alignment.Center) { Text(a.name, fontSize = 11.sp, fontWeight = FontWeight.SemiBold, color = MaterialTheme.colorScheme.onTertiaryContainer) } }
                    Text(a.role, Modifier.padding(start = 10.dp, end = 12.dp), fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
                }
            }
        }
    }
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterHorizontally)) { repeat(6) { i -> Box(Modifier.size(15.dp).clip(androidx.compose.foundation.shape.CircleShape).background(if (i < pin.length) MaterialTheme.colorScheme.onSurface else Color.Transparent).border(1.5.dp, if (i < pin.length) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.outline, androidx.compose.foundation.shape.CircleShape)) } }
    Box(Modifier.height(22.dp).fillMaxWidth(), contentAlignment = Alignment.Center) { err?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 14.sp) } }
    Keypad({ d -> if (pin.length < 6) { pin += d; if (pin.length == 6) { err = who?.let { store.approve(pin, it) }; pin = "" } } }, { pin = pin.dropLast(1) }, Modifier.fillMaxWidth(), keyHeight = 50.dp, light = true)
    Row(Modifier.fillMaxWidth().padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(if (store.conn == com.warkop.pos.state.Conn.ONLINE) "Persetujuan berlaku untuk aksi ini saja dan dicatat." else "Offline: diverifikasi dengan data PIN tersimpan, batas offline berlaku.", Modifier.weight(1f), fontSize = 15.sp)
        TextButton({ store.show(null) }) { Text("Batal", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }
    }
}

@Composable
private fun ColumnScope.EdcDialog(store: PosStore) {
    val o = store.active ?: return
    var code by remember { mutableStateOf("") }
    var err by remember { mutableStateOf(false) }
    Icon(Icons.Filled.CreditCard, null, Modifier.size(32.dp), tint = MaterialTheme.colorScheme.primary)
    Text("EDC BCA", Modifier.padding(top = 10.dp), fontSize = 30.sp)
    Text("Masukkan nominal ini di mesin EDC", Modifier.padding(top = 18.dp), fontSize = 17.sp)
    Text(rp(o.remaining), Modifier.padding(vertical = 6.dp), fontSize = 56.sp, fontWeight = FontWeight.Light)
    SectionLabel("Kode approval dari slip EDC", Modifier.padding(start = 8.dp, top = 10.dp, bottom = 6.dp))
    OutlinedTextField(code, { code = it.uppercase().filter { c -> c.isLetterOrDigit() }.take(12); err = false }, Modifier.fillMaxWidth(), isError = err, singleLine = true, shape = RoundedCornerShape(8.dp),
        supportingText = { Text(if (err) "Kode approval 6-12 huruf atau angka." else "Nomor kartu tidak pernah dicatat di aplikasi.") })
    Spacer(Modifier.height(14.dp))
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End, verticalAlignment = Alignment.CenterVertically) {
        TextButton({ store.show(null) }) { Text("Batal", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }; Spacer(Modifier.width(12.dp))
        Button({ if (code.length in 6..12) store.payEdc(code) else err = true }, Modifier.height(52.dp)) { Text("Simpan pembayaran", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }
    }
}

@Composable
private fun ColumnScope.QrisRecordDialog(store: PosStore) {
    val o = store.active ?: return
    Icon(Icons.Filled.QrCode2, null, Modifier.size(32.dp), tint = MaterialTheme.colorScheme.primary)
    Text("QRIS GoPay (statis)", Modifier.padding(top = 10.dp, bottom = 16.dp), fontSize = 30.sp)
    Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.errorContainer, contentColor = MaterialTheme.colorScheme.onErrorContainer) {
        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) { Icon(Icons.Filled.WarningAmber, null, Modifier.size(22.dp)); Spacer(Modifier.width(12.dp)); Text("Jangan terima bukti dari layar ponsel pelanggan.", fontWeight = FontWeight.Medium, fontSize = 16.sp) }
    }
    Row(Modifier.fillMaxWidth().padding(vertical = 16.dp), verticalAlignment = Alignment.Top) {
        Surface(Modifier.width(210.dp), shape = RoundedCornerShape(14.dp), color = Color.White, border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant)) {
            Column {
                Box(Modifier.fillMaxWidth().background(MaterialTheme.colorScheme.primary).padding(horizontal = 14.dp, vertical = 10.dp)) { Text("QRIS · GoPay", color = MaterialTheme.colorScheme.onPrimary, fontWeight = FontWeight.Bold, fontSize = 15.sp) }
                Icon(Icons.Filled.QrCode2, null, Modifier.padding(10.dp).fillMaxWidth().aspectRatio(1f), tint = Color(0xFF201E1D))
                Text("WARKOP WJM 3 · NMID ID10••••", Modifier.padding(start = 14.dp, end = 14.dp, bottom = 12.dp), fontSize = 12.sp, color = Color(0xFF201E1D))
            }
        }
        Column(Modifier.weight(1f).padding(start = 20.dp, top = 30.dp)) {
            Text("Standee QRIS statis di meja kasir", fontSize = 14.sp)
            Text(rp(o.remaining), Modifier.padding(vertical = 6.dp), fontSize = 40.sp, fontWeight = FontWeight.Light)
            Text("Catat sebagai menunggu verifikasi, lalu cek aplikasi GoPay Merchant.", fontSize = 16.sp)
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
    Text(androidx.compose.ui.text.buildAnnotatedString { append("Cek transaksi "); pushStyle(androidx.compose.ui.text.SpanStyle(fontWeight = FontWeight.Bold)); append(rp(t.amount)); pop(); append(" di aplikasi GoPay Merchant, lalu masukkan referensinya.") }, Modifier.padding(vertical = 14.dp), fontSize = 17.sp)
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
private fun CashOutSheet(store: PosStore) {
    var inflow by remember { mutableStateOf(false) }
    var reason by remember { mutableStateOf<String?>(null) }
    var amount by remember { mutableStateOf("") }
    val value = amount.toIntOrNull() ?: 0
    val reasons = if (inflow) PosCatalog.cashInReasons else PosCatalog.cashOutReasons
    Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding()) {
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(start = 24.dp, end = 24.dp, top = 20.dp)) {
            Title("Kas ${if (inflow) "masuk" else "keluar"}", "Dicatat ke Shift 1 · ${store.user?.name}", { store.show(null) })
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) { FilterChip(inflow, { inflow = true; reason = null }, { Text("Kas masuk") }, shape = RoundedCornerShape(50)); FilterChip(!inflow, { inflow = false; reason = null }, { Text("Kas keluar") }, shape = RoundedCornerShape(50)) }
            SectionLabel("Alasan", Modifier.padding(top = 12.dp, bottom = 2.dp))
            reasons.forEach { r -> RadioRow(r, reason == r, { reason = r }) }
            Text(rp(value), Modifier.fillMaxWidth().padding(vertical = 6.dp), fontSize = 30.sp, textAlign = TextAlign.End, fontWeight = FontWeight.Light)
            if (!inflow && value > PosCatalog.CASH_OUT_THRESHOLD) Text("Di atas Rp 200.000: perlu persetujuan Supervisor.", color = MaterialTheme.colorScheme.error, fontSize = 13.sp, modifier = Modifier.padding(bottom = 6.dp))
            Keypad({ d -> if (amount.length < 9) amount = (amount + d).trimStart('0') }, { amount = amount.dropLast(1) }, Modifier.fillMaxWidth(), extraKey = "000", keyHeight = 40.dp, shape = RoundedCornerShape(12.dp))
            Spacer(Modifier.height(12.dp))
        }
        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
        PrimaryPill("Simpan", { reason?.let { store.cashMove(inflow, it, value) } }, Modifier.fillMaxWidth().padding(horizontal = 24.dp, vertical = 12.dp), enabled = reason != null && value > 0)
    }
}

@Composable
private fun ColumnScope.ReceiptDialog(store: PosStore, s: Sheet.Receipt) {
    val o = store.orders.firstOrNull { it.id == s.orderId } ?: return
    val mono = androidx.compose.ui.text.font.FontFamily.Monospace
    val ink = Color(0xFF201E1D)
    @Composable fun Line(l: String, r: String, bold: Boolean = false, size: Int = 15) {
        Row(Modifier.fillMaxWidth().padding(vertical = 1.dp)) {
            Text(l, Modifier.weight(1f), fontFamily = mono, fontSize = size.sp, fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal, color = ink)
            Text(r, fontFamily = mono, fontSize = size.sp, fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal, color = ink)
        }
    }
    @Composable fun Dash() = Text("- ".repeat(26).trim(), fontFamily = mono, fontSize = 14.sp, color = ink, maxLines = 1, modifier = Modifier.padding(vertical = 6.dp))
    Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(14.dp), color = Color.White) {
        Column(Modifier.padding(horizontal = 20.dp, vertical = 14.dp)) {
            if (o.reprints > 0) Box(Modifier.border(2.dp, ink).padding(horizontal = 12.dp, vertical = 6.dp)) { Text("SALINAN ${o.reprints}", fontFamily = mono, fontWeight = FontWeight.Bold, color = ink, fontSize = 16.sp) }
            Text("WARKOP WJM 3", Modifier.padding(top = 10.dp), fontFamily = mono, fontWeight = FontWeight.Bold, fontSize = 20.sp, color = ink)
            Text("Kab. Bogor", Modifier.padding(top = 6.dp), fontFamily = mono, fontSize = 15.sp, color = ink)
            Dash()
            Text(o.receiptNo ?: "—", fontFamily = mono, fontSize = 15.sp, color = ink, modifier = Modifier.padding(vertical = 1.dp))
            Text("31/10/2026 ${o.closedAt ?: ""} · Kasir ${o.waiter}", fontFamily = mono, fontSize = 15.sp, color = ink, modifier = Modifier.padding(vertical = 3.dp))
            Text(o.label + (o.queue?.let { " · Antrean $it" } ?: ""), fontFamily = mono, fontSize = 15.sp, color = ink, modifier = Modifier.padding(vertical = 3.dp))
            Dash()
            o.live.forEach { l -> Line("${l.qty}× ${l.name}", num(l.net)); if (l.mods.isNotEmpty()) Text("  " + l.modLabel, fontFamily = mono, fontSize = 13.sp, color = Color(0xFF605D5D)) }
            Dash()
            Line("Subtotal", num(o.gross)); if (o.discount > 0) Line("Diskon", "-" + num(o.discount)); Line("Pajak 10%", num(o.tax)); Line("TOTAL", num(o.total), bold = true, size = 17)
            o.tenders.filter { it.status == com.warkop.pos.model.TenderStatus.OK }.forEach { Line(it.method.label + (it.ref?.let { r -> " · $r" } ?: ""), num(it.received ?: it.amount)) }
            val change = o.serverChange ?: 0
            if (change > 0) Line("Kembalian", num(change))
            Text("Terima kasih", Modifier.padding(top = 10.dp), fontFamily = mono, fontSize = 14.sp, color = ink)
        }
    }
    TextButton({ store.show(null) }, Modifier.align(Alignment.End)) { Text("Tutup", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }
}
