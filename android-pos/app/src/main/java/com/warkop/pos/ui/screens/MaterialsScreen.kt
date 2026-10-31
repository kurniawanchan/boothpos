package com.warkop.pos.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.MaterialMove
import com.warkop.pos.model.MaterialStock
import com.warkop.pos.model.ModifierPricing
import com.warkop.pos.state.Load
import com.warkop.pos.state.PosStore
import com.warkop.pos.ui.ErrorState
import com.warkop.pos.ui.Pill
import com.warkop.pos.ui.PosShell
import com.warkop.pos.ui.friendlyLoadError
import com.warkop.pos.ui.isOfflineError

/**
 * 087 lanjutan (2026-11-01) — "Stok Bahan": saldo bahan OUTLET TABLET (hanya baca) dan riwayat satu bahan. Minus
 * ditaruh paling atas dan merah: penjualan tidak pernah ditolak karena bahan, jadi minus = tanda perlu stok opname
 * atau pembelian. Koreksi/pembelian tetap dilakukan di web (menu Bahan/PO), bukan dari tablet.
 */
@Composable
fun MaterialsScreen(store: PosStore) {
    LaunchedEffect(Unit) { store.loadMaterials() }
    var query by remember { mutableStateOf("") }
    val selected = store.materialSelected
    // BUG YANG DITEMUKAN & DIPERBAIKI (2026-11-01): dulu tanpa PosShell → layar penuh tanpa navigasi, tak bisa keluar
    // (dijaga NavShellScanTest). Judul "Stok Bahan" kini dari PosShell.
    PosShell(store, title = "Stok Bahan") {
    BoxWithConstraints(Modifier.fillMaxSize().padding(20.dp)) {
        val wide = maxWidth >= 840.dp
        Row(Modifier.fillMaxSize(), horizontalArrangement = Arrangement.spacedBy(20.dp)) {
            if (wide || selected == null) {
                Column(Modifier.weight(1f).fillMaxHeight()) {
                    Text("Saldo bahan di outlet ini. Minus = perlu stok opname atau pembelian (dilakukan di web).", fontSize = 14.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Spacer(Modifier.height(14.dp))
                    OutlinedTextField(query, { query = it }, Modifier.fillMaxWidth(), placeholder = { Text("Cari nama atau kode bahan") }, leadingIcon = { Icon(Icons.Filled.Search, null) }, singleLine = true, shape = RoundedCornerShape(12.dp))
                    Spacer(Modifier.height(12.dp))
                    when (val l = store.materials) {
                        Load.Idle, Load.Loading -> Box(Modifier.fillMaxWidth().padding(40.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                        is Load.Failed -> ErrorState(friendlyLoadError(l.error), { store.loadMaterials() }, Modifier.fillMaxWidth(), offline = isOfflineError(l.error))
                        is Load.Ready -> {
                            val q = query.trim().lowercase()
                            val rows = l.value.filter { q.isEmpty() || q in it.name.lowercase() || q in it.code.lowercase() }
                            if (rows.isEmpty()) Text(if (l.value.isEmpty()) "Belum ada bahan." else "Tidak ada bahan yang cocok.", Modifier.padding(24.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
                            LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                items(rows, key = { it.id }) { m -> MaterialRow(m, m.id == selected?.id) { store.openMaterial(m) } }
                            }
                        }
                    }
                }
            }
            if (selected != null) MaterialHistory(store, selected, Modifier.weight(1f).fillMaxHeight())
        }
    }
    }
}

@Composable
private fun MaterialRow(m: MaterialStock, on: Boolean, onClick: () -> Unit) {
    Surface(
        onClick = onClick, shape = RoundedCornerShape(12.dp),
        color = if (on) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surfaceContainerLowest,
        border = BorderStroke(1.dp, if (m.negative) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.outlineVariant),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(Modifier.padding(horizontal = 16.dp, vertical = 14.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(m.name, fontSize = 17.sp, fontWeight = FontWeight.SemiBold)
                Text("${m.code} · ${m.kindLabel}", fontSize = 13.sp, fontFamily = FontFamily.Monospace, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            if (m.negative) { Pill("Minus", MaterialTheme.colorScheme.errorContainer, MaterialTheme.colorScheme.onErrorContainer); Spacer(Modifier.width(10.dp)) }
            Text(
                "${ModifierPricing.qty(m.onHand)} ${m.unit}", fontSize = 18.sp, fontWeight = FontWeight.Medium,
                color = if (m.negative) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface,
            )
        }
    }
}

@Composable
private fun MaterialHistory(store: PosStore, m: MaterialStock, modifier: Modifier) {
    Surface(modifier, shape = RoundedCornerShape(16.dp), color = MaterialTheme.colorScheme.surfaceContainerLow) {
        Column(Modifier.padding(18.dp)) {
            Row(verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f)) {
                    Text(m.name, fontSize = 22.sp, fontWeight = FontWeight.Medium)
                    Text("Saldo ${ModifierPricing.qty(m.onHand)} ${m.unit} · riwayat terbaru", fontSize = 14.sp, color = if (m.negative) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant)
                }
                IconButton(store::closeMaterial) { Icon(Icons.Filled.Close, "Tutup") }
            }
            Spacer(Modifier.height(10.dp))
            when (val l = store.materialMoves) {
                Load.Idle, Load.Loading -> Box(Modifier.fillMaxWidth().padding(32.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                is Load.Failed -> ErrorState(friendlyLoadError(l.error), { store.openMaterial(m) }, Modifier.fillMaxWidth(), offline = isOfflineError(l.error))
                is Load.Ready -> if (l.value.isEmpty()) Text("Belum ada pergerakan di outlet ini.", Modifier.padding(16.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
                    else LazyColumn(verticalArrangement = Arrangement.spacedBy(6.dp)) { items(l.value) { MoveRow(it, m.unit) } }
            }
        }
    }
}

@Composable
private fun MoveRow(mv: MaterialMove, unit: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(mv.typeLabel, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
            Text(listOfNotNull(mv.at, mv.note, mv.user).joinToString(" · "), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        Column(horizontalAlignment = Alignment.End) {
            Text("${mv.qtyText} $unit", fontSize = 15.sp, fontWeight = FontWeight.Medium, fontFamily = FontFamily.Monospace)
            Text("${mv.beforeText} → ${mv.afterText}", fontSize = 13.sp, fontFamily = FontFamily.Monospace, color = if (mv.negativeAfter) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
    HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
}
