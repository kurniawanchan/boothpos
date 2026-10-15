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
import com.warkop.pos.state.Conn
import com.warkop.pos.state.Dest
import com.warkop.pos.state.PosStore
import com.warkop.pos.theme.pos
import java.util.Calendar

private data class Nav(val dest: Dest, val label: String, val icon: androidx.compose.ui.graphics.vector.ImageVector)

private val NAV = listOf(
    Nav(Dest.TABLES, "Meja", Icons.Filled.TableRestaurant),
    Nav(Dest.EDITOR, "Counter", Icons.Filled.Storefront),
    Nav(Dest.ORDERS, "Order", Icons.Filled.Receipt),
    Nav(Dest.SHIFT, "Shift", Icons.Filled.PointOfSale),
)

/** Rangka aplikasi (rail kiri di layar lebar, bottom nav di compact) — dipakai semua layar kecuali PIN dan buka shift. */
@Composable
fun PosShell(store: PosStore, title: String, subtitle: String? = null, content: @Composable BoxScope.() -> Unit) {
    val size = windowSize()
    val compact = size == Size.COMPACT
    Row(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        if (!compact) Rail(store)
        Column(Modifier.weight(1f).fillMaxHeight()) {
            Header(store, title, subtitle, compact)
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
        Icon(Icons.Filled.Menu, "Menu", Modifier.padding(8.dp))
        Spacer(Modifier.height(8.dp))
        FloatingActionButton(onClick = { store.newCounter() }, containerColor = MaterialTheme.colorScheme.primaryContainer, contentColor = MaterialTheme.colorScheme.onPrimaryContainer) {
            Icon(Icons.Filled.Add, "Order baru")
        }
        Spacer(Modifier.height(16.dp))
        NAV.forEach { n ->
            NavigationRailItem(
                selected = store.dest == n.dest,
                onClick = { if (n.dest == Dest.EDITOR) { if (store.active?.kind == com.warkop.pos.model.OrderKind.COUNTER) store.go(Dest.EDITOR) else store.newCounter() } else store.go(n.dest) },
                icon = {
                    if (n.dest == Dest.ORDERS && openCount > 0) BadgedBox({ Badge { Text(openCount.toString()) } }) { Icon(n.icon, n.label) } else Icon(n.icon, n.label)
                },
                label = { Text(n.label) },
                alwaysShowLabel = true,
            )
        }
        Spacer(Modifier.weight(1f))
        NavigationRailItem(selected = store.dest == Dest.SYNC, onClick = { store.go(Dest.SYNC) }, icon = { Icon(Icons.Filled.TabletAndroid, "Perangkat") }, label = { Text("Perangkat") }, alwaysShowLabel = true)
        Spacer(Modifier.height(12.dp))
    }
}

@Composable
private fun BottomNav(store: PosStore) {
    NavigationBar(containerColor = MaterialTheme.colorScheme.surfaceContainer) {
        NAV.forEach { n ->
            NavigationBarItem(selected = store.dest == n.dest, onClick = { if (n.dest == Dest.EDITOR) store.newCounter() else store.go(n.dest) }, icon = { Icon(n.icon, n.label) }, label = { Text(n.label) })
        }
    }
}

@Composable
private fun Header(store: PosStore, title: String, subtitle: String?, compact: Boolean) {
    val u = store.user
    val shift = store.shift
    Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(title, fontSize = if (compact) 20.sp else 28.sp, fontWeight = FontWeight.Normal, maxLines = 1)
            Text(subtitle ?: (if (compact) "${u?.name} · ${shift?.let { "Shift terbuka" } ?: "Belum ada shift"}" else "${u?.name} (${u?.role}) · Sab, 31 Okt 2026 · ${shift?.let { "Shift 1 sejak ${it.openedAt}" } ?: "Belum ada shift"}"), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1)
        }
        ConnChip(store)
        Spacer(Modifier.width(10.dp))
        Box(Modifier.size(48.dp).clip(CircleShape).background(MaterialTheme.colorScheme.tertiaryContainer).clickable { store.lock() }, contentAlignment = Alignment.Center) {
            Text(u?.initials ?: "", fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.onTertiaryContainer)
        }
    }
}

@Composable
fun ConnChip(store: PosStore) {
    val warn = store.conn != Conn.ONLINE
    Surface(
        shape = RoundedCornerShape(50), color = if (warn) MaterialTheme.pos.warnContainer else Color0,
        border = if (warn) null else androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.outline),
        onClick = { store.conn = when (store.conn) { Conn.ONLINE -> Conn.OFFLINE; Conn.OFFLINE -> Conn.OFFLINE_LIMITED; Conn.OFFLINE_LIMITED -> Conn.ONLINE } },
    ) {
        Row(Modifier.padding(horizontal = 14.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(if (warn) Icons.Filled.WifiOff else Icons.Filled.Wifi, null, Modifier.size(18.dp))
            Spacer(Modifier.width(6.dp))
            Text(store.conn.label, fontWeight = FontWeight.Medium, fontSize = 14.sp)
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
            Text("Perangkat offline lebih dari 4 jam. Order baru perlu persetujuan Outlet Manager dan tunai baru ditolak. EDC tetap bisa dipakai.", fontSize = 13.sp)
        }
    }
}

/** Mode gelap otomatis malam hari (>= 18 atau < 6) seperti prototipe; null = ikuti sistem pada siang hari. */
fun autoDark(): Boolean? { val h = Calendar.getInstance().get(Calendar.HOUR_OF_DAY); return if (h >= 18 || h < 6) true else null }
