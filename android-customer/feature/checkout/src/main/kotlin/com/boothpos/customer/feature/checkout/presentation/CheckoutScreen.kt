package com.boothpos.customer.feature.checkout.presentation

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.autofill.ContentType
import androidx.compose.ui.graphics.vector.rememberVectorPainter
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.contentType
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.isTraversalGroup
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.repeatOnLifecycle
import com.boothpos.customer.core.data.domain.model.Address
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.ui.component.ApiErrorState
import com.boothpos.customer.core.ui.component.AppCard
import com.boothpos.customer.core.ui.component.AppTextField
import com.boothpos.customer.core.ui.component.Confetti
import com.boothpos.customer.core.ui.component.EmptyState
import com.boothpos.customer.core.ui.component.IconTile
import com.boothpos.customer.core.ui.component.InlineMessage
import com.boothpos.customer.core.ui.component.InlineMessageTone
import com.boothpos.customer.core.ui.component.LoadingState
import com.boothpos.customer.core.ui.component.Mascot
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.component.ProductImage
import com.boothpos.customer.core.ui.component.SecondaryButton
import com.boothpos.customer.core.ui.component.SectionLabel
import com.boothpos.customer.core.ui.component.SegmentedProgress
import com.boothpos.customer.core.ui.component.SelectionMark
import com.boothpos.customer.core.ui.component.apiErrorMessage
import com.boothpos.customer.core.ui.component.paletteSeed
import com.boothpos.customer.core.ui.format.RupiahFormatter
import com.boothpos.customer.core.ui.icon.SkyIcons
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.HeroBackdrop
import com.boothpos.customer.core.ui.theme.PillShape
import com.boothpos.customer.core.ui.theme.SoftShadow
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.core.ui.theme.softShadow
import com.boothpos.customer.feature.checkout.R
import com.boothpos.customer.feature.checkout.domain.CartIssueReason
import com.boothpos.customer.feature.checkout.domain.Quote
import com.boothpos.customer.feature.checkout.presentation.address.AddressText
import com.boothpos.customer.feature.checkout.presentation.address.CardLink
import com.boothpos.customer.feature.checkout.presentation.address.fieldIssueText
import com.boothpos.customer.core.ui.R as CoreUiR

internal object CheckoutTestTags {
    const val TOTAL = "checkout_total"
    const val SHIPPING = "checkout_shipping"
}

/** Semua aksi layar checkout dalam satu wadah tak berubah, supaya layar stateless mudah diuji. */
internal class CheckoutActions(
    val onRetryLoad: () -> Unit = {},
    val onSignIn: () -> Unit = {},
    val onBackToCart: () -> Unit = {},
    val onViewOrder: () -> Unit = {},
    val onSelectMethod: (FulfilmentMethod) -> Unit = {},
    val onContinueFromMethod: () -> Unit = {},
    val onContactNameChange: (String) -> Unit = {},
    val onContactPhoneChange: (String) -> Unit = {},
    val onNotesChange: (String) -> Unit = {},
    val onSelectAddress: (Long) -> Unit = {},
    val onAddAddress: () -> Unit = {},
    val onEditAddress: (Long) -> Unit = {},
    val onRetryAddresses: () -> Unit = {},
    val onContinueToReview: () -> Unit = {},
    val onRetryQuote: () -> Unit = {},
    val onPlaceOrder: () -> Unit = {},
    val onDismissPriceChange: () -> Unit = {},
    val onFixCart: () -> Unit = {},
    val onRetryUnknownStatus: () -> Unit = {},
)

/**
 * Bagian stateful checkout: state, kejadian sekali jalan (masuk, pesanan dibuat, kembali ke
 * keranjang), dan tombol kembali yang mundur satu langkah.
 *
 * @param onExit menutup checkout seluruhnya (dipakai setelah konfirmasi saat status belum diketahui).
 */
