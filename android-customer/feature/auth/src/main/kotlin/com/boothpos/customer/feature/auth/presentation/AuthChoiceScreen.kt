package com.boothpos.customer.feature.auth.presentation

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.boothpos.customer.core.ui.component.CapsuleFrame
import com.boothpos.customer.core.ui.component.Confetti
import com.boothpos.customer.core.ui.component.Mascot
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.component.SecondaryButton
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.core.ui.theme.HeroBackdrop
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.auth.R

/**
 * Pilihan awal: Masuk (aksi utama), Daftar, atau melihat toko tanpa akun. Tanpa state: tidak ada
 * data yang dimuat dan tidak ada yang bisa gagal di layar ini.
 */
@Composable
internal fun AuthChoiceScreen(
    onSignIn: () -> Unit,
    onRegister: () -> Unit,
    onContinueAsGuest: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val sky = BoothTheme.sky
    // Seluruh layar berlatar hero; bilah atas milik bingkai aplikasi diminta ikut berwarna sama
    // supaya tidak ada sambungan warna di bawahnya.
    HeroBackdrop()
    BoxWithConstraints(
        modifier = modifier
            .fillMaxSize()
            .background(sky.hero),
    ) {
        // Ilustrasi yang mengalah lebih dulu: ia hanya memakai tinggi yang tersisa setelah judul,
        // teks, dan ketiga aksi (layar pendek), dan dengan huruf besar ia memakai ukuran terkecil,
        // supaya judul dan aksi tidak terdorong jauh ke bawah.
        val artHeight = if (LocalDensity.current.fontScale > LARGE_FONT_SCALE) {
            ArtHeightCompact
        } else {
            (maxHeight - TextAndActionsReserve).coerceIn(ArtHeightCompact, ArtHeight)
        }
        // Bila semuanya muat, aksi berada di dasar layar seperti di desain; bila tidak, seluruh isi
        // digulir sehingga ketiga aksi tetap dapat dicapai.
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .heightIn(min = maxHeight),
            verticalArrangement = Arrangement.SpaceBetween,
        ) {
            Column(
                modifier = Modifier.fillMaxWidth(),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                WelcomeArt(height = artHeight)
                Text(
                    text = stringResource(R.string.auth_choice_title),
                    style = MaterialTheme.typography.headlineMedium,
                    color = sky.onHero,
                    textAlign = TextAlign.Center,
                    modifier = Modifier
                        .padding(horizontal = Spacing.xl)
                        .semantics { heading() },
                )
                Spacer(Modifier.height(Spacing.sm))
                Text(
                    text = stringResource(R.string.auth_choice_message),
                    style = MaterialTheme.typography.bodyLarge,
                    color = sky.onHero,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.padding(horizontal = Spacing.xl),
                )
            }
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    // Jarak atas = jarak minimum dari teks saat layar penuh dan semuanya digulir.
                    .padding(start = Spacing.lg, top = Spacing.lg, end = Spacing.lg, bottom = Spacing.md),
                verticalArrangement = Arrangement.spacedBy(Spacing.sm),
            ) {
                PrimaryButton(
                    text = stringResource(R.string.auth_action_sign_in),
                    onClick = onSignIn,
                    modifier = Modifier.fillMaxWidth(),
                )
                SecondaryButton(
                    text = stringResource(R.string.auth_action_register),
                    onClick = onRegister,
                    modifier = Modifier.fillMaxWidth(),
                )
                AuthTextLink(text = stringResource(R.string.auth_choice_guest), onClick = onContinueAsGuest)
            }
        }
    }
}

/**
 * Ilustrasi sambutan: maskot di dalam kapsul, di depan taburan konfeti. Dekoratif (tanpa semantik);
 * judul di bawahnya yang membawa makna.
 */
@Composable
private fun WelcomeArt(height: Dp, modifier: Modifier = Modifier) {
    val sky = BoothTheme.sky
    // Kapsul mengecil bersama kotaknya; maskot dan jarak atasnya ikut perbandingan yang sama,
    // supaya ikan tetap "mengintip" dari belahan atas kapsul seperti di desain.
    val diameter = minOf(CapsuleDiameter, height * CAPSULE_TO_ART)
    val scale = diameter / CapsuleDiameter
    Box(
        modifier = modifier
            .fillMaxWidth()
            .height(height),
        contentAlignment = Alignment.Center,
    ) {
        Confetti(Modifier.matchParentSize())
        CapsuleFrame(diameter = diameter, lowerColor = sky.capsuleLower(0), seamColor = sky.hero) {
            Mascot(
                Modifier
                    .align(Alignment.TopCenter)
                    .padding(top = MascotTopInset * scale)
                    .width(MascotWidth * scale),
            )
        }
    }
}

// Ukuran desain: kotak ilustrasi 320 dp, kapsul 216 dp, maskot 132 dp yang turun 18 dp dari tepi
// atas kapsul.
private val ArtHeight = 320.dp
private val ArtHeightCompact = 160.dp
private val CapsuleDiameter = 216.dp
private val MascotWidth = 132.dp
private val MascotTopInset = 18.dp
private const val CAPSULE_TO_ART = 216f / 320f

// Perkiraan tinggi judul + teks sambutan (empat baris) + tiga aksi pada skala huruf biasa, dengan
// sedikit kelonggaran. Hanya menentukan tinggi ilustrasi; bila meleset, isi layar tetap dapat digulir.
private val TextAndActionsReserve = 384.dp

// Di atas skala ini teks sambutan dan aksi sudah melebihi satu layar ponsel kecil.
private const val LARGE_FONT_SCALE = 1.3f
