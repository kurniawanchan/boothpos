package com.boothpos.customer.ui

import androidx.activity.compose.LocalOnBackPressedDispatcherOwner
import androidx.compose.animation.EnterTransition
import androidx.compose.animation.ExitTransition
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.consumeWindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.AccountCircle
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalResources
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.repeatOnLifecycle
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.boothpos.customer.R
import com.boothpos.customer.core.ui.component.StoreUnavailableState
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.feature.account.presentation.AccountRoute
import com.boothpos.customer.feature.account.presentation.accountGraph
import com.boothpos.customer.feature.auth.presentation.AuthChoiceRoute
import com.boothpos.customer.feature.auth.presentation.RegisterRoute
import com.boothpos.customer.feature.auth.presentation.SignInRoute
import com.boothpos.customer.feature.auth.presentation.authGraph
import com.boothpos.customer.feature.cart.presentation.cartGraph
import com.boothpos.customer.feature.catalog.presentation.ProductDetailRoute
import com.boothpos.customer.feature.catalog.presentation.ShopRoute
import com.boothpos.customer.feature.catalog.presentation.catalogGraph
import com.boothpos.customer.feature.checkout.presentation.AddressListRoute
import com.boothpos.customer.feature.checkout.presentation.CheckoutRoute
import com.boothpos.customer.feature.checkout.presentation.checkoutGraph
import com.boothpos.customer.feature.orders.presentation.OrderDetailRoute
import com.boothpos.customer.feature.orders.presentation.OrdersRoute
import com.boothpos.customer.feature.orders.presentation.ordersGraph
import com.boothpos.customer.navigation.ScreenChrome
import com.boothpos.customer.navigation.TopLevelDestination
import com.boothpos.customer.navigation.chromeFor
import com.boothpos.customer.navigation.isAuthDestination
import kotlinx.coroutines.launch

/**
 * Akar UI yang stateful: mengumpulkan state bingkai, memegang NavController, dan merangkai graf
 * navigasi tiap fitur. Navigasi ANTARFITUR hanya terjadi di sini.
 */
@Composable
fun AppRoute(viewModel: AppViewModel = hiltViewModel()) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val navController = rememberNavController()
    val backStackEntry by navController.currentBackStackEntryAsState()
    val snackbarHostState = remember { SnackbarHostState() }
    val profileSavedMessage = stringResource(R.string.app_profile_saved)
    val scope = rememberCoroutineScope()
    val lifecycleOwner = LocalLifecycleOwner.current
    val resources = LocalResources.current
    val backDispatcher = LocalOnBackPressedDispatcherOwner.current?.onBackPressedDispatcher

    LaunchedEffect(viewModel, lifecycleOwner, navController) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
            viewModel.events.collect { event ->
                when (event) {
                    // Layar masuk sendiri yang menampilkan "Sesi berakhir, silakan masuk lagi".
                    AppEvent.SessionExpired -> navController.navigate(SignInRoute) { launchSingleTop = true }
                    AppEvent.ShowWelcome -> navController.navigate(AuthChoiceRoute) { launchSingleTop = true }
                }
            }
        }
    }

    AppScaffold(
        state = state,
        chrome = chromeFor(backStackEntry?.destination),
        canNavigateBack = navController.previousBackStackEntry != null,
        snackbarHostState = snackbarHostState,
        onTopLevelSelected = navController::navigateToTopLevel,
        onAccountClick = { navController.navigate(AccountRoute) { launchSingleTop = true } },
        // Panah kembali di bilah atas berperilaku sama dengan tombol kembali sistem, supaya layar
        // bertahap (checkout) mundur satu langkah lewat BackHandler-nya, bukan langsung tertutup.
        onBackClick = {
            if (backDispatcher != null) backDispatcher.onBackPressed() else navController.navigateUp()
        },
        onRetryStore = viewModel::refreshStore,
    ) {
        NavHost(
            navController = navController,
            startDestination = ShopRoute,
            modifier = Modifier.fillMaxSize(),
            // Tanpa animasi dekoratif antarlayar.
            enterTransition = { EnterTransition.None },
            exitTransition = { ExitTransition.None },
        ) {
            catalogGraph(
                navController = navController,
                onViewCart = { navController.navigateToTopLevel(TopLevelDestination.Cart) },
            )
            cartGraph(
                onStartShopping = { navController.navigateToTopLevel(TopLevelDestination.Shop) },
                onCheckout = { navController.navigate(CheckoutRoute) { launchSingleTop = true } },
                onOpenProduct = { slug -> navController.navigate(ProductDetailRoute(slug)) { launchSingleTop = true } },
            )
            ordersGraph(
                navController = navController,
                // Setelah masuk, leaveAuth() menutup alur auth dan layar pesanan memuat datanya sendiri.
                onSignIn = { navController.navigate(SignInRoute) { launchSingleTop = true } },
            )
            accountGraph(
                navController = navController,
                onSignIn = { navController.navigate(SignInRoute) { launchSingleTop = true } },
                onRegister = { navController.navigate(RegisterRoute) { launchSingleTop = true } },
                onManageAddresses = { navController.navigate(AddressListRoute) { launchSingleTop = true } },
                onProfileSaved = { scope.launch { snackbarHostState.showSnackbar(profileSavedMessage) } },
            )
            authGraph(
                navController = navController,
                onAuthenticated = navController::leaveAuth,
                onContinueAsGuest = navController::leaveAuth,
            )
            checkoutGraph(
                navController = navController,
                // Setelah masuk/daftar, leaveAuth() menutup alur auth dan checkout tampil lagi.
                onSignIn = { navController.navigate(SignInRoute) { launchSingleTop = true } },
                onBackToCart = navController::leaveCheckout,
                onOrderPlaced = { orderNumber ->
                    navController.openOrderAfterCheckout(orderNumber)
                    scope.launch {
                        snackbarHostState.showSnackbar(resources.getString(R.string.app_order_placed, orderNumber))
                    }
                },
            )
        }
    }
}