@Composable
internal fun CheckoutScreenRoute(
    onSignIn: () -> Unit,
    onBackToCart: () -> Unit,
    onOrderPlaced: (String) -> Unit,
    onExit: () -> Unit,
    onAddAddress: () -> Unit,
    onEditAddress: (Long) -> Unit,
    viewModel: CheckoutViewModel = hiltViewModel(),
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val lifecycleOwner = LocalLifecycleOwner.current
    val currentOnSignIn by rememberUpdatedState(onSignIn)
    val currentOnBackToCart by rememberUpdatedState(onBackToCart)
    val currentOnOrderPlaced by rememberUpdatedState(onOrderPlaced)
    var confirmLeave by rememberSaveable { mutableStateOf(false) }

    LaunchedEffect(viewModel, lifecycleOwner) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
            viewModel.events.collect { event ->
                when (event) {
                    CheckoutEvent.RequireSignIn -> currentOnSignIn()
                    CheckoutEvent.BackToCart -> currentOnBackToCart()
                    is CheckoutEvent.OrderPlaced -> currentOnOrderPlaced(event.orderNumber)
                }
            }
        }
    }
    // Berjalan setiap kali layar ini tampil lagi (mis. kembali dari formulir alamat).
    LaunchedEffect(viewModel) { viewModel.onScreenShown() }

    val form = state as? CheckoutUiState.Form
    BackHandler(enabled = form != null && (form.step != CheckoutStep.Method || form.submitting || form.statusUnknown)) {
        when {
            form == null -> Unit
            // Pesanan sedang dikirim: hasilnya harus ditunggu di layar ini.
            form.submitting -> Unit
            form.statusUnknown -> confirmLeave = true
            else -> viewModel.back()
        }
    }

    val actions = remember(viewModel, onAddAddress, onEditAddress) {
        CheckoutActions(
            onRetryLoad = viewModel::retryLoad,
            onSignIn = viewModel::requestSignIn,
            onBackToCart = viewModel::fixCart,
            onViewOrder = viewModel::viewPlacedOrder,
            onSelectMethod = viewModel::selectMethod,
            onContinueFromMethod = viewModel::continueFromMethod,
            onContactNameChange = viewModel::onContactNameChange,
            onContactPhoneChange = viewModel::onContactPhoneChange,
            onNotesChange = viewModel::onNotesChange,
            onSelectAddress = viewModel::selectAddress,
            onAddAddress = onAddAddress,
            onEditAddress = onEditAddress,
            onRetryAddresses = viewModel::retryAddresses,
            onContinueToReview = viewModel::continueToReview,
            onRetryQuote = viewModel::retryQuote,
            onPlaceOrder = viewModel::placeOrder,
            onDismissPriceChange = viewModel::dismissPriceChange,
            onFixCart = viewModel::fixCart,
            onRetryUnknownStatus = viewModel::retryUnknownStatus,
        )
    }
    CheckoutScreen(state = state, actions = actions)

    if (confirmLeave) {
        AlertDialog(
            onDismissRequest = { confirmLeave = false },
            title = { Text(stringResource(R.string.checkout_leave_unknown_title)) },
            text = { Text(stringResource(R.string.checkout_leave_unknown_message)) },
            confirmButton = {
                TextButton(
                    onClick = { confirmLeave = false },
                    modifier = Modifier.heightIn(min = Dimens.MinTouchTarget),
                ) { Text(stringResource(R.string.checkout_leave_unknown_stay)) }
            },
            dismissButton = {
                TextButton(
                    onClick = {
                        confirmLeave = false
                        onExit()
                    },
                    modifier = Modifier.heightIn(min = Dimens.MinTouchTarget),
                ) { Text(stringResource(R.string.checkout_leave_unknown_leave)) }
            },
        )
    }
}

/**
 * Checkout stateless. Satu kolom dengan satu aksi utama per langkah yang selalu terlihat di bawah;
 * di layar lebar bingkai aplikasi memusatkannya dengan lebar maksimum.
 */
@Composable
internal fun CheckoutScreen(state: CheckoutUiState, actions: CheckoutActions, modifier: Modifier = Modifier) {
    when (state) {
        CheckoutUiState.Loading -> LoadingState(modifier = modifier)
        is CheckoutUiState.LoadFailed -> ApiErrorState(
            error = state.error,
            onRetry = actions.onRetryLoad,
            retrying = state.retrying,
            modifier = modifier,
        )
        CheckoutUiState.SignInRequired -> EmptyState(
            title = stringResource(R.string.checkout_sign_in_title),
            message = stringResource(R.string.checkout_sign_in_message),
            icon = rememberVectorPainter(SkyIcons.Lock),
            actionLabel = stringResource(R.string.checkout_sign_in_action),
            onAction = actions.onSignIn,
            modifier = modifier,
        )
        CheckoutUiState.EmptyCart -> EmptyState(
            title = stringResource(R.string.checkout_empty_cart_title),
            message = stringResource(R.string.checkout_empty_cart_message),
            icon = rememberVectorPainter(SkyIcons.Cart),
            actionLabel = stringResource(R.string.checkout_empty_cart_action),
            onAction = actions.onBackToCart,
            modifier = modifier,
        )
        is CheckoutUiState.Placed -> PlacedContent(
            orderNumber = state.orderNumber,
            onViewOrder = actions.onViewOrder,
            modifier = modifier,
        )
        is CheckoutUiState.Form -> Column(modifier = modifier.fillMaxSize()) {
            StepHeader(state.step)
            when (state.step) {
                CheckoutStep.Method -> MethodStep(state, actions)
                CheckoutStep.Details -> DetailsStep(state, actions)
                CheckoutStep.Review -> ReviewStep(state, actions)
            }
        }
    }
}

// --- pesanan dibuat ---

/**
 * Pesanan tercatat di server. Seluruh keadaan ini berlatar hero, dan bilah atas aplikasi diminta
 * ikut berwarna sama supaya tidak ada sambungan warna di bawahnya. Aksi "Lihat pesanan"
 * disematkan di bawah; sisanya menggulir bila huruf besar atau layar pendek.
 */
