package com.boothpos.customer.feature.account.presentation

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.boothpos.customer.core.ui.component.AppCard
import com.boothpos.customer.core.ui.component.InlineMessage
import com.boothpos.customer.core.ui.component.InlineMessageTone
import com.boothpos.customer.core.ui.component.LoadingState
import com.boothpos.customer.core.ui.component.Mascot
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.component.SecondaryButton
import com.boothpos.customer.core.ui.component.apiErrorMessage
import com.boothpos.customer.core.ui.icon.SkyIcons
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.account.R
import com.boothpos.customer.core.ui.R as CoreUiR

/** Bagian stateful layar Akun: mengumpulkan state, lalu menyerahkannya ke layar stateless. */
@Composable
internal fun AccountScreenRoute(
    onSignIn: () -> Unit,
    onRegister: () -> Unit,
    onEditProfile: () -> Unit,
    onManageAddresses: () -> Unit,
    viewModel: AccountViewModel = hiltViewModel(),
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    AccountScreen(
        state = state,
        onSignIn = onSignIn,
        onRegister = onRegister,
        onEditProfile = onEditProfile,
        onManageAddresses = onManageAddresses,
        onRetryRefresh = viewModel::refresh,
        onSignOutClick = viewModel::requestSignOut,
        onConfirmSignOut = viewModel::confirmSignOut,
        onDismissSignOut = viewModel::dismissSignOut,
    )
}

@Composable
internal fun AccountScreen(
    state: AccountUiState,
    onSignIn: () -> Unit,
    onRegister: () -> Unit,
    onEditProfile: () -> Unit,
    onManageAddresses: () -> Unit,
    onRetryRefresh: () -> Unit,
    onSignOutClick: () -> Unit,
    onConfirmSignOut: () -> Unit,
    onDismissSignOut: () -> Unit,
    modifier: Modifier = Modifier,
) {
    when (state) {
        AccountUiState.Loading -> LoadingState(modifier = modifier)
        is AccountUiState.SignedOut -> SignInPrompt(
            sessionExpired = state.sessionExpired,
            onSignIn = onSignIn,
            onRegister = onRegister,
            modifier = modifier,
        )
        is AccountUiState.Profile -> ProfileContent(
            state = state,
            onEditProfile = onEditProfile,
            onManageAddresses = onManageAddresses,
            onRetryRefresh = onRetryRefresh,
            onSignOutClick = onSignOutClick,
            onConfirmSignOut = onConfirmSignOut,
            onDismissSignOut = onDismissSignOut,
            modifier = modifier,
        )
    }
}

/** Belum masuk: satu aksi utama (Masuk) dan satu pendamping (Daftar). */
@Composable
private fun SignInPrompt(
    sessionExpired: Boolean,
    onSignIn: () -> Unit,
    onRegister: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val scheme = MaterialTheme.colorScheme
    // Di tengah bila muat, seperti keadaan kosong lain di aplikasi; DIGULIR bila tidak (huruf besar,
    // layar pendek), supaya kedua aksi tidak pernah terdorong keluar layar.
    BoxWithConstraints(modifier = modifier.fillMaxSize()) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .heightIn(min = maxHeight)
                .padding(Spacing.lg),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            if (sessionExpired) {
                InlineMessage(text = stringResource(R.string.account_session_expired))
                Spacer(Modifier.height(Spacing.lg))
            }
            MascotBadge(diameter = PromptBadgeSize, mascotWidth = PromptMascotWidth)
            Spacer(Modifier.height(Spacing.md))
            Text(
                text = stringResource(R.string.account_signed_out_title),
                style = MaterialTheme.typography.titleLarge,
                color = scheme.onSurface,
                textAlign = TextAlign.Center,
                modifier = Modifier.semantics { heading() },
            )
            Spacer(Modifier.height(Spacing.sm))
            Text(
                text = stringResource(R.string.account_signed_out_message),
                style = MaterialTheme.typography.bodyLarge,
                color = scheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(Spacing.lg))
            PrimaryButton(
                text = stringResource(R.string.account_action_sign_in),
                onClick = onSignIn,
                modifier = Modifier.fillMaxWidth(),
            )
            Spacer(Modifier.height(Spacing.sm))
            SecondaryButton(
                text = stringResource(R.string.account_action_register),
                onClick = onRegister,
                modifier = Modifier.fillMaxWidth(),
            )
        }
    }
}

