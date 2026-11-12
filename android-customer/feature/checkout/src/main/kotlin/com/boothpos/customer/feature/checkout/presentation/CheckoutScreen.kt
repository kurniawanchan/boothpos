package com.boothpos.customer.feature.checkout.presentation

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
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
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.contentType
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
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
import com.boothpos.customer.core.ui.component.AppTextField
import com.boothpos.customer.core.ui.component.EmptyState
import com.boothpos.customer.core.ui.component.InlineMessage
import com.boothpos.customer.core.ui.component.InlineMessageTone
import com.boothpos.customer.core.ui.component.LoadingState
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.component.SecondaryButton
import com.boothpos.customer.core.ui.component.apiErrorMessage
import com.boothpos.customer.core.ui.format.RupiahFormatter
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.checkout.R
import com.boothpos.customer.feature.checkout.domain.CartIssueReason
import com.boothpos.customer.feature.checkout.domain.Quote
import com.boothpos.customer.feature.checkout.presentation.address.AddressText
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
            icon = rememberVectorPainter(Icons.Filled.Lock),
            actionLabel = stringResource(R.string.checkout_sign_in_action),
            onAction = actions.onSignIn,
            modifier = modifier,
        )
        CheckoutUiState.EmptyCart -> EmptyState(
            title = stringResource(R.string.checkout_empty_cart_title),
            message = stringResource(R.string.checkout_empty_cart_message),
            actionLabel = stringResource(R.string.checkout_empty_cart_action),
            onAction = actions.onBackToCart,
            modifier = modifier,
        )
        is CheckoutUiState.Placed -> EmptyState(
            title = stringResource(R.string.checkout_placed_title),
            message = stringResource(R.string.checkout_placed_message, state.orderNumber),
            icon = rememberVectorPainter(Icons.Filled.CheckCircle),
            actionLabel = stringResource(R.string.checkout_placed_action),
            onAction = actions.onViewOrder,
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

@Composable
private fun StepHeader(step: CheckoutStep) {
    Text(
        text = stringResource(
            when (step) {
                CheckoutStep.Method -> R.string.checkout_step_method
                CheckoutStep.Details -> R.string.checkout_step_details
                CheckoutStep.Review -> R.string.checkout_step_review
            },
        ),
        style = MaterialTheme.typography.labelLarge,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = Spacing.md, vertical = Spacing.sm),
    )
    HorizontalDivider()
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
            .padding(Spacing.md),
        verticalArrangement = Arrangement.spacedBy(Spacing.md),
        content = content,
    )
    HorizontalDivider()
    Surface {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .imePadding()
                .padding(horizontal = Spacing.md, vertical = Spacing.sm),
            verticalArrangement = Arrangement.spacedBy(Spacing.xs),
            content = bottomBar,
        )
    }
}

@Composable
private fun SectionTitle(text: String, modifier: Modifier = Modifier) {
    Text(
        text = text,
        style = MaterialTheme.typography.titleMedium,
        modifier = modifier.semantics { heading() },
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
        SectionTitle(stringResource(R.string.checkout_method_title))
        if (state.methodMissing) {
            InlineMessage(text = stringResource(R.string.checkout_method_missing), tone = InlineMessageTone.Error)
        }
        Column(modifier = Modifier.selectableGroup()) {
            state.methods.forEach { method ->
                OptionRow(
                    selected = method == state.method,
                    onClick = { actions.onSelectMethod(method) },
                ) {
                    Text(
                        text = stringResource(
                            when (method) {
                                FulfilmentMethod.Pickup -> R.string.checkout_method_pickup
                                FulfilmentMethod.Courier -> R.string.checkout_method_courier
                            },
                        ),
                        style = MaterialTheme.typography.bodyLarge,
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
            }
        }
        state.instructions?.let { instructions -> StoreInstructions(instructions) }
    }
}

/** Petunjuk dari toko, ditampilkan apa adanya sebagai teks polos. */
@Composable
private fun StoreInstructions(instructions: String) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
        SectionTitle(stringResource(R.string.checkout_instructions_title))
        Text(text = instructions, style = MaterialTheme.typography.bodyMedium)
    }
}