@Composable
private fun PlacedContent(orderNumber: String, onViewOrder: () -> Unit, modifier: Modifier = Modifier) {
    val sky = BoothTheme.sky
    HeroBackdrop()
    CompositionLocalProvider(LocalContentColor provides sky.onHero) {
        Column(
            modifier = modifier
                .fillMaxSize()
                .background(sky.hero),
        ) {
            Column(
                modifier = Modifier
                    .weight(1f)
                    .fillMaxWidth()
                    .verticalScroll(rememberScrollState())
                    .padding(bottom = Spacing.lg),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                PlacedIllustration()
                Column(
                    modifier = Modifier
                        .widthIn(max = Dimens.ContentMaxWidth)
                        .padding(horizontal = Spacing.xl)
                        .semantics(mergeDescendants = true) {},
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Text(
                        text = stringResource(R.string.checkout_placed_title),
                        style = MaterialTheme.typography.headlineMedium,
                        textAlign = TextAlign.Center,
                    )
                    Spacer(Modifier.height(Spacing.sm))
                    Text(
                        text = stringResource(R.string.checkout_placed_message, orderNumber),
                        style = MaterialTheme.typography.bodyLarge,
                        textAlign = TextAlign.Center,
                    )
                }
                Spacer(Modifier.height(Spacing.lg))
                AppCard(
                    modifier = Modifier
                        .widthIn(max = Dimens.ContentMaxWidth)
                        .fillMaxWidth()
                        .padding(horizontal = Spacing.lg)
                        // Nomor yang sama sudah dibacakan di kalimat di atasnya; kartu ini hanya
                        // menampilkannya besar supaya mudah dibaca dan dicatat.
                        .clearAndSetSemantics {},
                    contentPadding = PaddingValues(PlacedCardPadding),
                ) {
                    Text(text = orderNumber, style = MaterialTheme.typography.titleLarge)
                }
            }
            PrimaryButton(
                text = stringResource(R.string.checkout_placed_action),
                onClick = onViewOrder,
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = Spacing.lg, vertical = Spacing.md),
            )
        }
    }
}

/**
 * Maskot gembira di atas taburan konfeti, dengan lencana centang yang menumpang di kanan
 * bawahnya. Seluruhnya dekoratif: maknanya dibawa judul "Pesanan dibuat" di bawahnya.
 */
@Composable
private fun PlacedIllustration() {
    val scheme = MaterialTheme.colorScheme
    val sky = BoothTheme.sky
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .height(IllustrationHeight),
        contentAlignment = Alignment.Center,
    ) {
        Confetti(Modifier.matchParentSize())
        Box {
            Mascot(Modifier.width(MascotWidth), happy = true)
            // Cincin sewarna latar memisahkan lencana dari badan maskot yang ditumpanginya.
            Box(
                modifier = Modifier
                    .align(Alignment.BottomEnd)
                    .offset(x = BadgeOffsetX, y = BadgeOffsetY)
                    .size(BadgeSize + BadgeRing * 2)
                    .softShadow(CircleShape, sky.actionGlow, SoftShadow.Glow)
                    .background(sky.hero, CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                Box(
                    modifier = Modifier
                        .size(BadgeSize)
                        .background(scheme.primaryContainer, CircleShape),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(
                        SkyIcons.Check,
                        contentDescription = null,
                        tint = scheme.onPrimaryContainer,
                        modifier = Modifier.size(BadgeIconSize),
                    )
                }
            }
        }
    }
}

// --- kerangka langkah ---

/**
 * Kepala tiap langkah: batang progres, teks langkah, lalu judul langkah. Batang progres dekoratif;
 * posisi langkah selalu tertulis sebagai teks di bawahnya.
 */
@Composable
private fun StepHeader(step: CheckoutStep) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .padding(start = Spacing.lg, end = Spacing.lg, top = Spacing.xs, bottom = Spacing.sm),
    ) {
        SegmentedProgress(total = CheckoutStep.entries.size, filled = step.ordinal + 1)
        Text(
            text = stringResource(
                when (step) {
                    CheckoutStep.Method -> R.string.checkout_step_method
                    CheckoutStep.Details -> R.string.checkout_step_details
                    CheckoutStep.Review -> R.string.checkout_step_review
                },
            ),
            // Langkah 2 dan 3 tidak punya judul tersendiri, jadi baris langkahnya sendiri yang tampil
            // sebagai judul; di langkah 1 ia hanya keterangan kecil di atas judulnya.
            style = if (step == CheckoutStep.Method) {
                MaterialTheme.typography.bodySmall
            } else {
                MaterialTheme.typography.titleMedium
            },
            color = if (step == CheckoutStep.Method) {
                MaterialTheme.colorScheme.onSurfaceVariant
            } else {
                MaterialTheme.colorScheme.onSurface
            },
            modifier = Modifier.padding(top = Spacing.ms),
        )
        // Hanya langkah pertama yang punya teks judul tersendiri. Langkah 2 dan 3 tidak punya
        // (namanya sudah ada di baris langkah di atas), dan teks baru tidak boleh dikarang di sini.
        if (step == CheckoutStep.Method) {
            Text(
                text = stringResource(R.string.checkout_method_title),
                style = MaterialTheme.typography.titleLarge,
                modifier = Modifier.semantics { heading() },
            )
        }
    }
}

