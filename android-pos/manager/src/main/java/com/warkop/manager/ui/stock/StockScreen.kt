package com.warkop.manager.ui.stock

import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.SwapHoriz
import androidx.compose.material.icons.outlined.Tune
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.SmallFloatingActionButton
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.createSavedStateHandle
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.ManagerSession
import com.warkop.manager.data.StockRepository
import com.warkop.manager.data.dto.LowStockDto
import com.warkop.manager.data.dto.StockMovementDto
import com.warkop.manager.data.dto.TransferSummaryDto
import com.warkop.manager.domain.MovementTypes
import com.warkop.manager.ui.components.ListDetail
import com.warkop.manager.ui.components.LoadContent
import com.warkop.manager.ui.components.MessageBox
import com.warkop.manager.ui.components.PagedListContent
import com.warkop.manager.ui.container
import com.warkop.manager.ui.managerViewModel
import com.warkop.manager.ui.theme.ManagerColors
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

const val PERM_TRANSFER_DISPATCH = "stock.transfer.dispatch"

/** 094 / T059–T061 — Stok: Riwayat, Stok menipis, Transfer; panel detail = koreksi / transfer baru / detail transfer. */
@Composable
fun StockScreen(session: ManagerSession, onSessionError: (ApiError) -> Boolean) {
    val c = container()
    val outlet = c.session.selectedOutlet() ?: run { MessageBox("Pilih outlet dulu"); return }
    val vm = managerViewModel { cc -> StockViewModel(StockRepository(cc::api), createSavedStateHandle(), onSessionError) }
    val canDispatch = PERM_TRANSFER_DISPATCH in session.permissions
    ListDetail(
        list = { open -> StockList(vm, canDispatch, open) },
        detail = { key, close, _ ->
            when {
                key == "adjust" -> StockAdjustPane(
                    managerViewModel(key = "stock-adjust") { cc -> StockAdjustViewModel(StockRepository(cc::api), createSavedStateHandle(), outlet.id, onSessionError) },
                    outletName = outlet.name, onClose = close, onDone = { vm.refresh(); close() },
                )
                key == "transfer/new" -> NewTransferPane(
                    managerViewModel(key = "stock-transfer-new") { cc -> NewTransferViewModel(StockRepository(cc::api), createSavedStateHandle(), outlet.id, onSessionError) },
                    fromName = outlet.name, onClose = close, onDone = { vm.setTab(StockTab.TRANSFERS); vm.refresh(); close() },
                )
                key.startsWith("transfer/") -> {
                    val id = key.removePrefix("transfer/").toLong()
                    TransferDetailPane(
                        managerViewModel(key = "stock-transfer-$id") { cc -> TransferDetailViewModel(StockRepository(cc::api), createSavedStateHandle(), id, onSessionError) },
                        outletName = outlet.name, onClose = close, onChanged = vm::refresh,
                    )
                }
                else -> MessageBox("Tidak ditemukan")
            }
        },
        placeholder = { MessageBox("Pilih transfer, atau buat koreksi stok", "Koreksi dan transfer berlaku untuk outlet ${outlet.name}.") },
    )
}

@Composable
private fun StockList(vm: StockViewModel, canDispatch: Boolean, open: (String) -> Unit) {
    val tab by vm.tab.collectAsState()
    val multi by vm.multi.collectAsState()
    val tabs = if (multi) StockTab.entries else StockTab.entries - StockTab.TRANSFERS
    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            TabRow(selectedTabIndex = tabs.indexOf(tab).coerceAtLeast(0)) {
                tabs.forEach { t -> Tab(selected = t == tab, onClick = { vm.setTab(t) }, text = { Text(t.label) }) }
            }
            when (tab) {
                StockTab.MOVEMENTS -> Movements(vm)
                StockTab.LOW -> Low(vm)
                StockTab.TRANSFERS -> Transfers(vm, open)
            }
        }
        Column(Modifier.align(Alignment.BottomEnd).padding(16.dp), horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (multi && canDispatch) SmallFloatingActionButton(onClick = { open("transfer/new") }) { Icon(Icons.Outlined.SwapHoriz, "Transfer baru") }
            ExtendedFloatingActionButton(onClick = { open("adjust") }, icon = { Icon(Icons.Outlined.Tune, null) }, text = { Text("Koreksi") })
        }
    }
}

