package com.boothpos.customer.core.ui.theme

import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Shapes
import androidx.compose.ui.unit.dp

/** Jarak baku. Layar memakai token ini, bukan angka lepas. */
object Spacing {
    val xs = 4.dp
    val sm = 8.dp
    val md = 16.dp
    val lg = 24.dp
    val xl = 32.dp
}

object Dimens {
    /** Target sentuh minimum (aksesibilitas). */
    val MinTouchTarget = 48.dp

    /** Di layar lebar konten dipusatkan dengan lebar maksimum ini, bukan direntang menjadi dasbor. */
    val ContentMaxWidth = 640.dp
}

// Sudut sederhana: cukup untuk membedakan permukaan, tidak membulat berlebihan.
internal val BoothShapes = Shapes(
    extraSmall = RoundedCornerShape(4.dp),
    small = RoundedCornerShape(6.dp),
    medium = RoundedCornerShape(8.dp),
    large = RoundedCornerShape(12.dp),
    extraLarge = RoundedCornerShape(16.dp),
)