/** Konten yang dapat digulir + bilah bawah berisi aksi utama langkah itu. */
@Composable
private fun ColumnScope.StepLayout(
    bottomBar: @Composable ColumnScope.() -> Unit,
    content: @Composable ColumnScope.() -> Unit,
) {
    Column(
        modifier = Modifier
            .weight(1f)
            .fillMaxWidth()
            .verticalScroll(rememberScrollState())
            // Bantalan atas memberi ruang bagi bayangan kartu pertama di bawah kepala langkah.
            .padding(start = Spacing.lg, end = Spacing.lg, top = Spacing.sm, bottom = Spacing.lg),
        verticalArrangement = Arrangement.spacedBy(Spacing.ms),
        content = content,
    )
    // Tanpa Surface: Surface memotong isinya, sehingga pendar tombol utama ikut terpotong.
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .imePadding()
            .padding(horizontal = Spacing.lg, vertical = Spacing.md)
            // Total dan tombolnya dijelajahi pembaca layar sebagai satu kelompok.
            .semantics { isTraversalGroup = true },
        verticalArrangement = Arrangement.spacedBy(Spacing.sm),
        content = bottomBar,
    )
}

// --- langkah 1: cara pengambilan ---

@Composable
private fun ColumnScope.MethodStep(state: CheckoutUiState.Form, actions: CheckoutActions) {
    StepLayout(
        bottomBar = {
            PrimaryButton(
                text = stringResource(R.string.checkout_action_continue),
                onClick = actions.onContinueFromMethod,
                modifier = Modifier.fillMaxWidth(),
            )
        },
    ) {
        if (state.methodMissing) {
            InlineMessage(text = stringResource(R.string.checkout_method_missing), tone = InlineMessageTone.Error)
        }
        Column(
            modifier = Modifier.selectableGroup(),
            verticalArrangement = Arrangement.spacedBy(Spacing.ms),
        ) {
            state.methods.forEach { method ->
                MethodOption(
                    method = method,
                    selected = method == state.method,
                    onClick = { actions.onSelectMethod(method) },
                )
            }
        }
        state.instructions?.let { instructions ->
            AppCard(modifier = Modifier.fillMaxWidth()) { StoreInstructions(instructions) }
        }
    }
}

/**
 * Kartu pilihan tunggal: seluruh kartu adalah target sentuhnya, bukan hanya tanda pilihannya.
 * Tanda pilihan dan ubin ikon dekoratif; keadaan terpilih dibawa semantik `selectable` kartunya.
 */
@Composable
private fun MethodOption(method: FulfilmentMethod, selected: Boolean, onClick: () -> Unit) {
    AppCard(
        modifier = Modifier.fillMaxWidth(),
        selected = selected,
        interaction = Modifier.selectable(selected = selected, role = Role.RadioButton, onClick = onClick),
    ) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Spacing.ms),
        ) {
            IconTile(
                when (method) {
                    FulfilmentMethod.Pickup -> SkyIcons.Store
                    FulfilmentMethod.Courier -> SkyIcons.Truck
                },
            )
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = stringResource(
                        when (method) {
                            FulfilmentMethod.Pickup -> R.string.checkout_method_pickup
                            FulfilmentMethod.Courier -> R.string.checkout_method_courier
                        },
                    ),
                    style = MaterialTheme.typography.titleMedium,
                )
                Text(
                    text = stringResource(
                        when (method) {
                            FulfilmentMethod.Pickup -> R.string.checkout_method_pickup_hint
                            FulfilmentMethod.Courier -> R.string.checkout_method_courier_hint
                        },
                    ),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            SelectionMark(selected)
        }
    }
}

/**
 * Petunjuk dari toko, ditampilkan apa adanya sebagai teks polos. Dipanggil di dalam kartu: kartu
 * sendiri di langkah 1, kartu "cara menerima pesanan" di ringkasan.
 */
@Composable
private fun StoreInstructions(instructions: String) {
    SectionLabel(stringResource(R.string.checkout_instructions_title))
    Spacer(Modifier.height(Spacing.xs))
    Text(text = instructions, style = MaterialTheme.typography.bodyLarge)
}

// --- langkah 2: alamat dan kontak ---

