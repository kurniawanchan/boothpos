package com.warkop.pos.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.data.TaxDisplay
import com.warkop.pos.model.*
import com.warkop.pos.state.Load
import com.warkop.pos.state.ORDER_NOTE_MAX
import com.warkop.pos.state.clampSplit
import com.warkop.pos.state.PosStore
import com.warkop.pos.state.Sheet
import com.warkop.pos.ui.*

/** S06 — editor order: rel kategori + grid menu + panel order (desain 04/05/27/28/35/36, 30 phone). */
@Composable
fun EditorScreen(store: PosStore) {
    val order = store.active ?: return
    val size = windowSize()
    val catalog = store.menu
    // 055 / T018 — pilihan kategori bertahan setelah daftar dimuat ulang; kategori pertama bila pilihan sudah hilang.
    var pickedCat by remember { mutableStateOf("") }
    val cat = keepOrPickCategory(catalog?.categories.orEmpty(), pickedCat)
    var query by remember { mutableStateOf("") }
    var paneOpen by remember { mutableStateOf(false) }
    // 075 — tanpa pencarian: satu kartu per PRODUK di kategori terpilih. Dengan pencarian: daftar VARIAN (nama "Produk — Varian"),
    // supaya varian tertentu bisa dicapai langsung seperti di POS web.
    val entries = remember(cat, query, catalog) {
        if (query.isNotBlank()) emptyList() else catalog?.entries.orEmpty().filter { it.cat == cat }
    }
    val found = remember(query, catalog) {
        if (query.isBlank()) emptyList() else catalog?.products.orEmpty().filter { it.name.contains(query, ignoreCase = true) }
    }
    val shownCount = entries.size + found.size
    val qtyById = order.live.groupBy { it.itemId }.mapValues { (_, v) -> v.sumOf { it.qty } }

    val menu: @Composable (Modifier) -> Unit = { m ->
        Row(m) {
            Column(Modifier.weight(1f)) {
                // 055 / T017 — satu strip horizontal untuk semua ukuran; menggantikan rel vertikal yang terpotong.
                CategoryStrip(catalog?.categories.orEmpty(), cat) { pickedCat = it; query = "" }
                SearchField(query) { query = it }
                Spacer(Modifier.height(10.dp))
                when (val c = store.catalog) {
                    is Load.Failed -> ErrorState(friendlyLoadError(c.error), onRetry = { store.loadMenu() }, offline = isOfflineError(c.error), modifier = Modifier.fillMaxWidth())
                    is Load.Loading, is Load.Idle -> Box(Modifier.fillMaxWidth().padding(32.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                    is Load.Ready -> if (shownCount == 0) Text(if (query.isBlank()) "Belum ada menu di kategori ini." else "Tidak ada menu \"$query\"", Modifier.padding(24.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                LazyVerticalGrid(GridCells.Fixed(size.menuColumns()), horizontalArrangement = Arrangement.spacedBy(10.dp), verticalArrangement = Arrangement.spacedBy(10.dp), contentPadding = PaddingValues(bottom = if (size == Size.COMPACT) 88.dp else 8.dp)) {
                    items(entries, key = { "e${it.id}" }) { e ->
                        val tone = categoryTone(catalog?.categories.orEmpty(), e.cat)
                        ProductCard(
                            e.name, e.categoryName, e.imageUrl, tone, e.variants.sumOf { qtyById[it.id] ?: 0 }, e.isSoldOut(store.soldOut),
                            if (e.isSingle) null else variantCountLabel(e.variants.size), e.hasOptions && e.isSingle, priceLabel(e.minPrice, e.maxPrice), stockLabel(e.stock),
                            onTap = { store.tapEntry(e) }, onHold = if (e.isSingle) ({ store.markSoldOut(e.variants.first()) }) else null,
                        )
                    }
                    items(found, key = { "v${it.id}" }) { p ->
                        ProductCard(
                            p.name, categoryName(catalog?.categories.orEmpty(), p.cat), p.imageUrl, categoryTone(catalog?.categories.orEmpty(), p.cat), qtyById[p.id] ?: 0, p.id in store.soldOut,
                            null, p.groups.isNotEmpty(), rp(p.price), stockLabel(p.stock), onTap = { store.tapProduct(p) }, onHold = { store.markSoldOut(p) },
                        )
                    }
                }
            }
        }
    }

    val pane: @Composable (Modifier) -> Unit = { m -> OrderPane(store, m, onBack = if (size == Size.COMPACT) ({ paneOpen = false }) else null) }

    PosShell(store, title = order.label, subtitle = null) {
        RefreshableScreen(store.refreshing, store::refreshCounter, Modifier.fillMaxSize()) {
        when (size) {
            Size.COMPACT -> if (paneOpen) pane(Modifier.fillMaxSize()) else {
                menu(Modifier.fillMaxSize())
                Surface(modifier = Modifier.align(Alignment.BottomCenter).fillMaxWidth().padding(bottom = 8.dp), shape = RoundedCornerShape(50), color = MaterialTheme.colorScheme.primary, contentColor = MaterialTheme.colorScheme.onPrimary, onClick = { paneOpen = true }) {
                    Row(Modifier.padding(horizontal = 24.dp, vertical = 16.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text("${order.live.sumOf { it.qty }} item · Lihat order", Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
                        Text(rp(order.total), fontWeight = FontWeight.SemiBold)
                    }
                }
            }
            // 055 / T014–T015 — lanskap: produk kiri, order kanan (40 %); lebar sedang: produk di atas, order di bawah.
            Size.MEDIUM -> Column(Modifier.fillMaxSize()) {
                menu(Modifier.weight(1f).fillMaxWidth().padding(bottom = 8.dp))
                pane(Modifier.fillMaxWidth().height(340.dp))
            }
            // 077 / US5 — lebar produk vs order bisa digeser kiri-kanan (pembatas di tengah); dibatasi supaya keduanya tetap terpakai,
            // dan pilihan disimpan per perangkat. Hanya tata letak berdampingan: MEDIUM menumpuk, COMPACT satu panel.
            else -> BoxWithConstraints(Modifier.fillMaxSize()) {
                val totalDp = maxWidth.value
                val totalPx = with(androidx.compose.ui.platform.LocalDensity.current) { maxWidth.toPx() }
                val f = clampSplit(store.splitFraction, totalDp)
                Row(Modifier.fillMaxSize()) {
                    menu(Modifier.weight(f).fillMaxHeight())
                    SplitDivider(
                        onDrag = { dx -> store.dragSplit(dx, totalPx, totalDp) }, onDragEnd = store::commitSplit, onReset = store::resetSplit,
                    )
                    pane(Modifier.weight(1f - f).fillMaxHeight())
                }
            }
        }
        }
    }
}

@Composable
private fun SearchField(value: String, onChange: (String) -> Unit) {
    Surface(Modifier.fillMaxWidth().height(56.dp), shape = RoundedCornerShape(50), color = MaterialTheme.colorScheme.surfaceContainerHigh) {
        Row(Modifier.padding(horizontal = 20.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Filled.Search, null); Spacer(Modifier.width(12.dp))
            androidx.compose.foundation.text.BasicTextField(
                value, onChange, singleLine = true, modifier = Modifier.weight(1f),
                textStyle = androidx.compose.ui.text.TextStyle(color = MaterialTheme.colorScheme.onSurface, fontSize = 17.sp),
                cursorBrush = androidx.compose.ui.graphics.SolidColor(MaterialTheme.colorScheme.primary),
                decorationBox = { inner -> if (value.isEmpty()) Text("Cari menu", color = MaterialTheme.colorScheme.onSurfaceVariant, fontSize = 17.sp); inner() },
            )
        }
    }
}

private fun categoryTone(categories: List<MenuCategory>, id: String): Tone = categories.firstOrNull { it.id == id }?.tone ?: Tone.OUTLINE
private fun categoryName(categories: List<MenuCategory>, id: String): String = categories.firstOrNull { it.id == id }?.name.orEmpty()

/**
 * 075 — kartu grid Counter, satu bentuk untuk entri produk dan hasil pencarian (varian). [variantBadge] terisi hanya
 * untuk produk multi-varian ("N varian"); kartu itu membuka daftar varian, bukan menambah baris.
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun ProductCard(
    name: String, categoryName: String, imageUrl: String?, tone: Tone, qty: Int, soldOut: Boolean, variantBadge: String?,
    hasOptions: Boolean, priceText: String, stockText: String?, onTap: () -> Unit, onHold: (() -> Unit)?,
) {
    val dim = if (soldOut) 0.55f else 1f
    Surface(
        Modifier.fillMaxWidth().combinedClickable(onClick = onTap, onLongClick = onHold).then(if (soldOut) Modifier.hatched(MaterialTheme.colorScheme.outlineVariant) else Modifier),
        shape = RoundedCornerShape(20.dp), color = MaterialTheme.colorScheme.surfaceContainerLow, border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
    ) {
        Column(Modifier.padding(8.dp)) {
            Box(Modifier.fillMaxWidth().aspectRatio(1.15f).clip(RoundedCornerShape(14.dp))) {
                ProductPhoto(imageUrl, name, tone, Modifier.fillMaxSize(), dim = dim, initialsSize = 32.sp)
                if (qty > 0) Box(Modifier.align(Alignment.TopEnd).padding(6.dp).size(30.dp).clip(CircleShape).background(MaterialTheme.colorScheme.primary), contentAlignment = Alignment.Center) { Text(qty.toString(), color = MaterialTheme.colorScheme.onPrimary, fontWeight = FontWeight.Bold, fontSize = 14.sp) }
                // Satu slot pil: Habis > N varian > Pilihan (grup opsi). Lencana varian menggantikan "Pilihan" pada produk multi-varian.
                val pill = when { soldOut -> "Habis"; variantBadge != null -> variantBadge; hasOptions -> "Pilihan"; else -> null }
                if (pill != null) Pill(pill, MaterialTheme.colorScheme.surfaceContainerLowest, if (soldOut) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.onSurface, Modifier.align(Alignment.BottomStart).padding(8.dp))
            }
            Spacer(Modifier.height(8.dp))
            Text(name, fontWeight = FontWeight.SemiBold, fontSize = 16.sp, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.heightIn(min = 40.dp).padding(horizontal = 4.dp))
            if (categoryName.isNotBlank()) Text(categoryName, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(horizontal = 4.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
            // 077 — membungkus hanya sebelum tanda strip rentang (spasi tak-putus di priceLabel); dulu satu baris memotong "Rp 7.000-Rp".
            Text(priceText, fontSize = 15.sp, minLines = priceLines(priceText), maxLines = 2, modifier = Modifier.padding(horizontal = 4.dp, vertical = 2.dp), color = MaterialTheme.colorScheme.onSurface)
            if (stockText != null) Text(stockText, fontSize = 12.sp, maxLines = 1, modifier = Modifier.padding(horizontal = 4.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
fun OrderPane(store: PosStore, modifier: Modifier, onBack: (() -> Unit)? = null) {
    val o = store.active ?: return
    val waiter = store.user?.code == "WTR"
    Surface(modifier, shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
        Column(Modifier.padding(16.dp)) {
            Row(verticalAlignment = Alignment.Top) {
                if (onBack != null) IconButton(onBack) { Icon(Icons.Filled.ArrowBack, "Kembali") }
                Column(Modifier.weight(1f)) {
                    Text(o.label, fontSize = 26.sp)
                    Text(if (o.kind == OrderKind.COUNTER) "Counter · panggil dengan nomor antrean · ${o.waiter}" else "Dine-in · ${o.waiter}", fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                IconButton({ store.show(Sheet.OrderActions) }) { Icon(Icons.Filled.MoreVert, "Aksi order") }
            }
            // 050 / T046 — KOT tab ini yang belum tercetak tetap diperingatkan sampai tercetak atau ditutup dengan alasan (FR-020).
            val kotWaiting = store.printer?.jobs?.collectAsState()?.value.orEmpty().count {
                it.kind == com.warkop.pos.print.PrintKind.KOT && it.tabId == o.id &&
                    (it.status == com.warkop.pos.print.PrintStatus.PENDING || it.status == com.warkop.pos.print.PrintStatus.FAILED)
            }
            if (kotWaiting > 0) {
                Surface(Modifier.fillMaxWidth().padding(top = 8.dp), shape = RoundedCornerShape(12.dp), color = MaterialTheme.colorScheme.errorContainer) {
                    Text("KOT belum tercetak — kirim ulang ke dapur secara manual", Modifier.padding(12.dp), color = MaterialTheme.colorScheme.onErrorContainer, fontSize = 14.sp)
                }
            }
            LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                if (o.live.isEmpty()) item {
                    Column(Modifier.fillMaxWidth().padding(top = 48.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        Icon(Icons.Filled.ShoppingBag, null, Modifier.size(56.dp), tint = MaterialTheme.colorScheme.primary)
                        Spacer(Modifier.height(8.dp)); Text("Ketuk menu untuk menambah item", fontWeight = FontWeight.Medium)
                        Text("Tahan kartu menu untuk menandai habis.", fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
                if (o.unsent.isNotEmpty()) item { SectionLabel("Belum dikirim", Modifier.padding(top = 8.dp, bottom = 2.dp), MaterialTheme.colorScheme.error) }
                items(o.unsent, key = { it.id }) { l -> LineRow(l, store) }
                if (o.sent.isNotEmpty()) item { SectionLabel("Sudah dikirim ke dapur", Modifier.padding(top = 10.dp, bottom = 2.dp), MaterialTheme.colorScheme.onSurfaceVariant) }
                items(o.sent, key = { it.id }) { l -> LineRow(l, store) }
            }
            // 077 / US4 — catatan kasir untuk seluruh order; tersimpan di server, bukan hanya di layar.
            OrderNoteRow(store, o)
            // 055 / T038 — area total bisa disembunyikan; satu kontrol membukanya lagi (FR-006, FR-007).
            if (store.totalsVisible) {
                HorizontalDivider(Modifier.padding(vertical = 8.dp), color = MaterialTheme.colorScheme.outlineVariant)
                MoneyRow("Subtotal", rp(o.gross))
                MoneyRow(discountRowLabel(o.discount, o.discPct, o.discReason), discountRowAmount(o.discount), accent = if (o.discount > 0) MaterialTheme.colorScheme.error else androidx.compose.ui.graphics.Color.Unspecified)
                MoneyRow(taxLine(store.tax), rp(o.tax))
                DashedDivider(Modifier.padding(vertical = 8.dp))
                MoneyRow("Total", rp(o.total), big = true)
                if (o.paid > 0) MoneyRow("Terbayar", rp(o.paid))
                Spacer(Modifier.height(10.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    TonalPill(kirimLabel(o.unsent.size), { store.fire() }, Modifier.weight(1f), enabled = o.unsent.isNotEmpty(), icon = Icons.Filled.SoupKitchen, secondary = true)
                    PrimaryPill(bayarLabel(waiter), { store.goPay() }, Modifier.weight(1.4f), enabled = o.live.isNotEmpty(), icon = if (waiter) Icons.Filled.Receipt else Icons.Filled.Payments)
                }
                TextButton({ store.changeTotalsVisibility(false) }, Modifier.align(Alignment.End)) { Text("Sembunyikan total") }
            } else {
                Row(Modifier.fillMaxWidth().padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text("Total ${rp(o.total)}", Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
                    TextButton({ store.changeTotalsVisibility(true) }) { Text("Tampilkan total") }
                }
            }
        }
    }
}

/** 055 / T039 — baris pajak dari server; tarif ditampilkan hanya bila server memberinya (tanpa angka tertulis di kode). */
private fun taxLine(t: TaxDisplay?): String {
    if (t == null) return "Pajak"
    val rate = t.ratePercent ?: return t.label
    return "${t.label} ${if (rate % 1.0 == 0.0) rate.toLong().toString() else rate.toString()}%"
}

@Composable
private fun LineRow(l: OrderLine, store: PosStore) {
    Surface(modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(18.dp), color = MaterialTheme.colorScheme.surfaceContainerLowest, onClick = { store.show(Sheet.LineActions(l.id)) }) {
        Row(Modifier.padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
            if (!l.fired) QtyStepper(l.qty, { store.qtyDelta(l.id, -1) }, { store.qtyDelta(l.id, 1) })
            else Pill("${l.qty}×", MaterialTheme.colorScheme.surfaceContainerHigh, MaterialTheme.colorScheme.onSurface)
            Column(Modifier.weight(1f).padding(horizontal = 10.dp)) {
                Text(l.name, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                if (l.mods.isNotEmpty()) Text(l.modLabel, fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                if (l.note.isNotBlank()) Text("“${l.note}”", fontSize = 11.sp, fontStyle = FontStyle.Italic, color = MaterialTheme.colorScheme.onSurfaceVariant)
                if (l.discPct > 0) Pill(discountLabel(l.discPct), MaterialTheme.colorScheme.primaryContainer, MaterialTheme.colorScheme.onPrimaryContainer)
            }
            if (l.fired) Text("Terkirim ${l.firedAt}", fontSize = 10.sp, fontWeight = FontWeight.Medium, modifier = Modifier.padding(end = 10.dp))
            Text(num(l.net), fontWeight = FontWeight.SemiBold, fontSize = 14.sp, modifier = Modifier.padding(end = 6.dp))
        }
    }
}

/**
 * 077 / US4 — baris "Catatan order": tertutup menampilkan catatan tersimpan (atau ajakan menambah), terbuka berupa kolom teks dengan
 * hitungan karakter. Teks ketikan TIDAK dibuang bila simpan gagal: baris tetap terbuka sampai server menerima. Order tertutup: baca-saja.
 */
@Composable
private fun OrderNoteRow(store: PosStore, o: Order) {
    var expanded by remember(o.id) { mutableStateOf(false) }
    var text by remember(o.id) { mutableStateOf(o.note) }
    if (!expanded) {
        Surface(
            modifier = Modifier.fillMaxWidth().padding(top = 8.dp), shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.surfaceContainerLowest,
            onClick = { if (!o.closed) { text = o.note; expanded = true } },
        ) {
            Row(Modifier.padding(horizontal = 12.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.EditNote, null, Modifier.size(20.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                Spacer(Modifier.width(8.dp))
                Text(
                    o.note.ifBlank { "Tambah catatan" }, Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis, fontSize = 14.sp,
                    fontStyle = if (o.note.isBlank()) FontStyle.Normal else FontStyle.Italic,
                    color = if (o.note.isBlank()) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.onSurface,
                )
            }
        }
    } else {
        Column(Modifier.fillMaxWidth().padding(top = 8.dp)) {
            OutlinedTextField(
                value = text, onValueChange = { text = it }, modifier = Modifier.fillMaxWidth(), minLines = 2, maxLines = 4,
                label = { Text("Catatan order") }, placeholder = { Text("Catatan untuk seluruh order") },
                isError = text.length > ORDER_NOTE_MAX,
                supportingText = { Text(if (text.length > ORDER_NOTE_MAX) "Catatan maksimal $ORDER_NOTE_MAX karakter." else "${text.length}/$ORDER_NOTE_MAX") },
            )
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                TextButton({ text = o.note; expanded = false }) { Text("Batal") }
                Spacer(Modifier.width(8.dp))
                Button({ store.saveOrderNote(text) { expanded = false } }, enabled = text.length <= ORDER_NOTE_MAX) { Text("Simpan") }
            }
        }
    }
}

/**
 * 077 / US5 — pembatas yang bisa digeser antara area produk dan panel order. Area sentuh 24 dp (jauh lebih lebar dari garis yang
 * terlihat) supaya mudah diseret dengan jari; ketuk dua kali mengembalikan ke 60/40. Tidak ada panggilan ke server.
 */
@Composable
private fun SplitDivider(onDrag: (Float) -> Unit, onDragEnd: () -> Unit, onReset: () -> Unit) {
    val drag by rememberUpdatedState(onDrag)
    val end by rememberUpdatedState(onDragEnd)
    val reset by rememberUpdatedState(onReset)
    Box(
        Modifier.width(24.dp).fillMaxHeight()
            .pointerInput(Unit) { detectHorizontalDragGestures(onDragEnd = { end() }, onDragCancel = { end() }) { change, dx -> change.consume(); drag(dx) } }
            .pointerInput(Unit) { detectTapGestures(onDoubleTap = { reset() }) },
        contentAlignment = Alignment.Center,
    ) {
        Box(Modifier.width(4.dp).height(56.dp).clip(RoundedCornerShape(50)).background(MaterialTheme.colorScheme.outline))
    }
}
