package com.warkop.manager.ui.dashboard

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyGridScope
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.ExperimentalMaterialApi
import androidx.compose.material.pullrefresh.PullRefreshIndicator
import androidx.compose.material.pullrefresh.pullRefresh
import androidx.compose.material.pullrefresh.rememberPullRefreshState
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedCard
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.warkop.manager.data.MaterialStats
import com.warkop.manager.data.dto.AttentionItemDto
import com.warkop.manager.data.dto.DashboardOrderRowDto
import com.warkop.manager.data.dto.ShiftRowDto
import com.warkop.manager.domain.DashboardModel
import com.warkop.manager.domain.Dest
import com.warkop.manager.domain.KpiCard
import com.warkop.manager.domain.MoneyField
import com.warkop.manager.domain.Period
import com.warkop.manager.domain.Tone
import com.warkop.manager.ui.components.Load
import com.warkop.manager.ui.theme.ManagerColors
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/**
 * 094 / T053 — Dashboard, cermin `DashboardView.vue`: angka utama, bahan & stok, perlu tindakan, shift terbuka, order
 * terbaru. Ekspor CSV TIDAK ada di aplikasi (kontrak B1). Kartu bahan/stok membuka layar Stok/Bahan di aplikasi
 * (web membuka Laporan → Bahan, yang tidak ada di aplikasi manajemen).
 */
@OptIn(ExperimentalMaterialApi::class)
@Composable
fun DashboardScreen(vm: DashboardViewModel, menuKeys: Set<String>, open: (Dest) -> Unit) {
    val s by vm.state.collectAsState()
    val pull = rememberPullRefreshState(s.refreshing, vm::refresh)
    Box(Modifier.fillMaxSize().pullRefresh(pull)) {
        LazyVerticalGrid(
            columns = GridCells.Adaptive(minSize = 168.dp),
            contentPadding = PaddingValues(16.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
            modifier = Modifier.fillMaxSize(),
        ) {
            full { PeriodPicker(s.period, vm::setPeriod, (s.summary as? Load.Ready)?.data) }
            region(s.summary, vm::refresh, "Gagal memuat angka utama Dashboard") { summary ->
                if (summary.cards.isEmpty()) full { Muted("Belum ada angka untuk ditampilkan.") }
                summary.cards.forEach { card -> item(key = "kpi-${card.key}") { KpiTile(card) } }
            }
            full { SectionTitle("Bahan & stok", (s.materials as? Load.Ready)?.data?.let { "Laju ${it.dto.params.windowDays} hari terakhir · akan habis bila < ${it.dto.params.riskDays} hari" }) }
            region(s.materials, vm::refresh, "Gagal memuat statistik bahan") { m ->
                materialTiles(m, canOpenMaterials = "materials" in menuKeys, canOpenStock = "stock" in menuKeys, open = open)
            }
            full { SectionTitle("Perlu tindakan", (s.attention as? Load.Ready)?.data?.let { "${it.count} hal perlu tindakan" }) }
            region(s.attention, vm::refresh, "Gagal memuat daftar tindakan") { a ->
                full { Column(verticalArrangement = Arrangement.spacedBy(8.dp)) { a.items.forEach { AttentionRow(it) } } }
            }
            full { SectionTitle("Shift terbuka", (s.shifts as? Load.Ready)?.data?.let { "${it.totalOpen} terbuka" }) }
            region(s.shifts, vm::refresh, "Gagal memuat shift") { sh ->
                if (sh.data.isEmpty()) full { Muted("Tidak ada shift yang sedang terbuka.") }
                else full { OutlinedCard { sh.data.forEachIndexed { i, row -> if (i > 0) HorizontalDivider(); ShiftRow(row) } } }
            }
            full { SectionTitle("Order terbaru", (s.orders as? Load.Ready)?.data?.let { "${it.counts.all} order" }) }
            region(s.orders, vm::refresh, "Gagal memuat order") { o ->
                if (o.data.isEmpty()) full { Muted("Belum ada order pada periode ini.") }
                else full { OutlinedCard { o.data.forEachIndexed { i, row -> if (i > 0) HorizontalDivider(); OrderRow(row) } } }
            }
        }
        PullRefreshIndicator(s.refreshing, pull, Modifier.align(Alignment.TopCenter))
    }
}

private fun LazyGridScope.full(content: @Composable () -> Unit) = item(span = { GridItemSpan(maxLineSpan) }) { content() }

/** Wilayah dengan keadaannya sendiri: galat satu wilayah tidak mengosongkan wilayah lain. */
private fun <T> LazyGridScope.region(state: Load<T>, retry: () -> Unit, failText: String, ready: LazyGridScope.(T) -> Unit) {
    when (state) {
        Load.Loading -> full { Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() } }
        is Load.Failed -> full {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text("$failText. ${state.message}", color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                TextButton(onClick = retry) { Text("Coba lagi") }
            }
        }
        is Load.Ready -> ready(state.data)
    }
}

