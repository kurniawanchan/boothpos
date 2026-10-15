package com.warkop.pos.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.PosCatalog
import com.warkop.pos.model.num
import com.warkop.pos.model.rp
import com.warkop.pos.state.Dest
import com.warkop.pos.state.PosStore
import com.warkop.pos.state.Sheet
import com.warkop.pos.ui.*

/** S03 — modal awal dihitung per pecahan (desain 02-open-shift, 29 phone). */
@Composable
fun OpenShiftScreen(store: PosStore) {
    val counts = remember { mutableStateMapOf<Int, Int>() }
    val total = PosCatalog.denominations.sumOf { it * (counts[it] ?: 0) }
    val compact = windowSize() == Size.COMPACT

    fun open() {
        if (total == 0) store.show(Sheet.Confirm("Buka shift dengan modal Rp 0?", "Pastikan memang tidak ada uang kembalian di kasir.", "Buka shift") { store.openShift(0) })
        else store.openShift(total)
    }

    @Composable
    fun DenomRow(d: Int, modifier: Modifier) {
        val c = counts[d] ?: 0
        Column(modifier) {
            Row(Modifier.fillMaxWidth().padding(vertical = 14.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(if (d >= 1000) "Rp ${num(d)}" else "Koin Rp ${num(d)}", Modifier.weight(1f), fontSize = 17.sp, maxLines = 1, softWrap = false)
                CircleStepper(c, { if (c > 0) counts[d] = c - 1 }, { counts[d] = c + 1 })
                Text(rp(d * c), Modifier.width(92.dp), fontSize = 15.sp, textAlign = TextAlign.End, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
        }
    }

    val left: @Composable (Modifier) -> Unit = { m ->
        // Di compact, pembungkus luar yang menggulir; menggulir lagi di sini membuat tinggi tak terhingga (crash).
        Column(if (compact) m else m.verticalScroll(rememberScrollState())) {
            Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(24.dp), color = MaterialTheme.colorScheme.primaryContainer, contentColor = MaterialTheme.colorScheme.onPrimaryContainer) {
                Row(Modifier.padding(24.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Filled.Schedule, null, Modifier.size(32.dp)); Spacer(Modifier.width(18.dp))
                    Column { Text("Shift 1 · 07:00 – 15:00", fontSize = 20.sp, fontWeight = FontWeight.SemiBold); Text("Diusulkan dari jadwal outlet hari Sabtu", fontSize = 14.sp) }
                }
            }
            SectionLabel("Hitung modal per pecahan", Modifier.padding(start = 6.dp, top = 22.dp, bottom = 10.dp))
            Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(24.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
                if (compact) Column(Modifier.padding(horizontal = 16.dp)) { PosCatalog.denominations.forEach { DenomRow(it, Modifier.fillMaxWidth()) } }
                else Column(Modifier.padding(horizontal = 24.dp, vertical = 8.dp)) {
                    PosCatalog.denominations.chunked(2).forEach { pair -> Row { pair.forEach { DenomRow(it, Modifier.weight(1f).padding(horizontal = 12.dp)) } } }
                }
            }
        }
    }
    val right: @Composable (Modifier) -> Unit = { m ->
        Surface(m, shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
            Column(Modifier.padding(28.dp)) {
                Text("Total modal", color = MaterialTheme.colorScheme.onSurfaceVariant)
                Text(num(total), fontSize = 76.sp, fontWeight = FontWeight.Light, modifier = Modifier.padding(top = 12.dp, bottom = 6.dp))
                Text("Shift bisa dibuka saat offline. Pembayaran hanya bisa diterima setelah shift terbuka.", fontSize = 16.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, lineHeight = 24.sp)
                Spacer(Modifier.weight(1f, fill = !compact)); Spacer(Modifier.height(16.dp))
                PrimaryPill("Buka shift", ::open, Modifier.fillMaxWidth(), icon = Icons.Filled.Check)
                TextButton(onClick = { store.go(Dest.TABLES) }, Modifier.align(Alignment.CenterHorizontally).padding(top = 8.dp)) { Text("Lewati, ambil order saja", fontWeight = FontWeight.SemiBold, fontSize = 16.sp) }
            }
        }
    }

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background).padding(horizontal = 16.dp, vertical = 12.dp)) {
        Row(Modifier.fillMaxWidth().padding(bottom = 14.dp), verticalAlignment = Alignment.CenterVertically) {
            Text("Buka shift", Modifier.weight(1f), fontSize = 28.sp)
            TextButton({ store.lock() }) { Icon(Icons.AutoMirrored.Filled.Logout, null, Modifier.size(20.dp)); Spacer(Modifier.width(8.dp)); Text("Keluar", fontWeight = FontWeight.SemiBold) }
        }
        if (compact) Column(Modifier.verticalScroll(rememberScrollState())) { left(Modifier.fillMaxWidth()); Spacer(Modifier.height(12.dp)); right(Modifier.fillMaxWidth()) }
        else Row(Modifier.weight(1f)) { left(Modifier.weight(2f).fillMaxHeight().padding(end = 16.dp)); right(Modifier.weight(1f).fillMaxHeight()) }
    }
}
