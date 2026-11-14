package com.boothpos.customer.core.ui.icon

import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.graphics.vector.addPathNodes
import androidx.compose.ui.unit.dp

/**
 * Ikon garis desain "Sky Capsule": kanvas 24, garis 2, ujung dan sambungan membulat. Digambar di
 * sini sebagai [ImageVector] karena `material-icons-extended` sengaja tidak dipakai (sangat
 * besar, lihat gradle/libs.versions.toml). Warnanya selalu dari `tint` pemanggil.
 *
 * Busur ditulis dengan bendera terpisah ("0 0 1"), bukan bentuk rapat SVG ("01"): pengurai path
 * Android lama salah membaca bentuk rapat.
 */
object SkyIcons {
    val Search: ImageVector by lazy {
        strokeIcon("Search") {
            circle(11f, 11f, 7f)
            path("M20 20l-3.6-3.6")
        }
    }
    val Store: ImageVector by lazy {
        strokeIcon("Store") {
            path("M4 11v9h16v-9")
            path("M2.5 9.5L4.5 4h15l2 5.5a3 3 0 0 1 -6 0a3.2 3.2 0 0 1 -6.5 0a3.2 3.2 0 0 1 -6.5 0z")
            path("M10 20v-5h4v5")
        }
    }
    val Cart: ImageVector by lazy {
        strokeIcon("Cart") {
            circle(9f, 20f, 1.4f)
            circle(18f, 20f, 1.4f)
            path("M2 3h3l2.6 12.5h10.8L21 7H6.2")
        }
    }
    val Receipt: ImageVector by lazy {
        strokeIcon("Receipt") {
            path("M6 3h12v18l-3-2-3 2-3-2-3 2z")
            path("M9.5 8.5h5M9.5 12.5h5")
        }
    }
    val Back: ImageVector by lazy {
        strokeIcon("Back", autoMirror = true) {
            path("M19 12H5")
            path("M11 6l-6 6 6 6")
        }
    }
    val Check: ImageVector by lazy { strokeIcon("Check") { path("M5 12.5l4.5 4.5L19 7.5") } }
    val Plus: ImageVector by lazy { strokeIcon("Plus") { path("M12 5v14M5 12h14") } }
    val Minus: ImageVector by lazy { strokeIcon("Minus") { path("M5 12h14") } }
    val Close: ImageVector by lazy { strokeIcon("Close") { path("M6 6l12 12M18 6L6 18") } }
    val ChevronRight: ImageVector by lazy { strokeIcon("ChevronRight", autoMirror = true) { path("M9 6l6 6-6 6") } }
    val Bag: ImageVector by lazy {
        strokeIcon("Bag") {
            path("M5 8h14l-1 13H6z")
            path("M9 8V7a3 3 0 0 1 6 0v1")
        }
    }
    val Box: ImageVector by lazy {
        strokeIcon("Box") {
            path("M12 3l8.5 4.5v9L12 21l-8.5-4.5v-9z")
            path("M3.5 7.5L12 12l8.5-4.5")
            path("M12 12v9")
        }
    }
    val Truck: ImageVector by lazy {
        strokeIcon("Truck", autoMirror = true) {
            path("M2 6h11v10H2z")
            path("M13 9h4.5l3.5 3.5V16h-8")
            circle(6.5f, 17.5f, 2f)
            circle(17f, 17.5f, 2f)
        }
    }
    val Pin: ImageVector by lazy {
        strokeIcon("Pin") {
            path("M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11z")
            circle(12f, 10f, 2.2f)
        }
    }
    val Home: ImageVector by lazy {
        strokeIcon("Home") {
            path("M4 11l8-7 8 7")
            path("M6 10v10h12V10")
            path("M10 20v-5h4v5")
        }
    }
    val Edit: ImageVector by lazy {
        strokeIcon("Edit") {
            path("M4 20h4L19 9l-4-4L4 16z")
            path("M13.5 6.5l4 4")
        }
    }
    val Trash: ImageVector by lazy {
        strokeIcon("Trash") {
            path("M4.5 7h15")
            path("M9 7V4.5h6V7")
            path("M6.5 7l1 13h9l1-13")
            path("M10 11v5.5M14 11v5.5")
        }
    }
    val Logout: ImageVector by lazy {
        strokeIcon("Logout", autoMirror = true) {
            path("M10 4H5v16h5")
            path("M14 8l4 4-4 4")
            path("M18 12H9")
        }
    }
    val Refresh: ImageVector by lazy {
        strokeIcon("Refresh") {
            path("M21 12a9 9 0 1 1 -9 -9c2.52 0 4.93 1 6.74 2.74L21 8")
            path("M21 3v5h-5")
        }
    }
    val Clock: ImageVector by lazy {
        strokeIcon("Clock") {
            circle(12f, 12f, 9f)
            path("M12 7v5l3 2")
        }
    }
    val Info: ImageVector by lazy {
        strokeIcon("Info") {
            circle(12f, 12f, 9f)
            path("M12 11v5.5")
            path("M12 7.6v.2")
        }
    }
    val Alert: ImageVector by lazy {
        strokeIcon("Alert") {
            path("M12 4l9 16H3z")
            path("M12 10v4.5")
            path("M12 17.3v.2")
        }
    }
    val CheckCircle: ImageVector by lazy {
        strokeIcon("CheckCircle") {
            circle(12f, 12f, 9f)
            path("M8 12.5l2.8 2.8L16 9.5")
        }
    }
    val CloseCircle: ImageVector by lazy {
        strokeIcon("CloseCircle") {
            circle(12f, 12f, 9f)
            path("M9 9l6 6M15 9l-6 6")
        }
    }
    val Lock: ImageVector by lazy {
        strokeIcon("Lock") {
            path("M6 11h12v9H6z")
            path("M8.5 11V8a3.5 3.5 0 0 1 7 0v3")
        }
    }
    val Sparkle: ImageVector by lazy {
        strokeIcon("Sparkle") {
            path("M11 4q1.3 5.7 7 7q-5.7 1.3-7 7q-1.3-5.7-7-7q5.7-1.3 7-7z")
            path("M19 3v3M17.5 4.5h3")
        }
    }
}

private class StrokeIconScope(private val builder: ImageVector.Builder) {
    fun path(data: String) {
        builder.addPath(
            pathData = addPathNodes(data),
            fill = null,
            stroke = SolidColor(Color.Black),
            strokeLineWidth = 2f,
            strokeLineCap = StrokeCap.Round,
            strokeLineJoin = StrokeJoin.Round,
        )
    }

    /** Lingkaran sebagai dua busur setengah (path tidak punya perintah lingkaran). */
    fun circle(cx: Float, cy: Float, r: Float) {
        val d = r * 2
        path("M${cx - r} ${cy}a$r $r 0 1 0 $d 0a$r $r 0 1 0 ${-d} 0z")
    }
}

private fun strokeIcon(
    name: String,
    autoMirror: Boolean = false,
    block: StrokeIconScope.() -> Unit,
): ImageVector = ImageVector.Builder(
    name = name,
    defaultWidth = 24.dp,
    defaultHeight = 24.dp,
    viewportWidth = 24f,
    viewportHeight = 24f,
    autoMirror = autoMirror,
).apply { StrokeIconScope(this).block() }.build()