/** Pindah tab tanpa menumpuk back stack; keadaan tiap tab disimpan dan dipulihkan. */
internal fun NavHostController.navigateToTopLevel(destination: TopLevelDestination) {
    navigate(destination.route) {
        popUpTo(graph.findStartDestination().id) { saveState = true }
        launchSingleTop = true
        restoreState = true
    }
}

/**
 * Menutup seluruh alur auth (selamat datang, masuk, daftar) dan kembali ke layar asalnya: Akun,
 * atau Belanja bila alur dibuka dari sambutan pertama. Dipakai setelah berhasil masuk/daftar dan
 * saat pelanggan memilih melihat toko tanpa akun.
 */
internal fun NavHostController.leaveAuth() {
    while (currentDestination.isAuthDestination()) {
        if (!popBackStack()) break
    }
}

/** Menutup checkout dan kembali ke Keranjang (mis. untuk memperbaiki barang yang ditolak server). */
internal fun NavHostController.leaveCheckout() {
    if (!popBackStack<CheckoutRoute>(inclusive = true)) navigateToTopLevel(TopLevelDestination.Cart)
}

/**
 * Pesanan tercatat: checkout dibuang dari back stack LEBIH DULU, baru pindah ke tab Pesanan. Tanpa
 * itu `saveState` tab Keranjang ikut menyimpan layar checkout dan menampilkannya lagi saat tab
 * Keranjang dibuka. Detail pesanan yang baru dibuat lalu dibuka DI ATAS daftar Pesanan, supaya
 * pelanggan langsung melihat status dan petunjuk toko, dan Kembali menuju daftar pesanannya.
 *
 * Detail lama yang tersimpan di tab Pesanan dibuang dengan `popUpTo<OrdersRoute>` (bukan
 * `launchSingleTop`): `launchSingleTop` memakai ulang entri back stack yang sama, sehingga
 * ViewModel detail lama (yang membaca nomor pesanan sekali saat dibuat) tetap menampilkan pesanan
 * LAMA meski argumen rutenya sudah berganti.
 */
internal fun NavHostController.openOrderAfterCheckout(orderNumber: String) {
    popBackStack<CheckoutRoute>(inclusive = true)
    navigateToTopLevel(TopLevelDestination.Orders)
    navigate(OrderDetailRoute(orderNumber)) { popUpTo<OrdersRoute>() }
}