@Composable
private fun ColumnScope.DetailsStep(state: CheckoutUiState.Form, actions: CheckoutActions) {
    val nameLabel = stringResource(R.string.checkout_contact_name)
    val phoneLabel = stringResource(R.string.checkout_contact_phone)
    val notesLabel = stringResource(R.string.checkout_notes)
    StepLayout(
        bottomBar = {
            PrimaryButton(
                text = stringResource(R.string.checkout_action_to_review),
                onClick = actions.onContinueToReview,
                modifier = Modifier.fillMaxWidth(),
            )
        },
    ) {
        if (state.needsAddress) {
            AddressSection(state, actions)
        }
        Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
            SectionLabel(stringResource(R.string.checkout_contact_title))
            Text(
                text = stringResource(R.string.checkout_contact_hint),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            AppTextField(
                value = state.contactName,
                onValueChange = actions.onContactNameChange,
                label = nameLabel,
                error = fieldErrorText(state.contactNameError, nameLabel),
                keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Words, imeAction = ImeAction.Next),
                modifier = Modifier.semantics { contentType = ContentType.PersonFullName },
            )
            AppTextField(
                value = state.contactPhone,
                onValueChange = actions.onContactPhoneChange,
                label = phoneLabel,
                error = fieldErrorText(state.contactPhoneError, phoneLabel),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone, imeAction = ImeAction.Next),
                modifier = Modifier.semantics { contentType = ContentType.PhoneNumber },
            )
            AppTextField(
                value = state.notes,
                onValueChange = actions.onNotesChange,
                label = stringResource(R.string.checkout_notes_optional),
                error = fieldErrorText(state.notesError, notesLabel),
                supportingText = stringResource(R.string.checkout_notes_hint),
                singleLine = false,
                keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences),
            )
        }
    }
}

