package com.warkop.pos.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.FloorTable
import com.warkop.pos.model.rp
import com.warkop.pos.state.Conn
import com.warkop.pos.state.Load
import com.warkop.pos.state.PosStore
import com.warkop.pos.state.Sheet
import com.warkop.pos.theme.pos
import com.warkop.pos.ui.ErrorState
import com.warkop.pos.ui.friendlyLoadError
import com.warkop.pos.ui.isOfflineError
import com.warkop.pos.ui.Size
import com.warkop.pos.ui.hatched
import com.warkop.pos.ui.tableColumns
import com.warkop.pos.ui.windowSize

/** S05 — denah meja dari server (GET /floor, diperbarui tiap 3 dtk). Status: tersedia / terisi / bill tercetak / perangkat lain. */
@Composable
fun TablesScreen(store: PosStore) {
    val size = windowSize()
    when (val f = store.floor) {
        is Load.Idle, is Load.Loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
        is Load.Failed -> ErrorState(friendlyLoadError(f.error), onRetry = { store.startFloorPolling() }, offline = isOfflineError(f.error), modifier = Modifier.fillMaxWidth().padding(top = 24.dp))
        is Load.Ready -> FloorBoard(store, f.value.tables, size)
    }
}

@Composable
private fun FloorBoard(store: PosStore, tables: List<FloorTable>, size: Size) {
    val areas = tables.map { it.area.ifBlank { "Lainnya" } }.distinct()
    var area by remember(areas) { mutableStateOf(areas.firstOrNull().orEmpty()) }
    val visible = tables.filter { it.area.ifBlank { "Lainnya" } == area }
    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().padding(bottom = 10.dp), verticalAlignment = Alignment.CenterVertically) {
            areas.forEach { a ->
                FilterChip(
                    selected = a == area, onClick = { area = a },
                    label = { Text(a, Modifier.padding(horizontal = 8.dp, vertical = 6.dp), fontWeight = FontWeight.Medium) },
                    shape = RoundedCornerShape(50), modifier = Modifier.padding(end = 10.dp),
                )
            }
            Spacer(Modifier.weight(1f))
            if (size != Size.COMPACT) {
                Text(
                    if (store.conn == Conn.ONLINE) "Denah dari server · diperbarui tiap 3 dtk" else "Denah terakhir · server tidak terjangkau",
                    fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
        LazyVerticalGrid(
            GridCells.Fixed(size.tableColumns()),
            horizontalArrangement = Arrangement.spacedBy(12.dp), verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            items(visible, key = { it.id }) { t -> TableTile(store, t) }
        }
    }
}

@Composable
private fun TableTile(store: PosStore, t: FloorTable) {
    val compact = windowSize() == Size.COMPACT
    val tab = t.tab
    val busy = tab != null
    val other = tab?.otherDevice != null
    val bg = when {
        !busy -> MaterialTheme.colorScheme.surfaceContainerLow
        other -> MaterialTheme.colorScheme.surfaceContainerHighest
        else -> MaterialTheme.pos.occupied
    }
    val fg = if (busy && !other) MaterialTheme.pos.onOccupied else MaterialTheme.colorScheme.onSurface
    val border = if (tab?.billPrinted == true) BorderStroke(3.dp, MaterialTheme.colorScheme.tertiaryContainer) else BorderStroke(2.dp, MaterialTheme.colorScheme.outline)
    Surface(
        modifier = Modifier.fillMaxWidth().aspectRatio(if (compact) 0.8f else 1f).then(if (other) Modifier.hatched(MaterialTheme.colorScheme.outlineVariant) else Modifier),
        shape = RoundedCornerShape(24.dp), color = bg, contentColor = fg, border = border,
        onClick = { if (tab == null) store.show(Sheet.Pax(t.name)) else store.openOrder(tab.id.toString()) },
    ) {
        Column(Modifier.padding(if (compact) 12.dp else 16.dp)) {
            Text(t.name, fontSize = if (compact) 28.sp else 34.sp)
            Spacer(Modifier.weight(1f))
            if (tab != null) {
                Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    repeat(tab.pax.coerceIn(0, 8)) { Box(Modifier.size(9.dp).clip(CircleShape).background(fg)) }
                }
                Text(
                    if (tab.billPrinted) "Bill tercetak" else if (other) "Perangkat ${tab.otherDevice}" else "Terisi",
                    fontWeight = FontWeight.SemiBold, fontSize = 15.sp,
                )
                Text("${tab.pax} tamu", fontSize = 12.sp, maxLines = 1)
                Text(rp(tab.total), fontSize = if (compact) 12.sp else 13.sp, maxLines = 1, softWrap = false)
            } else {
                Text("Tersedia", fontWeight = FontWeight.SemiBold, fontSize = 16.sp)
            }
        }
    }
}
