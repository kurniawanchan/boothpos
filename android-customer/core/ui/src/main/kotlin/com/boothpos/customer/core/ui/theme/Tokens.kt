package com.boothpos.customer.core.ui.theme

import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Shapes
import androidx.compose.ui.unit.dp

/** Jarak baku. Layar memakai token ini, bukan angka lepas. */
object Spacing {
    val xs = 4.dp
    val sm = 8.dp
    val ms = 12.dp
    val md = 16.dp
    val lg = 24.dp
    val xl = 32.dp
}

object Dimens {
    /** Target sentuh minimum (aksesibilitas). */
    val MinTouchTarget = 48.dp

    /** Di layar lebar konten dipusatkan dengan lebar maksimum ini, bukan direntang menjadi dasbor. */
    val ContentMaxWidth = 640.dp

    /** Tinggi tombol aksi utama dan kolom isian. */
    val ButtonHeight = 56.dp

    /** Diameter tombol bulat (kembali, pengatur jumlah): digambar 44 dp, area sentuh tetap 48 dp. */
    val RoundButton = 44.dp

    /** Ubin ikon di dalam kartu pilihan dan baris menu. */
    val IconTile = 48.dp
}

// "Sky Capsule": kartu 28 dp, kolom isian dan ubin 16 dp, catatan 14 dp. Tombol, chip, dan kolom
// cari membulat penuh (PillShape); komponen Material yang memakai `small`/`medium` ikut membulat.
internal val BoothShapes = Shapes(
    extraSmall = RoundedCornerShape(12.dp),
    small = RoundedCornerShape(14.dp),
    medium = RoundedCornerShape(16.dp),
    large = RoundedCornerShape(28.dp),
    extraLarge = RoundedCornerShape(28.dp),
)

/** Bentuk membulat penuh untuk tombol, chip, lencana, dan kolom cari. */
val PillShape = CircleShape

/** Lembar konten yang menumpang di atas hero (detail produk): hanya sudut atas yang membulat. */
val SheetShape = RoundedCornerShape(topStart = 32.dp, topEnd = 32.dp)

/** Navigasi bawah yang menempel: hanya sudut atas yang membulat. */
val DockShape = RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp)
