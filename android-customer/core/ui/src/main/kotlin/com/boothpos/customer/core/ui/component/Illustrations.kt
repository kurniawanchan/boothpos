package com.boothpos.customer.core.ui.component

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.rotate
import androidx.compose.ui.graphics.drawscope.scale
import androidx.compose.ui.graphics.drawscope.translate
import androidx.compose.ui.graphics.vector.PathParser
import androidx.compose.ui.unit.Dp
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.core.ui.theme.SoftShadow
import com.boothpos.customer.core.ui.theme.softShadow

// Ilustrasi desain "Sky Capsule" digambar dengan Canvas dari data path mockup: tanpa aset bitmap
// dan tanpa pustaka ikon tambahan. Semuanya dekoratif (tanpa semantik); teks di sebelahnya yang
// membawa makna.

private fun svgPath(data: String): Path = PathParser().parsePathString(data).toPath()

// Maskot ikan, kanvas 64 x 52. Warnanya bagian dari ilustrasi, bukan dari tema.
private val MascotTail by lazy { svgPath("M14 26L2 12l4 14-4 14z") }
private val MascotTopFin by lazy { svgPath("M27 9q9-11 17 0z") }
private val MascotSideFin by lazy { svgPath("M30 29q-5 5 2 8") }
private val MascotSmile by lazy { svgPath("M53.5 29q2 2.4 4.2 0") }
private val MascotHappyEye by lazy { svgPath("M40.5 24q5.5-7.5 11 0") }
private val MascotHappyMouth by lazy { svgPath("M52.6 29.2q2.2 3.4 4.6 0z") }

private val MascotFin = Color(0xFF7DBBFF)
private val MascotBody = Color(0xFFA9D8FF)
private val MascotBelly = Color(0xFFE6F4FF)
private val MascotInk = Color(0xFF2B2350)
private val MascotCheek = Color(0xFFFFB3C7)

private const val MASCOT_WIDTH = 64f
private const val MASCOT_HEIGHT = 52f

/**
 * Maskot ikan. [modifier] cukup menentukan lebar; tingginya mengikuti perbandingan gambar.
 * [happy]: mata tersenyum untuk layar keberhasilan.
 */
@Composable
fun Mascot(modifier: Modifier = Modifier, happy: Boolean = false) {
    Canvas(modifier = modifier.aspectRatio(MASCOT_WIDTH / MASCOT_HEIGHT)) {
        val unit = size.width / MASCOT_WIDTH
        scale(scaleX = unit, scaleY = unit, pivot = Offset.Zero) {
            drawPath(MascotTail, MascotFin)
            drawPath(MascotTopFin, MascotFin)
            drawOval(MascotBody, topLeft = Offset(12f, 8f), size = Size(48f, 38f))
            drawOval(MascotBelly, topLeft = Offset(22f, 26f), size = Size(32f, 18f))
            if (happy) {
                drawPath(MascotHappyEye, MascotInk, style = Stroke(width = 2.8f, cap = StrokeCap.Round))
                drawPath(MascotHappyMouth, MascotInk)
            } else {
                drawCircle(MascotInk, radius = 6.5f, center = Offset(46f, 22f))
                drawCircle(Color.White, radius = 2.3f, center = Offset(48.2f, 19.8f))
                drawCircle(Color.White, radius = 1.1f, center = Offset(44.2f, 24.6f))
                drawPath(MascotSmile, MascotInk, style = Stroke(width = 1.6f, cap = StrokeCap.Round))
            }
            drawOval(MascotCheek, topLeft = Offset(42.4f, 30.5f), size = Size(9.2f, 5f))
            drawPath(MascotSideFin, MascotFin, style = Stroke(width = 3f, cap = StrokeCap.Round))
        }
    }
}