@Composable
private fun ProfileContent(
    state: AccountUiState.Profile,
    onEditProfile: () -> Unit,
    onManageAddresses: () -> Unit,
    onRetryRefresh: () -> Unit,
    onSignOutClick: () -> Unit,
    onConfirmSignOut: () -> Unit,
    onDismissSignOut: () -> Unit,
    modifier: Modifier = Modifier,
) {
    BottomActionColumn(
        modifier = modifier,
        bottom = {
            SecondaryButton(
                text = stringResource(R.string.account_action_sign_out),
                onClick = onSignOutClick,
                enabled = !state.signingOut,
                icon = SkyIcons.Logout,
                modifier = Modifier.fillMaxWidth(),
            )
        },
    ) {
        if (state.refreshing) {
            val refreshingLabel = stringResource(R.string.account_refreshing)
            LinearProgressIndicator(
                modifier = Modifier
                    .fillMaxWidth()
                    .semantics { contentDescription = refreshingLabel },
                color = BoothTheme.sky.progress,
                trackColor = BoothTheme.sky.progressTrack,
            )
        }
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(start = Spacing.lg, top = Spacing.sm, end = Spacing.lg),
            verticalArrangement = Arrangement.spacedBy(Spacing.ms),
        ) {
            state.refreshError?.let { error ->
                // Profil dari sesi tetap tampil; pesan ini hanya memberi tahu bahwa ia mungkin belum terbaru.
                InlineMessage(
                    text = stringResource(R.string.account_refresh_failed, apiErrorMessage(error)),
                    tone = InlineMessageTone.Error,
                    actionLabel = stringResource(CoreUiR.string.core_ui_retry),
                    onAction = onRetryRefresh,
                )
            }
            ProfileHeader(name = state.name, email = state.email)
            AppCard(modifier = Modifier.fillMaxWidth()) {
                val phone = state.phone
                ProfileRow(label = stringResource(R.string.account_field_name), value = state.name)
                ProfileRow(label = stringResource(R.string.account_field_email), value = state.email)
                ProfileRow(
                    label = stringResource(R.string.account_field_phone),
                    value = phone ?: stringResource(R.string.account_field_empty),
                    placeholder = phone == null,
                )
            }
            // Tanpa padding kartu: tiap baris menu membawa paddingnya sendiri, sehingga area sentuh
            // dan riaknya selebar kartu dan terpotong mengikuti sudut kartu.
            AppCard(modifier = Modifier.fillMaxWidth(), contentPadding = PaddingValues(0.dp)) {
                MenuRow(
                    icon = SkyIcons.Edit,
                    label = stringResource(R.string.account_action_edit_profile),
                    onClick = onEditProfile,
                    enabled = !state.signingOut,
                )
                HorizontalDivider(modifier = Modifier.padding(horizontal = Spacing.md))
                // Kelola alamat tinggal di modul checkout; :app yang membukanya.
                MenuRow(
                    icon = SkyIcons.Pin,
                    label = stringResource(R.string.account_action_addresses),
                    onClick = onManageAddresses,
                    enabled = !state.signingOut,
                )
            }
        }
    }

    if (state.confirmingSignOut) {
        AlertDialog(
            onDismissRequest = onDismissSignOut,
            title = { Text(stringResource(R.string.account_sign_out_confirm_title)) },
            text = { Text(stringResource(R.string.account_sign_out_confirm_message)) },
            confirmButton = {
                TextButton(onClick = onConfirmSignOut, modifier = Modifier.heightIn(min = Dimens.MinTouchTarget)) {
                    Text(stringResource(R.string.account_action_sign_out))
                }
            },
            dismissButton = {
                TextButton(onClick = onDismissSignOut, modifier = Modifier.heightIn(min = Dimens.MinTouchTarget)) {
                    Text(stringResource(R.string.account_action_cancel))
                }
            },
        )
    }
}

/**
 * Kerangka layar akun: isi di atas, aksi penutup ([bottom]) di dasar layar seperti di desain selama
 * semuanya muat. Bila tidak muat (huruf besar, layar pendek, papan ketik terbuka) seluruhnya
 * digulir, sehingga aksi itu tetap terjangkau. Dipakai layar Akun dan ubah profil.
 */
@Composable
internal fun BottomActionColumn(
    modifier: Modifier = Modifier,
    bottom: @Composable ColumnScope.() -> Unit,
    content: @Composable ColumnScope.() -> Unit,
) {
    BoxWithConstraints(modifier = modifier.fillMaxSize()) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                // Setinggi layar bila isinya lebih pendek: sisa ruangnya jatuh di antara isi dan aksi.
                .heightIn(min = maxHeight),
            verticalArrangement = Arrangement.SpaceBetween,
        ) {
            Column(modifier = Modifier.fillMaxWidth(), content = content)
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    // Jarak atas = jarak minimum dari isi saat layar penuh dan semuanya digulir.
                    .padding(start = Spacing.lg, top = Spacing.lg, end = Spacing.lg, bottom = Spacing.md),
                content = bottom,
            )
        }
    }
}

/**
 * Kepala profil: maskot sebagai avatar, nama, dan e-mail. Dibaca pembaca layar sebagai satu
 * kalimat; rinciannya (dengan label) ada di kartu di bawahnya.
 */
