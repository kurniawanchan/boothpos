package com.warkop.pos.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.SystemMode
import com.warkop.pos.model.Tone
import com.warkop.pos.state.PosStore
import com.warkop.pos.theme.pos

/** 077 — nama toko bila server belum memberi (sama dengan bawaan struk di ReceiptMapper). */
const val DEFAULT_STORE_NAME = "Toko"

/** 077 — nama outlet hanya ditampilkan bila organisasi punya lebih dari satu outlet (asumsi spec); satu outlet = tanpa label tambahan. */
fun showOutletName(outletCount: Int?): Boolean = (outletCount ?: 0) > 1

/** 077 — nama yang ditampilkan di header: nama dari server, atau bawaan bila kosong. */
fun storeTitle(storeName: String?): String = storeName?.trim()?.takeIf { it.isNotEmpty() } ?: DEFAULT_STORE_NAME

/** 077 — lencana mode: hanya DEMO yang ditandai, supaya LIVE tetap bersih dan order demo tidak tertukar dengan order sungguhan. */
fun showModeBadge(mode: SystemMode): Boolean = mode == SystemMode.DEMO

/**
 * 077 — identitas toko: logo (inisial nama bila belum ada/gagal dimuat, lewat [ProductPhoto] yang sama dengan foto produk) +
 * nama toko satu baris berelipsis (+ nama outlet bila multi-outlet) + lencana DEMO. Ukuran logo tetap, jadi tata letak tidak bergeser
 * saat gambar tiba. Dipakai di layar penyiapan, PIN, dan header semua layar setelah login.
 */
@Composable
fun StoreMark(store: PosStore, modifier: Modifier = Modifier, logoSize: Dp = 40.dp, maxTextWidth: Dp = 220.dp) {
    val b = store.branding
    val name = storeTitle(b?.storeName)
    Row(modifier, verticalAlignment = Alignment.CenterVertically) {
        ProductPhoto(b?.logoUrl, name, Tone.INK, Modifier.size(logoSize).clip(RoundedCornerShape(12.dp)), initialsSize = (logoSize.value * 0.45f).sp)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.widthIn(max = maxTextWidth)) {
            Text(name, fontWeight = FontWeight.SemiBold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (showOutletName(b?.outletCount)) store.config?.outletName?.takeIf { it.isNotBlank() }?.let {
                Text(it, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
        if (showModeBadge(store.mode)) { Spacer(Modifier.width(8.dp)); ModeBadge() }
    }
}

@Composable
fun ModeBadge() {
    Surface(shape = RoundedCornerShape(50), color = MaterialTheme.pos.warnContainer, contentColor = MaterialTheme.pos.onWarnContainer) {
        Text("DEMO", Modifier.padding(horizontal = 10.dp, vertical = 3.dp), fontSize = 12.sp, fontWeight = FontWeight.Bold)
    }
}
