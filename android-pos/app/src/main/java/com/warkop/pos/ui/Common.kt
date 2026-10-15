package com.warkop.pos.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Backspace
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.Tone

/** Kelas ukuran layar dari desain: compact <600dp, medium 600–839, expanded 840–1199, large ≥1200. */
enum class Size { COMPACT, MEDIUM, EXPANDED, LARGE }

@Composable
fun windowSize(): Size {
    val w = LocalConfiguration.current.screenWidthDp
    return when { w < 600 -> Size.COMPACT; w < 840 -> Size.MEDIUM; w < 1200 -> Size.EXPANDED; else -> Size.LARGE }
}

fun Size.menuColumns() = when (this) { Size.COMPACT -> 2; Size.MEDIUM -> 3; Size.EXPANDED -> 4; Size.LARGE -> 5 }
fun Size.tableColumns() = when (this) { Size.COMPACT -> 3; Size.MEDIUM -> 4; Size.EXPANDED -> 6; Size.LARGE -> 8 }

/** Ikon menu desain (nama Lucide) dipetakan ke ikon Material terdekat; yang tak dikenal jatuh ke Restaurant. */
fun productIcon(name: String): ImageVector = when (name) {
    "coffee" -> Icons.Filled.Coffee
    "cup-soda", "glass-water", "citrus" -> Icons.Filled.LocalDrink
    "milk" -> Icons.Filled.EmojiFoodBeverage
    "ice-cream" -> Icons.Filled.Icecream
    "flame" -> Icons.Filled.LocalFireDepartment
    "soup" -> Icons.Filled.RamenDining
    "egg", "wheat" -> Icons.Filled.BakeryDining
    "drumstick", "utensils" -> Icons.Filled.Restaurant
    "sandwich" -> Icons.Filled.LunchDining
    "popcorn", "layers", "cookie", "banana" -> Icons.Filled.Cookie
    else -> Icons.Filled.Restaurant
}

@Composable
fun toneColors(tone: Tone): Pair<Color, Color> {
    val c = MaterialTheme.colorScheme
    return when (tone) {
        Tone.INK -> c.tertiaryContainer to c.onTertiaryContainer
        Tone.TINT -> c.secondaryContainer to c.onSecondaryContainer
        Tone.ACCENT -> c.primaryContainer to c.onPrimaryContainer
        Tone.NEUTRAL -> c.surfaceContainerHighest to c.onSurface
        Tone.OUTLINE -> c.surfaceContainerHigh to c.onSurfaceVariant
    }
}

/** Arsiran diagonal untuk item habis (86) dan meja milik perangkat lain. */
fun Modifier.hatched(color: Color, gap: Dp = 8.dp): Modifier = clipToBounds().drawWithContent {
    drawContent()
    val step = gap.toPx()
    var x = -size.height
    while (x < size.width) {
        drawLine(color.copy(alpha = 0.55f), Offset(x, size.height), Offset(x + size.height, 0f), strokeWidth = 1.5f)
        x += step
    }
}

@Composable
fun Pill(text: String, container: Color, content: Color, modifier: Modifier = Modifier) {
    Surface(modifier, shape = RoundedCornerShape(50), color = container, contentColor = content) {
        Text(text, Modifier.padding(horizontal = 10.dp, vertical = 3.dp), fontSize = 12.sp, fontWeight = FontWeight.Medium, maxLines = 1, softWrap = false)
    }
}

@Composable
fun QtyStepper(qty: Int, onMinus: () -> Unit, onPlus: () -> Unit, modifier: Modifier = Modifier) {
    Surface(modifier, shape = RoundedCornerShape(50), color = MaterialTheme.colorScheme.surfaceContainerHigh) {
        Row(Modifier.padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = onMinus, Modifier.size(40.dp)) { Icon(Icons.Filled.Remove, "Kurangi") }
            Text(qty.toString(), fontWeight = FontWeight.SemiBold, fontSize = 16.sp, modifier = Modifier.widthIn(min = 22.dp))
            IconButton(onClick = onPlus, Modifier.size(40.dp)) { Icon(Icons.Filled.Add, "Tambah") }
        }
    }
}

/** Keypad angka (PIN & nominal). Bentuk bulat (PIN, desain 01) memakai tombol persegi-sama-sisi; `shape` persegi panjang (nominal, desain 13) melebar mengisi baris. */
@Composable
fun Keypad(onDigit: (String) -> Unit, onBackspace: () -> Unit, modifier: Modifier = Modifier, extraKey: String? = null, keyHeight: Dp = 56.dp, shape: androidx.compose.ui.graphics.Shape = CircleShape, light: Boolean = false) {
    val round = shape == CircleShape
    val rows = listOf(listOf("1", "2", "3"), listOf("4", "5", "6"), listOf("7", "8", "9"))
    val keyMod: @Composable RowScope.() -> Modifier = { if (round) Modifier.size(keyHeight) else Modifier.weight(1f).height(keyHeight) }
    Column(modifier, verticalArrangement = Arrangement.spacedBy(10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        val arrangement = if (round) Arrangement.spacedBy(18.dp) else Arrangement.spacedBy(10.dp)
        rows.forEach { r -> Row(horizontalArrangement = arrangement) { r.forEach { k -> Key(k, keyMod(), shape, light) { onDigit(k) } } } }
        Row(horizontalArrangement = arrangement) {
            if (extraKey != null) Key(extraKey, keyMod(), shape, light) { onDigit(extraKey) } else if (round) Spacer(Modifier.size(keyHeight)) else Spacer(Modifier.weight(1f))
            Key("0", keyMod(), shape, light) { onDigit("0") }
            Surface(modifier = keyMod(), shape = shape, color = if (light) MaterialTheme.colorScheme.surfaceContainerLowest else MaterialTheme.colorScheme.surfaceContainerHigh, onClick = onBackspace) {
                Box(contentAlignment = Alignment.Center) { Icon(Icons.AutoMirrored.Filled.Backspace, "Hapus") }
            }
        }
    }
}

@Composable
private fun Key(label: String, modifier: Modifier, shape: androidx.compose.ui.graphics.Shape, light: Boolean, onClick: () -> Unit) {
    val round = shape == CircleShape
    Surface(modifier = modifier, shape = shape, color = if (light) MaterialTheme.colorScheme.surfaceContainerLowest else if (round) MaterialTheme.colorScheme.surfaceContainerHigh else MaterialTheme.colorScheme.surfaceContainerLow,
        border = if (round) null else BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant), onClick = onClick) {
        Box(contentAlignment = Alignment.Center) { Text(label, fontSize = 22.sp, fontWeight = FontWeight.Medium) }
    }
}

