package com.warkop.pos.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.rememberScrollState
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
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.*
import com.warkop.pos.state.PosStore
import com.warkop.pos.state.Sheet
import com.warkop.pos.ui.*

/** S06 — editor order: rel kategori + grid menu + panel order (desain 04/05/27/28/35/36, 30 phone). */
@Composable
fun EditorScreen(store: PosStore) {
    val order = store.active ?: return
    val size = windowSize()
    var cat by remember { mutableStateOf(PosCatalog.categories.first().id) }
    var query by remember { mutableStateOf("") }
    var paneOpen by remember { mutableStateOf(false) }
    val items = remember(cat, query) {
        if (query.isNotBlank()) PosCatalog.products.filter { it.name.contains(query, ignoreCase = true) } else PosCatalog.products.filter { it.cat == cat }
    }
    val qtyById = order.live.groupBy { it.itemId }.mapValues { (_, v) -> v.sumOf { it.qty } }

    val menu: @Composable (Modifier) -> Unit = { m ->
        Row(m) {
            if (size == Size.EXPANDED || size == Size.LARGE) CategoryRail(cat, if (size == Size.LARGE) 160.dp else 150.dp) { cat = it; query = "" }
            Column(Modifier.weight(1f)) {
                if (size == Size.COMPACT || size == Size.MEDIUM) {
                    Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(bottom = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        PosCatalog.categories.forEach { c -> FilterChip(c.id == cat && query.isBlank(), { cat = c.id; query = "" }, { Text(c.name) }, shape = RoundedCornerShape(50)) }
                    }
                }
                SearchField(query) { query = it }
                Spacer(Modifier.height(10.dp))
                if (items.isEmpty()) Text("Tidak ada menu \"$query\"", Modifier.padding(24.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
                LazyVerticalGrid(GridCells.Fixed(size.menuColumns()), horizontalArrangement = Arrangement.spacedBy(10.dp), verticalArrangement = Arrangement.spacedBy(10.dp), contentPadding = PaddingValues(bottom = if (size == Size.COMPACT) 88.dp else 8.dp)) {
                    items(items, key = { it.id }) { p -> MenuCard(p, qtyById[p.id] ?: 0, onTap = { store.tapProduct(p) }, onHold = { store.say(if (store.user?.isSupervisor == true) "Ketersediaan ${p.name} diubah." else "Mengubah ketersediaan perlu persetujuan Supervisor.") }) }
                }
            }
        }
    }

    val pane: @Composable (Modifier) -> Unit = { m -> OrderPane(store, m, onBack = if (size == Size.COMPACT) ({ paneOpen = false }) else null) }

    PosShell(store, title = order.label, subtitle = null) {
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
            else -> Row(Modifier.fillMaxSize()) {
                menu(Modifier.weight(1f).fillMaxHeight().padding(end = 12.dp))
                pane(Modifier.width(when (size) { Size.MEDIUM -> 320.dp; Size.EXPANDED -> 400.dp; else -> 420.dp }).fillMaxHeight())
            }
        }
    }
}

@Composable
private fun CategoryRail(selected: String, width: androidx.compose.ui.unit.Dp, onSelect: (String) -> Unit) {
    Column(Modifier.width(width).padding(end = 12.dp)) {
        Text("Kategori", Modifier.padding(start = 16.dp, top = 8.dp, bottom = 8.dp), fontWeight = FontWeight.SemiBold, color = MaterialTheme.colorScheme.onSurfaceVariant)
        PosCatalog.categories.forEach { c ->
            val sel = c.id == selected
            Surface(modifier = Modifier.fillMaxWidth().padding(vertical = 3.dp).height(52.dp), shape = RoundedCornerShape(50), color = if (sel) MaterialTheme.colorScheme.secondaryContainer else androidx.compose.ui.graphics.Color.Transparent, onClick = { onSelect(c.id) }) {
                Box(Modifier.padding(horizontal = 20.dp), contentAlignment = Alignment.CenterStart) { Text(c.name, fontWeight = FontWeight.Medium, fontSize = 17.sp) }
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

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun MenuCard(p: Product, qty: Int, onTap: () -> Unit, onHold: () -> Unit) {
    val cat = PosCatalog.categories.first { it.id == p.cat }
    val index = PosCatalog.products.filter { it.cat == p.cat }.indexOf(p) + 1
    val (tileBg, tileFg) = toneColors(cat.tone)
    val dim = if (p.is86) 0.55f else 1f
    Surface(
        Modifier.fillMaxWidth().combinedClickable(onClick = onTap, onLongClick = onHold).then(if (p.is86) Modifier.hatched(MaterialTheme.colorScheme.outlineVariant) else Modifier),
        shape = RoundedCornerShape(20.dp), color = MaterialTheme.colorScheme.surfaceContainerLow, border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
    ) {
        Column(Modifier.padding(8.dp)) {
            Box(Modifier.fillMaxWidth().aspectRatio(1.15f).clip(RoundedCornerShape(14.dp)).background(tileBg.copy(alpha = dim))) {
                Text("${cat.code}$index", Modifier.padding(10.dp), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, color = tileFg.copy(alpha = dim))
                Icon(productIcon(p.icon), null, Modifier.align(Alignment.Center).size(64.dp), tint = tileFg.copy(alpha = dim))
                if (qty > 0) Box(Modifier.align(Alignment.TopEnd).padding(6.dp).size(30.dp).clip(CircleShape).background(MaterialTheme.colorScheme.primary), contentAlignment = Alignment.Center) { Text(qty.toString(), color = MaterialTheme.colorScheme.onPrimary, fontWeight = FontWeight.Bold, fontSize = 14.sp) }
                if (p.is86) Pill("Habis", MaterialTheme.colorScheme.surfaceContainerLowest, MaterialTheme.colorScheme.onSurfaceVariant, Modifier.align(Alignment.BottomStart).padding(8.dp))
                else if (p.groups.isNotEmpty()) Pill("Pilihan", MaterialTheme.colorScheme.surfaceContainerLowest, MaterialTheme.colorScheme.onSurface, Modifier.align(Alignment.BottomStart).padding(8.dp))
            }
            Spacer(Modifier.height(8.dp))
            Text(p.name, fontWeight = FontWeight.SemiBold, fontSize = 16.sp, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.heightIn(min = 40.dp).padding(horizontal = 4.dp))
            Text(rp(p.price), fontSize = 15.sp, modifier = Modifier.padding(horizontal = 4.dp, vertical = 2.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
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
            HorizontalDivider(Modifier.padding(vertical = 8.dp), color = MaterialTheme.colorScheme.outlineVariant)
            MoneyRow("Subtotal", rp(o.gross))
            if (o.discount > 0) MoneyRow("Diskon ${o.discPct}%", "-" + rp(o.discount))
            MoneyRow("Pajak 10%", rp(o.tax))
            MoneyRow("Total", rp(o.total), big = true)
            if (o.paid > 0) MoneyRow("Terbayar", rp(o.paid))
            Spacer(Modifier.height(10.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                TonalPill("Kirim ${o.unsent.size}", { store.fire() }, Modifier.weight(1f), enabled = o.unsent.isNotEmpty(), icon = Icons.Filled.SoupKitchen, secondary = true)
                PrimaryPill(if (waiter) "Cetak pre-bill" else "Bayar", { store.goPay() }, Modifier.weight(1.4f), enabled = o.live.isNotEmpty(), icon = if (waiter) Icons.Filled.Receipt else Icons.Filled.Payments, trailing = if (waiter) null else rp(o.remaining))
            }
        }
    }
}

@Composable
private fun LineRow(l: OrderLine, store: PosStore) {
    Surface(modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(18.dp), color = MaterialTheme.colorScheme.surfaceContainerLowest, onClick = { store.show(Sheet.LineActions(l.id)) }) {
        Row(Modifier.padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
            if (!l.fired) QtyStepper(l.qty, { store.qtyDelta(l.id, -1) }, { store.qtyDelta(l.id, 1) })
            else Pill("${l.qty}×", MaterialTheme.colorScheme.surfaceContainerHigh, MaterialTheme.colorScheme.onSurface)
            Column(Modifier.weight(1f).padding(horizontal = 10.dp)) {
                Text(l.name, fontWeight = FontWeight.SemiBold, fontSize = 16.sp)
                if (l.mods.isNotEmpty()) Text(l.modLabel, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                if (l.note.isNotBlank()) Text("“${l.note}”", fontSize = 13.sp, fontStyle = FontStyle.Italic, color = MaterialTheme.colorScheme.onSurfaceVariant)
                if (l.discPct > 0) Pill("Diskon ${l.discPct}%", MaterialTheme.colorScheme.primaryContainer, MaterialTheme.colorScheme.onPrimaryContainer)
            }
            if (l.fired) Text("Terkirim ${l.firedAt}", fontSize = 12.sp, fontWeight = FontWeight.Medium, modifier = Modifier.padding(end = 10.dp))
            Text(num(l.net), fontWeight = FontWeight.SemiBold, fontSize = 16.sp, modifier = Modifier.padding(end = 6.dp))
        }
    }
}