@Composable
private fun Movements(vm: StockViewModel) {
    val type by vm.type.collectAsState()
    val state by vm.movements.collectAsState()
    Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 12.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        FilterChip(selected = type == null, onClick = { vm.setType(null) }, label = { Text("Semua tipe") })
        MovementTypes.LABELS.forEach { (k, label) -> FilterChip(selected = type == k, onClick = { vm.setType(k) }, label = { Text(label) }) }
    }
    PagedListContent(state, onRefresh = vm::refresh, onEnd = vm::loadMoreMovements, empty = "Belum ada pergerakan stok.") { rows ->
        items(rows, key = { it.id }) { MovementRow(it); HorizontalDivider() }
    }
}

@Composable
private fun MovementRow(m: StockMovementDto) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(listOfNotNull(m.productName, m.variantName).joinToString(" — ").ifEmpty { m.sku ?: "—" }, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
            Muted(listOfNotNull(MovementTypes.label(m.type), m.sku, m.outletName, m.reference?.number).joinToString(" · "))
            m.reason?.takeIf { it.isNotBlank() }?.let { Muted("Alasan: $it") }
            Muted(listOfNotNull(m.userName, dateTime(m.createdAt)).joinToString(" · "))
        }
        Column(horizontalAlignment = Alignment.End) {
            val up = m.qtyChange > 0
            Text((if (up) "+" else "") + m.qtyChange, fontWeight = FontWeight.Bold, color = if (up) ManagerColors.success.fg else ManagerColors.danger.fg)
            if (m.stockBefore != null && m.stockAfter != null) Muted("${m.stockBefore} → ${m.stockAfter}")
        }
    }
}

@Composable
private fun Low(vm: StockViewModel) {
    val state by vm.low.collectAsState()
    LoadContent(state, onRetry = vm::refresh) { rows ->
        if (rows.isEmpty()) MessageBox("Tidak ada stok menipis", "Varian tanpa ambang stok menipis tidak pernah muncul di sini.")
        else androidx.compose.foundation.lazy.LazyColumn(Modifier.fillMaxSize()) {
            items(rows, key = { "${it.variantId}-${it.outletName}" }) { LowRow(it); HorizontalDivider() }
        }
    }
}

@Composable
private fun LowRow(r: LowStockDto) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(r.productName ?: r.sku ?: "—", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
            Muted(listOfNotNull(r.sku, r.outletName).joinToString(" · "))
        }
        Column(horizontalAlignment = Alignment.End) {
            Text("${r.currentStock}", fontWeight = FontWeight.Bold, color = ManagerColors.danger.fg)
            r.lowStockAlert?.let { Muted("ambang $it") }
        }
    }
}

private val TRANSFER_STATUS = linkedMapOf("in_transit" to "Dalam perjalanan", "received" to "Diterima", "cancelled" to "Dibatalkan")

@Composable
private fun Transfers(vm: StockViewModel, open: (String) -> Unit) {
    val status by vm.transferStatus.collectAsState()
    val state by vm.transfers.collectAsState()
    Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 12.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        FilterChip(selected = status == null, onClick = { vm.setTransferStatus(null) }, label = { Text("Semua status") })
        TRANSFER_STATUS.forEach { (k, label) -> FilterChip(selected = status == k, onClick = { vm.setTransferStatus(k) }, label = { Text(label) }) }
    }
    PagedListContent(state, onRefresh = vm::refresh, onEnd = vm::loadMoreTransfers, empty = "Belum ada transfer.") { rows ->
        items(rows, key = { it.id }) { TransferRowItem(it) { open("transfer/${it.id}") }; HorizontalDivider() }
    }
}

@Composable
private fun TransferRowItem(t: TransferSummaryDto, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(t.number, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
            Muted("${t.fromOutlet?.name ?: "—"} → ${t.toOutlet?.name ?: "—"} · ${t.lineCount} baris")
            Muted(listOfNotNull(dateTime(t.dispatchedAt), if (t.hasDiscrepancy) "Ada selisih" else null).joinToString(" · "))
        }
        Text(TRANSFER_STATUS[t.status] ?: t.status, style = MaterialTheme.typography.labelMedium, color = if (t.status == "in_transit") ManagerColors.warning.fg else MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
internal fun Muted(text: String) = Text(text, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)

/** "25 Nov 19.30" di zona perangkat. ASSUMPTION: zona perangkat = zona toko. */
internal fun dateTime(iso: String?): String? = iso?.let {
    runCatching { OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("d MMM HH.mm", java.util.Locale("in", "ID"))) }.getOrNull()
}

internal val TRANSFER_STATUS_LABELS: Map<String, String> get() = TRANSFER_STATUS