@Composable
fun SectionLabel(text: String, modifier: Modifier = Modifier, color: Color = MaterialTheme.colorScheme.primary) {
    Text(text, modifier, color = color, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
}

@Composable
fun MoneyRow(label: String, value: String, modifier: Modifier = Modifier, big: Boolean = false, muted: Boolean = true) {
    Row(modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
        Text(label, fontSize = if (big) 24.sp else 14.sp, fontWeight = if (big) FontWeight.Medium else FontWeight.Normal,
            color = if (muted && !big) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.onSurface)
        Text(value, fontSize = if (big) 26.sp else 14.sp, fontWeight = if (big) FontWeight.Medium else FontWeight.Normal)
    }
}

@Composable
fun PrimaryPill(text: String, onClick: () -> Unit, modifier: Modifier = Modifier, enabled: Boolean = true, icon: ImageVector? = null, trailing: String? = null) {
    Button(onClick, modifier.height(56.dp), enabled = enabled, shape = RoundedCornerShape(50)) {
        if (icon != null) { Icon(icon, null); Spacer(Modifier.width(8.dp)) }
        Text(text, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, softWrap = false)
        if (trailing != null) { Spacer(Modifier.weight(1f).widthIn(min = 14.dp)); Text(trailing, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, softWrap = false) }
    }
}

@Composable
fun TonalPill(text: String, onClick: () -> Unit, modifier: Modifier = Modifier, enabled: Boolean = true, icon: ImageVector? = null, secondary: Boolean = false) {
    FilledTonalButton(onClick, modifier.height(56.dp), enabled = enabled, shape = RoundedCornerShape(50),
        colors = ButtonDefaults.filledTonalButtonColors(containerColor = if (secondary) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.primaryContainer, contentColor = if (secondary) MaterialTheme.colorScheme.onSecondaryContainer else MaterialTheme.colorScheme.onPrimaryContainer)) {
        if (icon != null) { Icon(icon, null); Spacer(Modifier.width(8.dp)) }
        Text(text, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, softWrap = false)
    }
}

@Composable
fun OutlinedPill(text: String, onClick: () -> Unit, modifier: Modifier = Modifier, enabled: Boolean = true, icon: ImageVector? = null, danger: Boolean = false) {
    val c = if (danger) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary
    OutlinedButton(onClick, modifier.height(48.dp), enabled = enabled, shape = RoundedCornerShape(50), border = BorderStroke(1.dp, if (danger) c else MaterialTheme.colorScheme.outline),
        colors = ButtonDefaults.outlinedButtonColors(contentColor = c)) {
        if (icon != null) { Icon(icon, null, Modifier.size(18.dp)); Spacer(Modifier.width(6.dp)) }
        Text(text, fontWeight = FontWeight.Medium)
    }
}

@Composable
fun Dot(filled: Boolean, size: Dp = 14.dp) {
    Box(Modifier.size(size).clip(CircleShape).background(if (filled) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outlineVariant))
}

@Composable
fun RadioRow(label: String, selected: Boolean, onClick: () -> Unit, trailing: String? = null, big: Boolean = false) {
    Row(Modifier.fillMaxWidth().height(if (big) 58.dp else 44.dp).clip(RoundedCornerShape(12.dp)).clickable(onClick = onClick).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        RadioButton(selected, onClick)
        Text(label, Modifier.weight(1f).padding(start = 4.dp), fontSize = if (big) 20.sp else 16.sp)
        if (trailing != null) Text(trailing, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

/** Stepper bundar desain 02/24: tombol kurang berbingkai, tambah tonal. Berbeda dari [QtyStepper] (pil) di panel order. */
@Composable
fun CircleStepper(qty: Int, onMinus: () -> Unit, onPlus: () -> Unit, modifier: Modifier = Modifier) {
    Row(modifier, verticalAlignment = Alignment.CenterVertically) {
        Surface(onClick = onMinus, modifier = Modifier.size(44.dp), shape = CircleShape, color = Color.Transparent, border = BorderStroke(1.dp, MaterialTheme.colorScheme.outline)) { Box(contentAlignment = Alignment.Center) { Icon(Icons.Filled.Remove, "Kurangi", Modifier.size(20.dp)) } }
        Text(qty.toString(), Modifier.widthIn(min = 48.dp), textAlign = androidx.compose.ui.text.style.TextAlign.Center, fontWeight = FontWeight.Bold, fontSize = 18.sp)
        Surface(onClick = onPlus, modifier = Modifier.size(44.dp), shape = CircleShape, color = MaterialTheme.colorScheme.primaryContainer) { Box(contentAlignment = Alignment.Center) { Icon(Icons.Filled.Add, "Tambah", Modifier.size(20.dp)) } }
    }
}
