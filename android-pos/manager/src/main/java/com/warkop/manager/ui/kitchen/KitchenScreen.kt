package com.warkop.manager.ui.kitchen

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedCard
import androidx.compose.material3.ScrollableTabRow
import androidx.compose.material3.Tab
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.createSavedStateHandle
import androidx.lifecycle.repeatOnLifecycle
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.ManagerSession
import com.warkop.manager.data.dto.TicketDto
import com.warkop.manager.domain.KitchenRules
import com.warkop.manager.ui.components.LoadingBox
import com.warkop.manager.ui.components.MessageBox
import com.warkop.manager.ui.managerViewModel
import com.warkop.manager.ui.theme.ManagerColors
import kotlinx.coroutines.delay
import java.time.Instant

/** 094 / T088 — Dapur & Bar, cermin `KitchenView.vue`/`StationBoard.vue`/`TicketCard.vue` (+ catatan order). */
@Composable
fun KitchenScreen(session: ManagerSession, onSessionError: (ApiError) -> Boolean) {
    val vm = managerViewModel { c -> KitchenViewModel(c::api, createSavedStateHandle(), onSessionError) }
    val s by vm.state.collectAsState()
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    LaunchedEffect(vm, s.stationId) { if (s.stationId != null) lifecycle.repeatOnLifecycle(Lifecycle.State.RESUMED) { vm.pollLoop() } }
    // Jam menunggu dihitung ulang tiap 15 dtk (web `useNow`).
    var now by remember { mutableStateOf(Instant.now()) }
    LaunchedEffect(Unit) { while (true) { delay(15_000); now = Instant.now() } }

    val stations = s.stations ?: run { LoadingBox(); return }
    if (stations.isEmpty()) { MessageBox("Belum ada stasiun", "Tambahkan di Pengaturan Restoran."); return }
    Column(Modifier.fillMaxSize()) {
        ScrollableTabRow(selectedTabIndex = stations.indexOfFirst { it.id == s.stationId }.coerceAtLeast(0), edgePadding = 8.dp) {
            stations.forEach { st -> Tab(selected = st.id == s.stationId, onClick = { vm.selectStation(st.id) }, text = { Text(st.name) }) }
        }
        s.loadError?.let { Banner(it, ManagerColors.warning.fg, ManagerColors.warning.bg) }
        s.actionError?.let { Banner(it, ManagerColors.danger.fg, ManagerColors.danger.bg) }
        val tickets = s.tickets ?: run { LoadingBox(); return@Column }
        val (active, recent) = tickets.partition(KitchenRules::isActive)
        LazyVerticalGrid(GridCells.Adaptive(300.dp), contentPadding = PaddingValues(12.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.fillMaxSize()) {
            item(span = { GridItemSpan(maxLineSpan) }) { Section("Sedang berjalan") }
            if (active.isEmpty()) item(span = { GridItemSpan(maxLineSpan) }) { Text("Tidak ada tiket yang sedang berjalan.", color = MaterialTheme.colorScheme.onSurfaceVariant) }
            items(active, key = { it.id }) { TicketCard(it, now, session.menuKeys, s.busy, vm::advance, dim = false) }
            if (recent.isNotEmpty()) {
                item(span = { GridItemSpan(maxLineSpan) }) { Section("Baru selesai") }
                items(recent, key = { "r${it.id}" }) { TicketCard(it, now, session.menuKeys, s.busy, vm::advance, dim = true) }
            }
        }
    }
}

@Composable private fun Section(t: String) = Text(t, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 4.dp))

@Composable
private fun Banner(text: String, fg: androidx.compose.ui.graphics.Color, bg: androidx.compose.ui.graphics.Color) =
    Text(text, color = fg, style = MaterialTheme.typography.bodySmall, modifier = Modifier.fillMaxWidth().background(bg).padding(horizontal = 16.dp, vertical = 8.dp))

@Composable
private fun Pill(text: String, fg: androidx.compose.ui.graphics.Color, bg: androidx.compose.ui.graphics.Color) =
    Text(text, color = fg, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, modifier = Modifier.background(bg, RoundedCornerShape(6.dp)).padding(horizontal = 6.dp, vertical = 2.dp))

@Composable
private fun TicketCard(t: TicketDto, now: Instant, menuKeys: Set<String>, busy: Boolean, advance: (Long, String) -> Unit, dim: Boolean) {
    val late = !dim && KitchenRules.isLate(t, now)
    val neutralFg = MaterialTheme.colorScheme.onSurfaceVariant
    val neutralBg = MaterialTheme.colorScheme.surfaceVariant
    OutlinedCard(
        Modifier.fillMaxWidth().alpha(if (dim) 0.8f else 1f),
        border = if (late) BorderStroke(3.dp, ManagerColors.warning.fg) else androidx.compose.material3.CardDefaults.outlinedCardBorder(),
    ) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(KitchenRules.place(t), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                KitchenRules.waitingMinutes(t, now)?.let { Pill("$it mnt", if (late) ManagerColors.warning.fg else neutralFg, if (late) ManagerColors.warning.bg else neutralBg) }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                Text("${t.order?.orderNumber ?: "—"} · Ronde ${t.roundNo}", style = MaterialTheme.typography.bodySmall, color = neutralFg)
                t.serviceType?.let { Pill(if (it == "takeaway") "Take Away" else "Dine-In", neutralFg, neutralBg) }
                if (t.selfService) Pill("Mandiri", ManagerColors.success.fg, ManagerColors.success.bg)
            }
            t.orderNote?.takeIf { it.isNotBlank() }?.let { Text("CATATAN ORDER: $it", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = ManagerColors.warning.fg) }
            t.items.forEach { item ->
                HorizontalDivider()
                val cancelled = item.status == "cancelled"
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("${item.qty ?: 1}× ${item.name}", fontWeight = FontWeight.SemiBold, textDecoration = if (cancelled) TextDecoration.LineThrough else null, color = if (cancelled) ManagerColors.danger.fg else MaterialTheme.colorScheme.onSurface)
                        item.modifiers.mapNotNull { it.label ?: it.option }.takeIf { it.isNotEmpty() }?.let { Text(it.joinToString(", "), style = MaterialTheme.typography.bodySmall, color = neutralFg) }
                        item.note?.takeIf { it.isNotBlank() }?.let { Text("“$it”", style = MaterialTheme.typography.bodySmall, fontStyle = FontStyle.Italic, color = ManagerColors.warning.fg) }
                        if (cancelled) Text("Dibatalkan${item.cancelReason?.let { " — $it" } ?: ""}", style = MaterialTheme.typography.bodySmall, color = ManagerColors.danger.fg)
                    }
                    val (fg, bg) = when (item.status) {
                        "preparing" -> ManagerColors.warning.fg to ManagerColors.warning.bg
                        "ready" -> ManagerColors.success.fg to ManagerColors.success.bg
                        "cancelled" -> ManagerColors.danger.fg to ManagerColors.danger.bg
                        else -> neutralFg to neutralBg
                    }
                    Pill(KitchenRules.STATUS[item.status] ?: item.status, fg, bg)
                }
                if (!dim) KitchenRules.nextAllowed(item.status, menuKeys)?.let { next ->
                    Button(onClick = { advance(item.id, next) }, enabled = !busy, modifier = Modifier.fillMaxWidth()) { Text(KitchenRules.ACTION[next] ?: next) }
                }
            }
        }
    }
}