// Konfeti: posisi pada kanvas 375 x 264 (x, y, jenis). Jenis 0 = bulatan, 1 = kapsul miring,
// 2 = kilau besar, 3 = kilau kecil.
private val ConfettiSparkle by lazy { svgPath("M0 -6Q.9 -.9 6 0Q.9 .9 0 6Q-.9 .9 -6 0Q-.9 -.9 0 -6z") }
private val ConfettiPoints = listOf(
    Triple(36f, 60f, 0), Triple(84f, 32f, 1), Triple(300f, 44f, 2), Triple(338f, 92f, 3),
    Triple(52f, 150f, 2), Triple(320f, 168f, 0), Triple(118f, 70f, 3), Triple(262f, 84f, 1),
    Triple(28f, 214f, 1), Triple(346f, 226f, 2), Triple(150f, 30f, 0), Triple(228f, 34f, 3),
    Triple(88f, 206f, 0), Triple(292f, 222f, 3),
)
private const val CONFETTI_WIDTH = 375f
private const val CONFETTI_HEIGHT = 264f

/**
 * Taburan konfeti di belakang maskot (layar sambutan, "Pesanan dibuat"). Diam, bukan animasi.
 * Ditaruh memenuhi kotak ilustrasi; isinya diskalakan dari kanvas desain.
 */
@Composable
fun Confetti(modifier: Modifier = Modifier) {
    val sky = BoothTheme.sky
    val colors = listOf(
        MaterialTheme.colorScheme.primaryContainer,
        sky.coral,
        sky.capsuleLower(1),
        sky.progress,
        sky.capsuleUpper,
    )
    Canvas(modifier = modifier) {
        val unitX = size.width / CONFETTI_WIDTH
        val unitY = size.height / CONFETTI_HEIGHT
        // Bentuknya sendiri diskalakan seragam supaya bulatan tetap bulat di kotak yang tidak sebanding.
        val unit = minOf(unitX, unitY)
        ConfettiPoints.forEachIndexed { index, (x, y, kind) ->
            val color = colors[index % colors.size]
            translate(left = x * unitX, top = y * unitY) {
                when (kind) {
                    0 -> drawCircle(color, radius = 6f * unit, center = Offset.Zero)
                    1 -> rotate(degrees = index * 37f, pivot = Offset.Zero) {
                        drawRoundRect(
                            color = color,
                            topLeft = Offset(-8f * unit, -4f * unit),
                            size = Size(16f * unit, 8f * unit),
                            cornerRadius = CornerRadius(4f * unit),
                        )
                    }
                    else -> drawSparkle(color, factor = (if (kind == 2) 1.6f else 1.1f) * unit)
                }
            }
        }
    }
}

private fun DrawScope.drawSparkle(color: Color, factor: Float) {
    scale(scaleX = factor, scaleY = factor, pivot = Offset.Zero) { drawPath(ConfettiSparkle, color) }
}

/**
 * Bingkai "kapsul gacha": lingkaran dua warna (belahan atas terang, belahan bawah [lowerColor])
 * dengan sambungan tipis berwarna [seamColor] (warna ubin di belakangnya). Isi ditaruh di tengah.
 */
@Composable
fun CapsuleFrame(
    diameter: Dp,
    lowerColor: Color,
    seamColor: Color,
    modifier: Modifier = Modifier,
    content: @Composable BoxScope.() -> Unit = {},
) {
    val sky = BoothTheme.sky
    val upperColor = sky.capsuleUpper
    Box(
        modifier = modifier
            .size(diameter)
            .softShadow(CircleShape, sky.shadow, SoftShadow.Capsule)
            .clip(CircleShape)
            .drawBehind {
                // Perbandingan desain: 47 % atas, sambungan 4 %, sisanya belahan bawah.
                val seamTop = size.height * 0.47f
                val seamBottom = size.height * 0.51f
                drawRect(upperColor, size = Size(size.width, seamTop))
                drawRect(seamColor, topLeft = Offset(0f, seamTop), size = Size(size.width, seamBottom - seamTop))
                drawRect(lowerColor, topLeft = Offset(0f, seamBottom), size = Size(size.width, size.height - seamBottom))
            },
        contentAlignment = Alignment.Center,
        content = content,
    )
}
