package com.warkop.pos.ui.screens

import com.warkop.pos.ui.ErrorState
import com.warkop.pos.ui.friendlyLoadError
import com.warkop.pos.ui.isOfflineError
import com.warkop.pos.state.Load
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.outlined.Receipt
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.*
import com.warkop.pos.state.PosStore
import com.warkop.pos.state.Sheet
import com.warkop.pos.theme.pos
import com.warkop.pos.ui.*

/** S16 — daftar order: Terbuka / Selesai, dengan detail dan aksi struk/refund (desain 19, 20). */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
fun OrdersScreen(store: PosStore) {
    var selected by remember { mutableStateOf<String?>(null) }
    val tab = store.ordersTab
    val open = store.openOrders
    val closed = store.closedOrders
    val compact = windowSize() == Size.COMPACT
    LaunchedEffect(tab) { if (tab == 1) store.loadClosed() }
    PosShell(store, title = "Order") {
        Column(Modifier.fillMaxSize()) {
            ScrollableTabRow(selectedTabIndex = tab, edgePadding = 0.dp, containerColor = androidx.compose.ui.graphics.Color.Transparent) {
                Tab(tab == 0, { store.ordersTab = 0 }, selectedContentColor = MaterialTheme.colorScheme.onSurface, unselectedContentColor = MaterialTheme.colorScheme.onSurface, text = { Text("Terbuka · ${open.size}", fontWeight = if (tab == 0) FontWeight.Bold else FontWeight.Medium) })
                Tab(tab == 1, { store.ordersTab = 1 }, selectedContentColor = MaterialTheme.colorScheme.onSurface, unselectedContentColor = MaterialTheme.colorScheme.onSurface, text = { Text("Selesai · ${closed.size}", fontWeight = if (tab == 1) FontWeight.Bold else FontWeight.Medium) })
            }
            Spacer(Modifier.height(10.dp))
            Row(Modifier.weight(1f)) {
                // T039: kegagalan memuat tampil sebagai ErrorState (bukan toast, bukan daftar kosong).
                val listFailure = if (tab == 1) (store.closedLoad as? Load.Failed)?.error else (store.floor as? Load.Failed)?.error
                if (listFailure != null) {
                    ErrorState(friendlyLoadError(listFailure), { if (tab == 1) store.loadClosed() else store.startFloorPolling() }, Modifier.weight(1f).fillMaxWidth(), offline = isOfflineError(listFailure))
                } else {
                LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(if (tab == 0) open else closed, key = { it.id }) { o ->
                        Surface(modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(18.dp), color = if (tab == 1 && (selected ?: closed.firstOrNull()?.id) == o.id) MaterialTheme.colorScheme.surfaceContainerHigh else if (tab == 1) androidx.compose.ui.graphics.Color.Transparent else MaterialTheme.colorScheme.surfaceContainerLow, onClick = { if (tab == 0) store.openOrder(o.id) else selected = o.id }) {
                            Row(Modifier.padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                                if (tab == 0) { Surface(Modifier.size(52.dp), shape = androidx.compose.foundation.shape.CircleShape, color = MaterialTheme.colorScheme.primaryContainer) { Box(contentAlignment = Alignment.Center) { androidx.compose.material3.Icon(Icons.Outlined.Receipt, null, Modifier.size(26.dp)) } }; Spacer(Modifier.width(16.dp)) }
                                Column(Modifier.weight(1f)) {
                                    Text(if (tab == 1) (o.receiptNo ?: "Dibatalkan") else o.label, fontWeight = FontWeight.SemiBold, fontSize = 16.sp)
                                    Text(if (tab == 0) "${if (o.kind == OrderKind.DINE_IN) "${o.pax} tamu · " else ""}Dibuka ${o.openedAt} · ${o.waiter}${(o.terminalName ?: store.config?.deviceName)?.takeIf { it.isNotBlank() }?.let { " · $it" }.orEmpty()}" else (o.cancelledReason?.let { "Dibatalkan: $it" } ?: "${o.label} · ${o.closedAt}"), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                }
                                if (o.hasPendingQris) Pill("QRIS", MaterialTheme.pos.warnContainer, MaterialTheme.pos.onWarnContainer)
                                if (o.billPrinted && !o.closed) Pill("Bill", MaterialTheme.colorScheme.tertiaryContainer, MaterialTheme.colorScheme.onTertiaryContainer)
                                o.otherDevice?.let { Pill(it, MaterialTheme.colorScheme.surfaceContainerHigh, MaterialTheme.colorScheme.onSurface) }
                                if (o.refunded) Pill("Direfund", MaterialTheme.colorScheme.errorContainer, MaterialTheme.colorScheme.onErrorContainer)
                                Text(if (o.cancelledReason != null) "Batal" else rp(o.total), Modifier.padding(horizontal = 10.dp), fontWeight = FontWeight.SemiBold)
                                if (tab == 0) androidx.compose.material3.Icon(Icons.Filled.ChevronRight, null)
                            }
                        }
                    }
                }
                }
                val sel = closed.firstOrNull { it.id == selected } ?: closed.firstOrNull()
                if (tab == 1 && sel != null && !compact) {
                    Surface(modifier = Modifier.width(430.dp).fillMaxHeight().padding(start = 12.dp), shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
                        Column(Modifier.padding(20.dp)) {
                            Text(sel.receiptNo ?: "Dibatalkan", fontSize = 30.sp)
                            Text(listOfNotNull(sel.label, sel.closedAt?.let { "ditutup $it" }, "cetak ulang ${sel.reprints}×").joinToString(" · "), Modifier.padding(top = 4.dp, bottom = 8.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
                            Spacer(Modifier.height(10.dp))
                            sel.live.forEach { l -> Row(Modifier.fillMaxWidth()) { Text("${l.qty}× ${l.name}", Modifier.weight(1f)); Text(num(l.net)) } }
                            HorizontalDivider(Modifier.padding(vertical = 8.dp))
                            MoneyRow("Total", rp(sel.total), big = true)
                            sel.tenders.filter { it.status == TenderStatus.OK }.forEach { MoneyRow(it.method.label, rp(it.amount)) }
                            if (sel.reprints > 0) Text("cetak ulang ${sel.reprints}×", fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            if (sel.refunded) Text("Direfund · total ${rp(sel.refundTotal ?: 0)} · kembali tunai ${rp(sel.cashReturned ?: 0)}", fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            Spacer(Modifier.weight(1f))
                            // 057 / US4 — dua tombol berdampingan, Refund di barisnya sendiri: labelnya utuh, tidak terpotong.
                            if (sel.cancelledReason == null) Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    OutlinedPill("Lihat struk", { store.show(Sheet.Receipt(sel.id)) }, Modifier.weight(1f))
                                    OutlinedPill("Cetak ulang", { store.reprint(sel.id) }, Modifier.weight(1f))
                                }
                                OutlinedPill(if (sel.refunded) "Sudah direfund" else "Refund", { store.show(Sheet.Refund(sel.id)) }, Modifier.fillMaxWidth(), enabled = !sel.refunded, danger = true)
                            }
                        }
                    }
                }
            }
        }
    }
}