/** Baris pilihan tunggal: seluruh baris adalah target sentuhnya, bukan hanya lingkaran radionya. */
@Composable
private fun OptionRow(
    selected: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    content: @Composable ColumnScope.() -> Unit,
) {
    Row(
        modifier = modifier
            .fillMaxWidth()
            .heightIn(min = OptionRowMinHeight)
            .selectable(selected = selected, role = Role.RadioButton, onClick = onClick)
            .padding(vertical = Spacing.sm),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        RadioButton(selected = selected, onClick = null)
        Spacer(Modifier.width(Spacing.md))
        Column(modifier = Modifier.weight(1f), content = content)
    }
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
            HorizontalDivider()
        }
        SectionTitle(stringResource(R.string.checkout_contact_title))
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

@Composable
private fun AddressSection(state: CheckoutUiState.Form, actions: CheckoutActions) {
    SectionTitle(stringResource(R.string.checkout_address_title))
    if (state.addressMissing) {
        InlineMessage(text = stringResource(R.string.checkout_address_missing), tone = InlineMessageTone.Error)
    }
    when (val addresses = state.addresses) {
        AddressesState.NotLoaded, AddressesState.Loading -> Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.semantics(mergeDescendants = true) {},
        ) {
            CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
            Spacer(Modifier.width(Spacing.sm))
            Text(stringResource(R.string.checkout_address_loading), style = MaterialTheme.typography.bodyMedium)
        }
        is AddressesState.Failed -> InlineMessage(
            text = stringResource(R.string.checkout_address_load_failed, apiErrorMessage(addresses.error)),
            tone = InlineMessageTone.Error,
            actionLabel = stringResource(CoreUiR.string.core_ui_retry),
            onAction = actions.onRetryAddresses,
        )
        is AddressesState.Loaded -> if (addresses.addresses.isEmpty()) {
            Text(
                text = stringResource(R.string.checkout_address_empty),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        } else {
            Column(modifier = Modifier.selectableGroup()) {
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
    if (state.addresses is AddressesState.Loaded) {
        SecondaryButton(
            text = stringResource(R.string.checkout_address_add),
            onClick = actions.onAddAddress,
            modifier = Modifier.fillMaxWidth(),
        )
    }
}

@Composable
private fun AddressOption(address: Address, selected: Boolean, onSelect: () -> Unit, onEdit: () -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        OptionRow(selected = selected, onClick = onSelect, modifier = Modifier.weight(1f)) {
            AddressText(address)
        }
        // Beberapa tombol "Ubah" tampil sekaligus: pembaca layar perlu tahu alamat mana yang dimaksud.
        val editLabel = stringResource(
            R.string.checkout_address_edit_named,
            address.label?.takeIf { it.isNotBlank() } ?: address.recipientName,
        )
        TextButton(
            onClick = onEdit,
            modifier = Modifier
                .heightIn(min = Dimens.MinTouchTarget)
                .semantics { contentDescription = editLabel },
        ) {
            Text(stringResource(R.string.checkout_address_edit))
        }
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
        review.lines.forEach { line ->
            // Ikon + teks: alasan tidak disampaikan lewat warna saja.
            Row(modifier = Modifier.semantics(mergeDescendants = true) {}) {
                Icon(
                    Icons.Filled.Warning,
                    contentDescription = null,
                    tint = MaterialTheme.colorScheme.error,
                    modifier = Modifier.size(20.dp),
                )
                Spacer(Modifier.width(Spacing.sm))
                Column {
                    Text(
                        text = line.name ?: stringResource(R.string.checkout_cart_invalid_unknown_item),
                        style = MaterialTheme.typography.titleSmall,
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
        QuoteLines(quote)
        HorizontalDivider()
        FulfilmentSummary(state)
        HorizontalDivider()
        CostRows(quote)
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

@Composable
private fun QuoteLines(quote: Quote) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        SectionTitle(stringResource(R.string.checkout_review_items_title))
        quote.lines.forEach { line ->
            // Nama, jumlah x harga, dan jumlah harga dibaca sebagai satu baris.
            Row(modifier = Modifier.fillMaxWidth().semantics(mergeDescendants = true) {}) {
                Column(modifier = Modifier.weight(1f)) {
                    Text(text = line.name, style = MaterialTheme.typography.bodyLarge)
                    Text(
                        text = stringResource(
                            R.string.checkout_review_line_quantity,
                            line.quantity.toString(),
                            RupiahFormatter.format(line.unitPrice),
                        ),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                Spacer(Modifier.width(Spacing.sm))
                Text(text = RupiahFormatter.format(line.lineTotal), style = MaterialTheme.typography.bodyLarge)
            }
        }
    }
}

@Composable
private fun FulfilmentSummary(state: CheckoutUiState.Form) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        SectionTitle(stringResource(R.string.checkout_review_fulfilment_title))
        Text(
            text = stringResource(
                when (state.method) {
                    FulfilmentMethod.Courier -> R.string.checkout_method_courier
                    else -> R.string.checkout_method_pickup
                },
            ),
            style = MaterialTheme.typography.bodyLarge,
        )
        state.selectedAddress?.takeIf { state.needsAddress }?.let { address ->
            AddressText(address, showDefaultMark = false)
        }
        state.instructions?.let { instructions -> StoreInstructions(instructions) }
        SectionTitle(stringResource(R.string.checkout_contact_title))
        Text(
            text = stringResource(R.string.checkout_review_contact, state.contactName.trim(), state.contactPhone.trim()),
            style = MaterialTheme.typography.bodyLarge,
        )
        state.notes.trim().takeIf { it.isNotEmpty() }?.let { notes ->
            Text(
                text = stringResource(R.string.checkout_review_notes, notes),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

/** Subtotal dan ongkos kirim dari quote server; tidak ada biaya lain yang muncul belakangan. */
@Composable
private fun CostRows(quote: Quote) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
        SectionTitle(stringResource(R.string.checkout_review_cost_title))
        CostRow(label = stringResource(R.string.checkout_review_subtotal), value = RupiahFormatter.format(quote.subtotal))
        CostRow(
            label = stringResource(R.string.checkout_review_shipping),
            value = if (quote.shippingCostPending) {
                stringResource(R.string.checkout_review_shipping_pending)
            } else {
                RupiahFormatter.format(quote.shippingCost)
            },
            modifier = Modifier.testTag(CheckoutTestTags.SHIPPING),
        )
    }
}

@Composable
private fun CostRow(label: String, value: String, modifier: Modifier = Modifier) {
    Row(modifier = modifier.fillMaxWidth().semantics(mergeDescendants = true) {}) {
        Text(text = label, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
        Spacer(Modifier.width(Spacing.sm))
        Text(text = value, style = MaterialTheme.typography.bodyLarge, textAlign = TextAlign.End)
    }
}

/** Total dari quote, selalu terlihat di atas tombol "Buat pesanan". */
@Composable
private fun TotalRow(quote: Quote) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .testTag(CheckoutTestTags.TOTAL)
            .semantics(mergeDescendants = true) {},
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            text = stringResource(R.string.checkout_review_total),
            style = MaterialTheme.typography.titleMedium,
            modifier = Modifier.weight(1f),
        )
        Text(
            text = RupiahFormatter.format(quote.total),
            style = MaterialTheme.typography.titleLarge,
            fontWeight = FontWeight.SemiBold,
        )
    }
    if (quote.shippingCostPending) {
        Text(
            text = stringResource(R.string.checkout_review_total_excludes_shipping),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

/**
 * Jawaban server tidak sampai. Layar ini sengaja TIDAK menyebut pesanan gagal: satu-satunya aksi
 * adalah memeriksa (mengirim ulang dengan kunci yang sama), dan itu tidak membuat pesanan kedua.
 */
@Composable
private fun ColumnScope.StatusUnknownContent(review: ReviewState.StatusUnknown, actions: CheckoutActions) {
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
            Icon(
                Icons.Filled.Info,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.size(40.dp),
            )
            Spacer(Modifier.height(Spacing.md))
            Text(
                text = stringResource(R.string.checkout_unknown_title),
                style = MaterialTheme.typography.titleMedium,
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

private val OptionRowMinHeight = 56.dp