@Composable
private fun PeriodPicker(current: Period, onPick: (Period) -> Unit, summary: Summary?) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
            Period.entries.forEachIndexed { i, p ->
                SegmentedButton(selected = p == current, onClick = { onPick(p) }, shape = SegmentedButtonDefaults.itemShape(i, Period.entries.size)) { Text(p.label) }
            }
        }
        val meta = listOfNotNull(summary?.businessDate?.let { "$it · tanggal bisnis" }, clock(summary?.computedAt)?.let { "Diperbarui $it" })
        if (meta.isNotEmpty()) Muted(meta.joinToString(" · "))
    }
}

@Composable
private fun KpiTile(card: KpiCard) {
    OutlinedCard(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(card.label, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(card.value, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Chip(card.change.text, card.change.tone)
            Text(if (card.orgScope) "${card.note} · Seluruh organisasi" else card.note, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun Chip(text: String, tone: Tone) {
    val (fg, bg) = when (tone) {
        Tone.GOOD -> ManagerColors.success.fg to ManagerColors.success.bg
        Tone.BAD -> ManagerColors.danger.fg to ManagerColors.danger.bg
        Tone.NEUTRAL -> MaterialTheme.colorScheme.onSurfaceVariant to MaterialTheme.colorScheme.surfaceVariant
    }
    Text(text, color = fg, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, modifier = Modifier.background(bg, RoundedCornerShape(6.dp)).padding(horizontal = 6.dp, vertical = 2.dp))
}

private fun LazyGridScope.materialTiles(m: MaterialStats, canOpenMaterials: Boolean, canOpenStock: Boolean, open: (Dest) -> Unit) {
    val c = m.dto.counts
    val toMaterials = if (canOpenMaterials) ({ open(Dest.MATERIALS) }) else null
    stat("mat-negative", "Minus", DashboardModel.count(c.negative.toLong()), ManagerColors.danger.fg, toMaterials)
    stat("mat-out", "Habis", DashboardModel.count(c.out.toLong()), ManagerColors.danger.fg, toMaterials)
    stat("mat-low", "Akan habis", DashboardModel.count(c.low.toLong()), ManagerColors.warning.fg, toMaterials)
    stat("mat-risk", "Menu terancam", DashboardModel.count(c.atRiskProducts.toLong()), null, toMaterials)
    stat("mat-lowprod", "Produk stok menipis", DashboardModel.count(m.dto.lowStockProducts.toLong()), null, if (canOpenStock) ({ open(Dest.STOCK) }) else null)
    // Rupiah hanya bila server mengirim kuncinya (menu `reports`); null = tak terhitung → "—", sama dengan web.
    listOf("mat-value" to ("Nilai persediaan" to m.stockValue), "mat-waste" to ("Bahan terbuang (periode)" to m.wastedValue)).forEach { (key, pair) ->
        when (val f = pair.second) {
            MoneyField.Hidden -> Unit
            MoneyField.Unknown -> stat(key, pair.first, "—", null, null)
            is MoneyField.Shown -> stat(key, pair.first, f.text, null, null)
        }
    }
}

private fun LazyGridScope.stat(key: String, label: String, value: String, tint: Color?, onClick: (() -> Unit)?) = item(key = key) {
    OutlinedCard(Modifier.fillMaxWidth().then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(label, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(value, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.ExtraBold, color = tint ?: MaterialTheme.colorScheme.onSurface)
        }
    }
}

private val ATTENTION = mapOf(
    "payments_pending" to ("Pembayaran menunggu verifikasi" to "Semua pembayaran terverifikasi"),
    "void_after_fire" to ("Void setelah fire" to "Tidak ada void setelah fire"),
    "cash_difference" to ("Selisih kas shift" to "Semua shift dalam toleransi"),
    "low_stock" to ("Item stok menipis" to "Semua stok aman"),
)

@Composable
private fun AttentionRow(item: AttentionItemDto) {
    val (label, okLabel) = ATTENTION[item.kind] ?: (item.kind to item.kind)
    val ok = item.severity == "ok"
    val tone = when (item.severity) { "critical" -> ManagerColors.danger; "warn" -> ManagerColors.warning; else -> ManagerColors.success }
    val unit = if (item.kind == "cash_difference") "shift" else "item"
    OutlinedCard(Modifier.fillMaxWidth()) {
        Row(Modifier.padding(horizontal = 14.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.background(tone.fg, RoundedCornerShape(50)).padding(5.dp))
            Column(Modifier.weight(1f)) {
                Text(if (ok) okLabel else label, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                if (!ok) {
                    val detail = listOfNotNull("${item.count} $unit", item.amount?.let(DashboardModel::rupiah), item.amountUnknownCount?.takeIf { it > 0 }?.let { "$it item tanpa nilai tercatat" })
                    Muted(detail.joinToString(" · "))
                }
            }
            Text(when (item.severity) { "critical" -> "Kritis"; "warn" -> "Perlu perhatian"; else -> "Aman" }, color = tone.fg, style = MaterialTheme.typography.labelSmall)
        }
    }
}

@Composable
private fun ShiftRow(row: ShiftRowDto) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(row.cashier?.name ?: "—", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
            val source = if (row.source == "app") row.terminalName ?: "Aplikasi" else "Web"
            Muted(listOfNotNull(source, row.outlet?.name, clock(row.openedAt)?.let { "sejak $it" }).joinToString(" · "))
        }
        Column(horizontalAlignment = Alignment.End) {
            Text(DashboardModel.rupiah(row.salesTotal), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
            Muted("${row.orderCount} struk")
        }
    }
}

private val TAB_LABEL = mapOf("open" to "Terbuka", "partial" to "Dibayar sebagian", "paid" to "Lunas", "cancelled" to "Dibatalkan")

@Composable
private fun OrderRow(row: DashboardOrderRowDto) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(listOfNotNull(row.orderNumber, row.table).joinToString(" · "), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
            Muted(row.itemsSummary.ifBlank { "—" }, maxLines = 1)
        }
        Column(horizontalAlignment = Alignment.End) {
            Text(DashboardModel.rupiah(row.totalAmount), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
            Muted(listOfNotNull(TAB_LABEL[row.tab], clock(row.at)).joinToString(" · "))
        }
    }
}

@Composable
private fun SectionTitle(title: String, meta: String?) {
    Column(Modifier.padding(top = 8.dp)) {
        Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.ExtraBold)
        meta?.let { Muted(it) }
    }
}

@Composable
private fun Muted(text: String, maxLines: Int = Int.MAX_VALUE) =
    Text(text, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = maxLines, overflow = TextOverflow.Ellipsis)

/** "HH.mm" waktu perangkat (format jam desain web). ASSUMPTION: zona perangkat = zona toko; web memakai zona toko. */
private fun clock(iso: String?): String? = iso?.let {
    runCatching { OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("HH.mm")) }.getOrNull()
}
