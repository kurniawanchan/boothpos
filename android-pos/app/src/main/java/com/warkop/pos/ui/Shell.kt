package com.warkop.pos.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.Tone
import com.warkop.pos.state.Conn
import com.warkop.pos.state.Dest
import com.warkop.pos.state.PosStore
import com.warkop.pos.theme.pos
import java.util.Calendar

private data class Nav(val dest: Dest, val label: String, val icon: androidx.compose.ui.graphics.vector.ImageVector)

// 084 — urutan dan istilah dari NAV_SPECS (Kasir, Meja, Pesanan, Shift, Koneksi); di sini hanya ikonnya.
private val NAV = NAV_SPECS.map { Nav(it.dest, it.label, navIcon(it.dest)) }

private fun navIcon(d: Dest): androidx.compose.ui.graphics.vector.ImageVector = when (d) {
    Dest.EDITOR -> Icons.Filled.Storefront
    Dest.TABLES -> Icons.Filled.TableRestaurant
    Dest.ORDERS -> Icons.Filled.Receipt
    Dest.SHIFT -> Icons.Filled.PointOfSale
    Dest.MATERIALS -> Icons.Filled.Inventory2
    else -> Icons.Filled.TabletAndroid // Dest.SYNC (Koneksi)
}

/** Rangka aplikasi (rail kiri di layar lebar, bottom nav di compact) — dipakai semua layar kecuali PIN dan buka shift. */
@Composable
fun PosShell(store: PosStore, title: String, subtitle: String? = null, content: @Composable BoxScope.() -> Unit) {
    val size = windowSize()
    val compact = size == Size.COMPACT
    Row(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        if (!compact && store.railVisible) Rail(store)
        Column(Modifier.weight(1f).fillMaxHeight()) {
            Header(store, title, subtitle, compact, showMenuButton = !compact && !store.railVisible)
            Banner(store)
            Box(Modifier.weight(1f).fillMaxWidth().padding(horizontal = if (compact) 12.dp else 16.dp), content = content)
            if (compact) BottomNav(store)
        }
    }
}

@Composable
private fun Rail(store: PosStore) {
    val openCount = store.openOrders.size
    NavigationRail(containerColor = MaterialTheme.colorScheme.background, modifier = Modifier.width(88.dp)) {
        Spacer(Modifier.height(12.dp))
        // 084 — hamburger menyembunyikan sidebar; saat tersembunyi tombol yang sama muncul di header.
        IconButton({ store.toggleRail() }) { Icon(Icons.Filled.Menu, "Sembunyikan menu") }
        Spacer(Modifier.height(8.dp))
        FloatingActionButton(onClick = { store.newCounter() }, containerColor = MaterialTheme.colorScheme.primaryContainer, contentColor = MaterialTheme.colorScheme.onPrimaryContainer) {
            Icon(Icons.Filled.Add, "Pesanan baru")
        }
        Spacer(Modifier.height(16.dp))
        NAV.forEach { n ->
            NavigationRailItem(
                selected = store.dest == n.dest,
                onClick = { if (n.dest == Dest.EDITOR) store.openKasir() else store.go(n.dest) },
                icon = {
                    if (n.dest == Dest.ORDERS && openCount > 0) BadgedBox({ Badge { Text(openCount.toString()) } }) { Icon(n.icon, n.label) } else Icon(n.icon, n.label)
                },
                label = { Text(n.label) },
                alwaysShowLabel = true,
            )
        }
        Spacer(Modifier.height(12.dp))
    }
}

@Composable
private fun BottomNav(store: PosStore) {
    NavigationBar(containerColor = MaterialTheme.colorScheme.surfaceContainer) {
        NAV.forEach { n ->
            NavigationBarItem(selected = store.dest == n.dest, onClick = { if (n.dest == Dest.EDITOR) store.openKasir() else store.go(n.dest) }, icon = { Icon(n.icon, n.label) }, label = { Text(n.label) })
        }
    }
}