/**
 * Bingkai aplikasi yang stateless: bilah atas (judul, kembali, aksi Akun), navigasi bawah
 * Belanja/Keranjang/Pesanan, dan penutup "toko tidak tersedia" untuk tujuan yang membuat pesanan.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AppScaffold(
    state: AppUiState,
    chrome: ScreenChrome,
    canNavigateBack: Boolean,
    snackbarHostState: SnackbarHostState,
    onTopLevelSelected: (TopLevelDestination) -> Unit,
    onAccountClick: () -> Unit,
    onBackClick: () -> Unit,
    onRetryStore: () -> Unit,
    modifier: Modifier = Modifier,
    content: @Composable () -> Unit,
) {
    val title = if (chrome.topLevel == TopLevelDestination.Shop && state.storeName != null) {
        state.storeName
    } else {
        stringResource(chrome.titleRes)
    }
    Scaffold(
        modifier = modifier,
        topBar = {
            TopAppBar(
                title = {
                    // Judul layar = judul halaman bagi TalkBack: navigasi per judul langsung ke sini.
                    Text(
                        title,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.semantics { heading() },
                    )
                },
                navigationIcon = {
                    if (chrome.topLevel == null && canNavigateBack) {
                        IconButton(onClick = onBackClick) {
                            Icon(
                                Icons.AutoMirrored.Filled.ArrowBack,
                                contentDescription = stringResource(R.string.app_action_back),
                            )
                        }
                    }
                },
                actions = {
                    if (chrome.showAccountAction) {
                        IconButton(onClick = onAccountClick) {
                            Icon(
                                Icons.Filled.AccountCircle,
                                contentDescription = stringResource(R.string.app_action_account),
                            )
                        }
                    }
                },
            )
        },
        bottomBar = {
            if (chrome.topLevel != null) {
                NavigationBar {
                    TopLevelDestination.entries.forEach { destination ->
                        NavigationBarItem(
                            selected = destination == chrome.topLevel,
                            onClick = { onTopLevelSelected(destination) },
                            icon = { TopLevelIcon(destination, state.cartItemCount) },
                            label = { Text(stringResource(destination.labelRes)) },
                        )
                    }
                }
            }
        },
        snackbarHost = { SnackbarHost(snackbarHostState) },
    ) { padding ->
        val storeClosed = chrome.storeGated && state.storeStatus == StoreStatus.Closed
        Box(
            modifier = Modifier
                .padding(padding)
                .consumeWindowInsets(padding)
                .fillMaxSize(),
            contentAlignment = Alignment.TopCenter,
        ) {
            Box(
                modifier = Modifier
                    .widthIn(max = Dimens.ContentMaxWidth)
                    .fillMaxSize()
                    // Konten di balik penutup tidak boleh terbaca atau ditekan lewat pembaca layar.
                    .then(if (storeClosed) Modifier.clearAndSetSemantics { } else Modifier),
            ) {
                content()
            }
            if (storeClosed) {
                Surface(modifier = Modifier.fillMaxSize()) {
                    StoreUnavailableState(onRetry = onRetryStore, retrying = state.checkingStore)
                }
            }
        }
    }
}

@Composable
private fun TopLevelIcon(destination: TopLevelDestination, cartItemCount: Int) {
    // Label tab di bawah ikon sudah menyebut namanya, jadi ikon tidak diberi deskripsi ganda.
    if (destination == TopLevelDestination.Cart && cartItemCount > 0) {
        val description = pluralStringResource(R.plurals.app_cart_badge_description, cartItemCount, cartItemCount)
        BadgedBox(
            badge = {
                Badge(modifier = Modifier.semantics { contentDescription = description }) {
                    Text(
                        if (cartItemCount > MAX_BADGE_COUNT) {
                            stringResource(R.string.app_cart_badge_overflow)
                        } else {
                            cartItemCount.toString()
                        },
                    )
                }
            },
        ) {
            Icon(destination.icon, contentDescription = null)
        }
    } else {
        Icon(destination.icon, contentDescription = null)
    }
}

private const val MAX_BADGE_COUNT = 99