@Composable
private fun AddressSection(state: CheckoutUiState.Form, actions: CheckoutActions) {
    val addresses = state.addresses
    val hasSavedAddresses = addresses is AddressesState.Loaded && addresses.addresses.isNotEmpty()
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.ms)) {
        // FlowRow: dengan huruf besar atau layar sempit tautan turun ke baris berikutnya alih-alih
        // menjepit label bagian.
        FlowRow(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            itemVerticalAlignment = Alignment.CenterVertically,
        ) {
            SectionLabel(stringResource(R.string.checkout_address_title))
            if (hasSavedAddresses) {
                CardLink(
                    text = stringResource(R.string.checkout_address_add),
                    onClick = actions.onAddAddress,
                    textStyle = MaterialTheme.typography.labelMedium,
                )
            }
        }
        if (state.addressMissing) {
            InlineMessage(text = stringResource(R.string.checkout_address_missing), tone = InlineMessageTone.Error)
        }
        when (addresses) {
            AddressesState.NotLoaded, AddressesState.Loading -> Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier.semantics(mergeDescendants = true) {},
            ) {
                CircularProgressIndicator(modifier = Modifier.size(LoadingIndicatorSize), strokeWidth = 2.dp)
                Spacer(Modifier.width(Spacing.sm))
                Text(
                    text = stringResource(R.string.checkout_address_loading),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            is AddressesState.Failed -> InlineMessage(
                text = stringResource(R.string.checkout_address_load_failed, apiErrorMessage(addresses.error)),
                tone = InlineMessageTone.Error,
                actionLabel = stringResource(CoreUiR.string.core_ui_retry),
                onAction = actions.onRetryAddresses,
            )
            is AddressesState.Loaded -> if (addresses.addresses.isEmpty()) {
                // Belum ada alamat: menambah alamat adalah satu-satunya jalan maju, jadi aksinya
                // tombol penuh di dalam kartu, bukan tautan kecil di kepala bagian.
                AppCard(modifier = Modifier.fillMaxWidth()) {
                    Text(
                        text = stringResource(R.string.checkout_address_empty),
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Spacer(Modifier.height(Spacing.ms))
                    SecondaryButton(
                        text = stringResource(R.string.checkout_address_add),
                        onClick = actions.onAddAddress,
                        icon = SkyIcons.Plus,
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
            } else {
                Column(
                    modifier = Modifier.selectableGroup(),
                    verticalArrangement = Arrangement.spacedBy(Spacing.ms),
                ) {
                    addresses.addresses.forEach { address ->
                        AddressOption(
                            address = address,
                            selected = address.id == state.selectedAddressId,
                            onSelect = { actions.onSelectAddress(address.id) },
                            onEdit = { actions.onEditAddress(address.id) },
                        )
                    }
                }
            }
        }
    }
}

/**
 * Satu alamat sebagai kartu pilihan. Tautan "Ubah" ada di dalam kartu tetapi tetap simpul
 * tersendiri: ketukan di luar tautan memilih alamat, ketukan pada tautan membuka formulirnya.
 */
@Composable
private fun AddressOption(address: Address, selected: Boolean, onSelect: () -> Unit, onEdit: () -> Unit) {
    val scheme = MaterialTheme.colorScheme
    AppCard(
        modifier = Modifier.fillMaxWidth(),
        selected = selected,
        interaction = Modifier.selectable(selected = selected, role = Role.RadioButton, onClick = onSelect),
        // Tautan "Ubah" sudah membawa ruang kosongnya sendiri (tinggi sentuh 48 dp).
        contentPadding = PaddingValues(start = Spacing.md, top = Spacing.md, end = Spacing.md, bottom = Spacing.xs),
    ) {
        Row(horizontalArrangement = Arrangement.spacedBy(Spacing.ms)) {
            AddressText(address, modifier = Modifier.weight(1f))
            SelectionMark(selected)
        }
        // Beberapa tombol "Ubah" tampil sekaligus: pembaca layar perlu tahu alamat mana yang dimaksud.
        val editLabel = stringResource(
            R.string.checkout_address_edit_named,
            address.label?.takeIf { it.isNotBlank() } ?: address.recipientName,
        )
        CardLink(
            text = stringResource(R.string.checkout_address_edit),
            onClick = onEdit,
            icon = SkyIcons.Edit,
            // Biru tautan di atas isi kuning lembut kartu terpilih tidak lolos kontras AA.
            contentColor = if (selected) scheme.onSecondaryContainer else scheme.primary,
            modifier = Modifier
                .align(Alignment.End)
                .semantics { contentDescription = editLabel },
        )
    }
}

@Composable
private fun fieldErrorText(error: CheckoutFieldError?, fieldLabel: String): String? = when (error) {
    null -> null
    is CheckoutFieldError.Server -> error.message
    is CheckoutFieldError.Local -> fieldIssueText(error.issue, fieldLabel)
}

// --- langkah 3: ringkasan ---

@Composable
private fun ColumnScope.ReviewStep(state: CheckoutUiState.Form, actions: CheckoutActions) {
    when (val review = state.review) {
        ReviewState.Idle, ReviewState.LoadingQuote ->
            LoadingState(label = stringResource(R.string.checkout_quote_loading), modifier = Modifier.weight(1f))
        is ReviewState.QuoteFailed ->
            ApiErrorState(error = review.error, onRetry = actions.onRetryQuote, modifier = Modifier.weight(1f))
        is ReviewState.CartInvalid -> CartInvalidContent(review, actions)
        is ReviewState.Ready -> ReadyContent(state, review, actions)
        is ReviewState.StatusUnknown -> StatusUnknownContent(review, actions)
    }
}

@Composable
private fun ColumnScope.CartInvalidContent(review: ReviewState.CartInvalid, actions: CheckoutActions) {
    StepLayout(
        bottomBar = {
            PrimaryButton(
                text = stringResource(R.string.checkout_cart_invalid_action),
                onClick = actions.onFixCart,
                modifier = Modifier.fillMaxWidth(),
            )
        },
    ) {
        InlineMessage(text = stringResource(R.string.checkout_cart_invalid_message), tone = InlineMessageTone.Error)
        AppCard(modifier = Modifier.fillMaxWidth()) {
            review.lines.forEachIndexed { index, line ->
                if (index > 0) Spacer(Modifier.height(Spacing.ms))
                // Ikon + teks: alasan tidak disampaikan lewat warna saja.
                Row(
                    modifier = Modifier.semantics(mergeDescendants = true) {},
                    horizontalArrangement = Arrangement.spacedBy(Spacing.ms),
                ) {
                    Icon(
                        SkyIcons.Alert,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.error,
                        // Sejajar dengan baris pertama teks (tinggi baris 24 sp, ikon 20 dp).
                        modifier = Modifier
                            .padding(top = IssueIconInset)
                            .size(IssueIconSize),
                    )
                    Column(modifier = Modifier.weight(1f)) {
                        Text(
                            text = line.name ?: stringResource(R.string.checkout_cart_invalid_unknown_item),
                            style = MaterialTheme.typography.titleMedium,
                        )
                        Text(
                            text = when (line.reason) {
                                CartIssueReason.Unavailable -> stringResource(R.string.checkout_cart_issue_unavailable)
                                CartIssueReason.InsufficientStock ->
                                    stringResource(R.string.checkout_cart_issue_stock, line.maxQuantity.toString())
                                CartIssueReason.QuantityLimit ->
                                    stringResource(R.string.checkout_cart_issue_limit, line.maxQuantity.toString())
                            },
                            style = MaterialTheme.typography.bodyMedium,
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun ColumnScope.ReadyContent(
    state: CheckoutUiState.Form,
    review: ReviewState.Ready,
    actions: CheckoutActions,
) {
    val quote = review.quote
    StepLayout(
        bottomBar = {
            TotalRow(quote)
            PrimaryButton(
                text = stringResource(R.string.checkout_action_place_order),
                onClick = actions.onPlaceOrder,
                loading = review.placing,
                modifier = Modifier.fillMaxWidth(),
            )
        },
    ) {
        review.submitError?.let { error ->
            InlineMessage(text = apiErrorMessage(error), tone = InlineMessageTone.Error)
        }
        when (review.notice) {
            ReviewNotice.PricesUpdated -> InlineMessage(text = stringResource(R.string.checkout_notice_prices_updated))
            ReviewNotice.ConfirmAgain -> InlineMessage(text = stringResource(R.string.checkout_notice_confirm_again))
            null -> Unit
        }
        AppCard(modifier = Modifier.fillMaxWidth()) { QuoteLines(quote) }
        AppCard(modifier = Modifier.fillMaxWidth()) { FulfilmentSummary(state) }
        AppCard(modifier = Modifier.fillMaxWidth()) { CostRows(quote) }
    }

    review.priceChange?.let { change ->
        AlertDialog(
            onDismissRequest = actions.onDismissPriceChange,
            title = { Text(stringResource(R.string.checkout_price_changed_title)) },
            text = {
                Text(
                    stringResource(
                        R.string.checkout_price_changed_message,
                        RupiahFormatter.format(change.previousTotal),
                        RupiahFormatter.format(change.newTotal),
                    ),
                )
            },
            confirmButton = {
                TextButton(
                    onClick = actions.onDismissPriceChange,
                    modifier = Modifier.heightIn(min = Dimens.MinTouchTarget),
                ) { Text(stringResource(R.string.checkout_price_changed_action)) }
            },
        )
    }
}

/** Isi kartu "Barang": satu baris per barang dari quote server. */
@Composable
private fun QuoteLines(quote: Quote) {
    SectionLabel(stringResource(R.string.checkout_review_items_title))
    quote.lines.forEach { line ->
        // Nama, jumlah x harga, dan jumlah harga dibaca sebagai satu baris.
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(top = Spacing.sm)
                .semantics(mergeDescendants = true) {},
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Spacing.ms),
        ) {
            // Dekoratif: nama barang di sebelahnya sudah menyebutnya.
            ProductImage(
                imageUrl = line.imageUrl,
                contentDescription = null,
                size = LineImageSize,
                paletteSeed = paletteSeed(line.name),
            )
            Column(modifier = Modifier.weight(1f)) {
                Text(text = line.name, style = MaterialTheme.typography.titleMedium)
                // Jumlah harga di ujung baris kedua, bukan di samping nama: dengan huruf besar ia
                // turun ke baris sendiri alih-alih menjepit nama barang menjadi satu huruf per baris.
                FlowRow(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    itemVerticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        text = stringResource(
                            R.string.checkout_review_line_quantity,
                            line.quantity.toString(),
                            RupiahFormatter.format(line.unitPrice),
                        ),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Text(text = RupiahFormatter.format(line.lineTotal), style = MaterialTheme.typography.titleMedium)
                }
            }
        }
    }
}

/** Isi kartu "Cara menerima pesanan": metode, alamat (kurir), petunjuk toko, lalu kontak pemesan. */
@Composable
private fun FulfilmentSummary(state: CheckoutUiState.Form) {
    val courier = state.method == FulfilmentMethod.Courier
    SectionLabel(stringResource(R.string.checkout_review_fulfilment_title))
    Spacer(Modifier.height(Spacing.sm))
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.ms),
    ) {
        IconTile(if (courier) SkyIcons.Truck else SkyIcons.Store)
        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = stringResource(if (courier) R.string.checkout_method_courier else R.string.checkout_method_pickup),
                style = MaterialTheme.typography.titleMedium,
            )
            state.selectedAddress?.takeIf { state.needsAddress }?.let { address ->
                AddressText(address, showDefaultMark = false, titleStyle = MaterialTheme.typography.labelMedium)
            }
        }
    }
    state.instructions?.let { instructions ->
        Spacer(Modifier.height(Spacing.ms))
        StoreInstructions(instructions)
    }
    HorizontalDivider(modifier = Modifier.padding(vertical = Spacing.ms))
    SectionLabel(stringResource(R.string.checkout_contact_title))
    Spacer(Modifier.height(Spacing.xs))
    Text(
        text = stringResource(R.string.checkout_review_contact, state.contactName.trim(), state.contactPhone.trim()),
        style = MaterialTheme.typography.bodyLarge,
    )
    state.notes.trim().takeIf { it.isNotEmpty() }?.let { notes ->
        Text(
            text = stringResource(R.string.checkout_review_notes, notes),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

/**
 * Isi kartu "Rincian biaya": subtotal dan ongkos kirim dari quote server; tidak ada biaya lain yang
 * muncul belakangan. Total sengaja TIDAK di kartu ini: ia disematkan di atas tombol "Buat pesanan"
 * supaya selalu terlihat tanpa menggulir.
 */
@Composable
private fun CostRows(quote: Quote) {
    SectionLabel(stringResource(R.string.checkout_review_cost_title))
    Spacer(Modifier.height(Spacing.xs))
    CostRow(label = stringResource(R.string.checkout_review_subtotal)) {
        Text(text = RupiahFormatter.format(quote.subtotal), style = MaterialTheme.typography.bodyLarge)
    }
    CostRow(
        label = stringResource(R.string.checkout_review_shipping),
        modifier = Modifier.testTag(CheckoutTestTags.SHIPPING),
    ) {
        if (quote.shippingCostPending) {
            NotePill(stringResource(R.string.checkout_review_shipping_pending))
        } else {
            Text(text = RupiahFormatter.format(quote.shippingCost), style = MaterialTheme.typography.bodyLarge)
        }
    }
}

/**
 * Baris label + nilai. FlowRow: bila keduanya tidak muat sebaris (huruf besar, atau nilai berupa
 * catatan panjang), nilainya turun ke baris berikutnya alih-alih menjepit labelnya.
 */
@Composable
private fun CostRow(label: String, modifier: Modifier = Modifier, value: @Composable () -> Unit) {
    FlowRow(
        modifier = modifier
            .fillMaxWidth()
            .padding(vertical = Spacing.xs)
            .semantics(mergeDescendants = true) {},
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalArrangement = Arrangement.spacedBy(Spacing.xs),
        itemVerticalAlignment = Alignment.CenterVertically,
    ) {
        Text(text = label, style = MaterialTheme.typography.bodyLarge)
        value()
    }
}

/** Catatan koral berbentuk pil. Maknanya dibawa teksnya; warna hanya penguat. */
@Composable
private fun NotePill(text: String, modifier: Modifier = Modifier) {
    val scheme = MaterialTheme.colorScheme
    Text(
        text = text,
        style = MaterialTheme.typography.labelMedium,
        color = scheme.onTertiaryContainer,
        modifier = modifier
            .background(scheme.tertiaryContainer, PillShape)
            .padding(horizontal = Spacing.ms, vertical = Spacing.xs),
    )
}

/** Total dari quote, selalu terlihat di atas tombol "Buat pesanan". */
@Composable
private fun TotalRow(quote: Quote) {
    Column {
        FlowRow(
            modifier = Modifier
                .fillMaxWidth()
                .testTag(CheckoutTestTags.TOTAL)
                .semantics(mergeDescendants = true) {},
            horizontalArrangement = Arrangement.SpaceBetween,
            itemVerticalAlignment = Alignment.CenterVertically,
        ) {
            Text(text = stringResource(R.string.checkout_review_total), style = MaterialTheme.typography.titleMedium)
            Text(text = RupiahFormatter.format(quote.total), style = MaterialTheme.typography.titleLarge)
        }
        if (quote.shippingCostPending) {
            Text(
                text = stringResource(R.string.checkout_review_total_excludes_shipping),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(top = Spacing.xs),
            )
        }
    }
}

/**
 * Jawaban server tidak sampai. Layar ini sengaja TIDAK menyebut pesanan gagal: satu-satunya aksi
 * adalah memeriksa (mengirim ulang dengan kunci yang sama), dan itu tidak membuat pesanan kedua.
 */
@Composable
private fun ColumnScope.StatusUnknownContent(review: ReviewState.StatusUnknown, actions: CheckoutActions) {
    val sky = BoothTheme.sky
    Column(
        modifier = Modifier
            .weight(1f)
            .fillMaxWidth()
            .verticalScroll(rememberScrollState())
            .padding(Spacing.lg),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Column(
            modifier = Modifier
                .widthIn(max = Dimens.ContentMaxWidth)
                .semantics(mergeDescendants = true) { liveRegion = LiveRegionMode.Polite },
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            // Dekoratif: judul di bawahnya sudah menyampaikan maknanya.
            Box(
                modifier = Modifier
                    .size(StateIconTile)
                    .background(sky.hero, CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    SkyIcons.Info,
                    contentDescription = null,
                    tint = sky.onHero,
                    modifier = Modifier.size(StateIconSize),
                )
            }
            Spacer(Modifier.height(Spacing.md))
            Text(
                text = stringResource(R.string.checkout_unknown_title),
                style = MaterialTheme.typography.titleLarge,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(Spacing.sm))
            Text(
                text = stringResource(R.string.checkout_unknown_message),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )
        }
        Spacer(Modifier.height(Spacing.lg))
        PrimaryButton(
            text = stringResource(R.string.checkout_unknown_action),
            onClick = actions.onRetryUnknownStatus,
            loading = review.checking,
        )
    }
}

// Ilustrasi "Pesanan dibuat": ukuran dan letak lencana mengikuti mockup (kotak ilustrasi 264 dp,
// maskot 168 dp, lencana 56 dp bercincin 6 dp yang menumpang di kanan bawah maskot).
private val IllustrationHeight = 264.dp
private val MascotWidth = 168.dp
private val BadgeSize = 56.dp
private val BadgeRing = 6.dp
private val BadgeIconSize = 28.dp
private val BadgeOffsetX = 18.dp
private val BadgeOffsetY = 34.dp
private val PlacedCardPadding = 20.dp

private val LineImageSize = 48.dp
private val LoadingIndicatorSize = 20.dp
private val IssueIconSize = 20.dp
private val IssueIconInset = 2.dp

// Lingkaran ikon keadaan satu layar penuh; sama dengan keadaan baku di :core:ui.
private val StateIconTile = 72.dp
private val StateIconSize = 32.dp