@Composable
private fun ProfileHeader(name: String, email: String, modifier: Modifier = Modifier) {
    val scheme = MaterialTheme.colorScheme
    Row(
        modifier = modifier
            .fillMaxWidth()
            // Desain: 20 dp ke kartu di bawahnya = 8 dp di sini + 12 dp jarak antarkartu.
            .padding(bottom = Spacing.sm)
            .semantics(mergeDescendants = true) {},
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.md),
    ) {
        MascotBadge(diameter = AvatarSize, mascotWidth = AvatarMascotWidth)
        Column(modifier = Modifier.weight(1f)) {
            Text(name, style = MaterialTheme.typography.titleLarge, color = scheme.onSurface)
            Text(email, style = MaterialTheme.typography.bodySmall, color = scheme.onSurfaceVariant)
        }
    }
}

/** Maskot di dalam lingkaran berwarna hero (avatar akun, ajakan masuk). Dekoratif, tanpa semantik. */
@Composable
private fun MascotBadge(diameter: Dp, mascotWidth: Dp, modifier: Modifier = Modifier) {
    Box(
        modifier = modifier
            .size(diameter)
            .clip(CircleShape)
            .background(BoothTheme.sky.hero),
        contentAlignment = Alignment.Center,
    ) {
        Mascot(Modifier.width(mascotWidth))
    }
}

/**
 * Satu baris data profil: label di kiri, nilai di kanan. Label + nilai dibaca pembaca layar sebagai
 * satu kalimat ("Nama, Pelanggan Uji"). [placeholder]: nilainya belum diisi, jadi ditulis redup dan
 * tidak tebal.
 */
@Composable
private fun ProfileRow(label: String, value: String, modifier: Modifier = Modifier, placeholder: Boolean = false) {
    val scheme = MaterialTheme.colorScheme
    val valueStyle = if (placeholder) MaterialTheme.typography.bodyLarge else MaterialTheme.typography.titleMedium
    val valueColor = if (placeholder) scheme.onSurfaceVariant else scheme.onSurface
    val row = modifier
        .fillMaxWidth()
        .padding(vertical = Spacing.xs)
        .semantics(mergeDescendants = true) {}
    if (LocalDensity.current.fontScale > LARGE_FONT_SCALE) {
        // Huruf besar: label dan nilai tidak muat berdampingan di ponsel sempit (nilai akan terpecah
        // per beberapa huruf), jadi nilai turun ke bawah labelnya.
        Column(modifier = row) {
            Text(label, style = MaterialTheme.typography.bodyLarge, color = scheme.onSurfaceVariant)
            Text(value, style = valueStyle, color = valueColor)
        }
    } else {
        Row(
            modifier = row,
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Spacing.ms),
        ) {
            Text(label, style = MaterialTheme.typography.bodyLarge, color = scheme.onSurfaceVariant)
            // Nilai yang panjang (e-mail) turun baris di kolomnya sendiri, tetap rata kanan.
            Text(
                text = value,
                style = valueStyle,
                color = valueColor,
                textAlign = TextAlign.End,
                modifier = Modifier.weight(1f),
            )
        }
    }
}

/**
 * Baris menu di dalam kartu: ubin ikon, label, dan panah. Seluruh baris adalah SATU tombol (peran
 * dan keadaan nonaktif sama seperti tombol biasa); ikon dan panahnya dekoratif.
 */
@Composable
private fun MenuRow(
    icon: ImageVector,
    label: String,
    onClick: () -> Unit,
    enabled: Boolean,
    modifier: Modifier = Modifier,
) {
    val sky = BoothTheme.sky
    Row(
        modifier = modifier
            .fillMaxWidth()
            .clickable(enabled = enabled, role = Role.Button, onClick = onClick)
            .heightIn(min = MenuRowMinHeight)
            .padding(horizontal = Spacing.md, vertical = Spacing.sm)
            // Nonaktif (sedang keluar) tetap terlihat, hanya memudar seperti tombol Material.
            .alpha(if (enabled) 1f else DISABLED_ALPHA),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.ms),
    ) {
        Box(
            modifier = Modifier
                .size(MenuTileSize)
                .clip(MaterialTheme.shapes.small)
                .background(sky.hero),
            contentAlignment = Alignment.Center,
        ) {
            Icon(icon, contentDescription = null, tint = sky.onHero, modifier = Modifier.size(MenuIconSize))
        }
        Text(label, style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
        Icon(
            SkyIcons.ChevronRight,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.size(MenuIconSize),
        )
    }
}

// Ukuran desain layar Akun.
private val AvatarSize = 64.dp
private val AvatarMascotWidth = 54.dp
private val PromptBadgeSize = 96.dp
private val PromptMascotWidth = 80.dp
private val MenuRowMinHeight = 60.dp
private val MenuTileSize = 40.dp
private val MenuIconSize = 20.dp

// Di atas skala ini label dan nilai profil tidak lagi muat berdampingan di ponsel 320 dp.
private const val LARGE_FONT_SCALE = 1.3f
private const val DISABLED_ALPHA = 0.38f
