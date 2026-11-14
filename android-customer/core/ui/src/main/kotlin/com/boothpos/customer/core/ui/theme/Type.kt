package com.boothpos.customer.core.ui.theme

import androidx.compose.material3.Typography
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import com.boothpos.customer.core.ui.R

// Fredoka (SIL Open Font License 1.1, lihat assets/licenses/Fredoka-OFL.txt). Dua berkas statis,
// bukan berkas variabel: instans bawaan berkas variabelnya adalah Light 300, jadi perangkat yang
// tidak menerapkan sumbu `wght` akan diam-diam menampilkan ketebalan yang salah.
internal val Fredoka = FontFamily(
    Font(R.font.fredoka_regular, FontWeight.Normal),
    Font(R.font.fredoka_semibold, FontWeight.SemiBold),
)

// Desain hanya memakai EMPAT ukuran (28 / 20 / 16 / 12 sp) dan DUA ketebalan (400 / 600). Semua
// peran tipografi Material dipetakan ke salah satunya, supaya komponen Material bawaan (tombol,
// chip, dialog, kolom isian) ikut skala yang sama tanpa ukuran liar. Satuan sp: skala huruf
// pengguna tetap dihormati.
private fun style(size: Int, lineHeight: Int, weight: FontWeight) = TextStyle(
    fontFamily = Fredoka,
    fontWeight = weight,
    fontSize = size.sp,
    lineHeight = lineHeight.sp,
    letterSpacing = 0.sp,
)

private val Display = style(28, 36, FontWeight.SemiBold)
private val Title = style(20, 28, FontWeight.SemiBold)
private val BodyStrong = style(16, 24, FontWeight.SemiBold)
private val Body = style(16, 24, FontWeight.Normal)
private val CaptionStrong = style(12, 16, FontWeight.SemiBold)
private val Caption = style(12, 16, FontWeight.Normal)

internal val BoothTypography = Typography(
    displayLarge = Display,
    displayMedium = Display,
    displaySmall = Display,
    headlineLarge = Display,
    // 28 sp: harga di detail produk, judul layar sambutan dan "Pesanan dibuat".
    headlineMedium = Display,
    // 20 sp: nama produk, judul dialog (bawaan Material untuk AlertDialog).
    headlineSmall = Title,
    titleLarge = Title,
    titleMedium = BodyStrong,
    // 12 sp tebal: label bagian ("Pilih varian", "Barang", "Rincian biaya").
    titleSmall = CaptionStrong,
    bodyLarge = Body,
    bodyMedium = Body,
    bodySmall = Caption,
    // Tombol, chip, tautan.
    labelLarge = BodyStrong,
    labelMedium = CaptionStrong,
    labelSmall = CaptionStrong,
)
