package com.warkop.pos.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.PosCatalog
import com.warkop.pos.model.rp
import com.warkop.pos.state.Conn
import com.warkop.pos.state.PosStore
import com.warkop.pos.state.Sheet
import com.warkop.pos.theme.pos
import com.warkop.pos.ui.*

/** S05 — denah meja. Status: Tersedia / Terisi / Bill tercetak / Perangkat lain (desain 03-tables, 34 phone). */
@Composable
fun TablesScreen(store: PosStore) {
    var area by remember { mutableStateOf(PosCatalog.areas.keys.first()) }
    val size = windowSize()
    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().padding(bottom = 10.dp), verticalAlignment = Alignment.CenterVertically) {
            PosCatalog.areas.keys.forEach { a ->
                FilterChip(selected = a == area, onClick = { area = a }, label = { Text(a, Modifier.padding(horizontal = 8.dp, vertical = 6.dp), fontWeight = FontWeight.Medium) },
                    shape = RoundedCornerShape(50), modifier = Modifier.padding(end = 10.dp))
            }
            Spacer(Modifier.weight(1f))
            if (size != Size.COMPACT) Text(if (store.conn == Conn.ONLINE) "Status meja dari server · diperbarui saat sinkron" else "Status meja per sinkron terakhir", fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        LazyVerticalGrid(GridCells.Fixed(size.tableColumns()), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            items(PosCatalog.areas.getValue(area)) { t -> TableTile(store, t) }
        }
    }
}

@Composable
private fun TableTile(store: PosStore, table: String) {
    val compact = windowSize() == Size.COMPACT
    val order = store.orderAtTable(table)
    val busy = order != null
    val other = order?.otherDevice != null
    val bg = when { !busy -> MaterialTheme.colorScheme.surfaceContainerLow; other -> MaterialTheme.colorScheme.surfaceContainerHighest; else -> MaterialTheme.pos.occupied }
    val fg = if (busy && !other) MaterialTheme.pos.onOccupied else MaterialTheme.colorScheme.onSurface
    val border = if (order?.billPrinted == true) BorderStroke(3.dp, MaterialTheme.colorScheme.tertiaryContainer) else BorderStroke(2.dp, MaterialTheme.colorScheme.outline)
    Surface(modifier = 
        Modifier.fillMaxWidth().aspectRatio(if (compact) 0.8f else 1f).then(if (other) Modifier.hatched(MaterialTheme.colorScheme.outlineVariant) else Modifier),
        shape = RoundedCornerShape(24.dp), color = bg, contentColor = fg, border = border,
        onClick = { if (order == null) store.show(Sheet.Pax(table)) else store.openOrder(order.id) },
    ) {
        Column(Modifier.padding(if (compact) 12.dp else 16.dp)) {
            Text(table, fontSize = if (compact) 28.sp else 34.sp, fontWeight = FontWeight.Normal)
            Spacer(Modifier.weight(1f))
            if (busy) {
                Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) { repeat(order!!.pax.coerceAtMost(8)) { Box(Modifier.size(9.dp).clip(CircleShape).background(fg)) } }
                Text(if (order!!.billPrinted) "Bill tercetak" else if (other) "Perangkat ${order.otherDevice}" else "Terisi", fontWeight = FontWeight.SemiBold, fontSize = 15.sp)
                Text("${order.pax} tamu", fontSize = 12.sp, maxLines = 1)
                Text(rp(order.total), fontSize = if (compact) 12.sp else 13.sp, maxLines = 1, softWrap = false)
            } else Text("Tersedia", fontWeight = FontWeight.SemiBold, fontSize = 16.sp)
        }
    }
}