@Composable
private fun Header(store: PosStore, title: String, subtitle: String?, compact: Boolean, showMenuButton: Boolean = false) {
    val u = store.user
    val shift = store.shift
    Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        // 084 — sidebar tersembunyi: hamburger yang sama ada di sini supaya bisa ditampilkan lagi.
        if (showMenuButton) IconButton({ store.toggleRail() }) { Icon(Icons.Filled.Menu, "Tampilkan menu") }
        // 077 — logo + nama toko di setiap layar setelah login; di layar sempit logo mengecil dan nama dipersempit.
        // 084 — baris kedua di samping logo (dulu nama outlet yang mengulang nama toko) menjadi "Shift dibuka {jam}".
        StoreMark(store, logoSize = if (compact) 32.dp else 40.dp, maxTextWidth = if (compact) 96.dp else 200.dp, secondLine = shiftOpenedLine(shift?.openedAt))
        Spacer(Modifier.width(if (compact) 10.dp else 16.dp))
        Column(Modifier.weight(1f)) {
            Text(title, fontSize = if (compact) 20.sp else 28.sp, fontWeight = FontWeight.Normal, maxLines = 1)
            subtitle?.let { Text(it, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1) }
        }
        // 084 — jam berdetak (dulu tidak ada di header; jam di layar PIN membeku).
        Text(liveClockText(), Modifier.padding(end = 12.dp), fontSize = if (compact) 15.sp else 18.sp, fontWeight = FontWeight.Medium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        ConnChip(store)
        Spacer(Modifier.width(10.dp))
        // 077 / US7 — foto pengguna dari server; inisial bila tidak ada. Mengetuknya tetap mengunci layar.
        UserAvatar(u, 48.dp, Modifier.clickable { store.lock() }, tone = Tone.ACCENT)
    }
}

/** 084 — "Shift dibuka 08:00", atau "Belum ada shift" bila belum dibuka. */
fun shiftOpenedLine(openedAt: String?): String = openedAt?.takeIf { it.isNotBlank() }?.let { "Shift dibuka $it" } ?: "Belum ada shift"

@Composable
fun ConnChip(store: PosStore) {
    // 054 — UNKNOWN belum tahu apa-apa, jadi tidak diberi nada peringatan (hanya OFFLINE yang diperingatkan).
    val warn = store.conn == Conn.OFFLINE || store.conn == Conn.OFFLINE_LIMITED
    Surface(
        shape = RoundedCornerShape(50), color = if (warn) MaterialTheme.pos.warnContainer else Color0,
        border = if (warn) null else androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.outline),
        onClick = { store.conn = when (store.conn) { Conn.UNKNOWN, Conn.ONLINE -> Conn.OFFLINE; Conn.OFFLINE -> Conn.OFFLINE_LIMITED; Conn.OFFLINE_LIMITED -> Conn.ONLINE } },
    ) {
        Row(Modifier.padding(horizontal = 14.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(if (warn) Icons.Filled.WifiOff else Icons.Filled.Wifi, null, Modifier.size(18.dp))
            Spacer(Modifier.width(6.dp))
            // 084 — label menampilkan nama pengguna yang login (dulu "Online"); offline tetap terlihat lewat ikon dan nada peringatan.
            Text(store.user?.name.orEmpty().ifBlank { store.conn.label }, fontWeight = FontWeight.Medium, fontSize = 14.sp, maxLines = 1, overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis, modifier = Modifier.widthIn(max = 160.dp))
        }
    }
}

private val Color0 = androidx.compose.ui.graphics.Color.Transparent

@Composable
private fun Banner(store: PosStore) {
    if (store.conn != Conn.OFFLINE_LIMITED) return
    Surface(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp), shape = RoundedCornerShape(12.dp), color = MaterialTheme.pos.warnContainer, contentColor = MaterialTheme.pos.onWarnContainer) {
        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Filled.WarningAmber, null)
            Spacer(Modifier.width(8.dp))
            Text("Tablet ini offline lebih dari 4 jam. Pesanan baru perlu persetujuan Outlet Manager dan tunai baru ditolak. EDC tetap bisa dipakai.", fontSize = 13.sp)
        }
    }
}

/** Mode gelap otomatis malam hari (>= 18 atau < 6) seperti prototipe; null = ikuti sistem pada siang hari. */
fun autoDark(): Boolean? { val h = Calendar.getInstance().get(Calendar.HOUR_OF_DAY); return if (h >= 18 || h < 6) true else null }
