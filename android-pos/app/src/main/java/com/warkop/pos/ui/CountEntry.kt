package com.warkop.pos.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.OffsetMapping
import androidx.compose.ui.text.input.TransformedText
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.PosCatalog
import com.warkop.pos.model.num
import com.warkop.pos.model.rp
import com.warkop.pos.state.CountInput
import com.warkop.pos.state.ThousandsOffsets

/**
 * 065 — isian uang untuk buka dan tutup shift, SATU implementasi dipakai kedua layar: baris pecahan yang terbaca
 * penuh dan kolom "total ketik" (rupiah utuh). Aturan "yang terakhir disentuh menang" ada di [CountInput].
 */

/** Label pecahan: "Rp 100.000" atau "Koin Rp 500". */
fun denominationLabel(d: Int): String = if (d >= 1000) "Rp ${num(d)}" else "Koin Rp ${num(d)}"

@Composable
fun DenominationRow(d: Int, qty: Int, onQty: (Int) -> Unit, modifier: Modifier = Modifier, showSubtotal: Boolean = true, enabled: Boolean = true) {
    Column(modifier) {
        Row(Modifier.fillMaxWidth().padding(vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
            // Label punya lebar minimum: dulu ia mengisi sisa lebar dan terjepit sampai terpotong.
            Text(denominationLabel(d), Modifier.weight(1f).widthIn(min = DenominationLayout.LABEL_MIN_DP.dp), fontSize = 17.sp, maxLines = 1, softWrap = false)
            CircleStepper(qty, { if (enabled && qty > 0) onQty(qty - 1) }, { if (enabled) onQty(qty + 1) })
            if (showSubtotal) Text(rp(d * qty), Modifier.width(88.dp), fontSize = 15.sp, textAlign = TextAlign.End, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, softWrap = false)
        }
        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
    }
}

/** Semua pecahan, [columns] per baris (hitung dengan [DenominationLayout.columnsFor] dari lebar yang tersedia). */
@Composable
fun DenominationGrid(input: CountInput, onChange: (CountInput) -> Unit, columns: Int, modifier: Modifier = Modifier, showSubtotal: Boolean = true, enabled: Boolean = true) {
    Column(modifier) {
        PosCatalog.denominations.chunked(columns).forEach { rowItems ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(24.dp)) {
                rowItems.forEach { d ->
                    DenominationRow(d, input.counts[d] ?: 0, { onChange(input.setCount(d, it)) }, Modifier.weight(1f), showSubtotal, enabled)
                }
                repeat(columns - rowItems.size) { Spacer(Modifier.weight(1f)) }
            }
        }
    }
}

/** Kolom total ketik: hanya angka, tampil dengan pemisah ribuan; mengetik mengosongkan stepper ([CountInput.type]). */
@Composable
fun TypedAmountField(input: CountInput, onChange: (CountInput) -> Unit, modifier: Modifier = Modifier, label: String = "Atau ketik total", enabled: Boolean = true) {
    OutlinedTextField(
        // 084 — nilai = digit murni; titik ribuan hanya tampilan (ThousandsVisualTransformation), jadi kursor tidak melompat.
        value = input.typed,
        onValueChange = { onChange(input.type(it)) },
        visualTransformation = ThousandsVisualTransformation,
        modifier = modifier.fillMaxWidth(),
        label = { Text(label) },
        prefix = { Text("Rp ") },
        placeholder = { Text("mis. 150.000") },
        singleLine = true,
        enabled = enabled,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
        shape = RoundedCornerShape(12.dp),
    )
}

/** 084 — pemisah ribuan sebagai tampilan; pemetaan kursor dari [ThousandsOffsets] (diuji murni). */
private val ThousandsVisualTransformation = VisualTransformation { text ->
    val digits = text.text
    TransformedText(
        AnnotatedString(ThousandsOffsets.format(digits)),
        object : OffsetMapping {
            override fun originalToTransformed(offset: Int): Int = ThousandsOffsets.toTransformed(digits, offset)
            override fun transformedToOriginal(offset: Int): Int = ThousandsOffsets.toOriginal(digits, offset)
        },
    )
}
