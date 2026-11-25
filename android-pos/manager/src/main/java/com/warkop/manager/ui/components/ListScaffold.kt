package com.warkop.manager.ui.components

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListScope
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.material.ExperimentalMaterialApi
import androidx.compose.material.pullrefresh.PullRefreshIndicator
import androidx.compose.material.pullrefresh.pullRefresh
import androidx.compose.material.pullrefresh.rememberPullRefreshState
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.adaptive.ExperimentalMaterial3AdaptiveApi
import androidx.compose.material3.adaptive.layout.AnimatedPane
import androidx.compose.material3.adaptive.layout.ListDetailPaneScaffold
import androidx.compose.material3.adaptive.layout.ListDetailPaneScaffoldRole
import androidx.compose.material3.adaptive.navigation.rememberListDetailPaneScaffoldNavigator
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.warkop.manager.data.dto.Paged

/**
 * 094 / T041, FR-028 — daftar + detail adaptif. Lebar Compact: satu panel (detail menimpa daftar, tombol kembali menutup
 * detail). Lebar Expanded: dua panel berdampingan. Kunci detail berupa String ("new", "42", "42/edit") supaya tersimpan
 * lintas rotasi/proses tanpa Parcelable.
 */
@OptIn(ExperimentalMaterial3AdaptiveApi::class)
@Composable
fun ListDetail(
    list: @Composable (open: (String) -> Unit) -> Unit,
    detail: @Composable (key: String, close: () -> Unit, open: (String) -> Unit) -> Unit,
    placeholder: @Composable () -> Unit = { MessageBox("Pilih item dari daftar") },
) {
    val nav = rememberListDetailPaneScaffoldNavigator<String>()
    val open: (String) -> Unit = { nav.navigateTo(ListDetailPaneScaffoldRole.Detail, it) }
    val close: () -> Unit = { if (nav.canNavigateBack()) nav.navigateBack() }
    BackHandler(nav.canNavigateBack()) { nav.navigateBack() }
    ListDetailPaneScaffold(
        directive = nav.scaffoldDirective,
        value = nav.scaffoldValue,
        listPane = { AnimatedPane { list(open) } },
        detailPane = {
            AnimatedPane {
                val key = nav.currentDestination?.content
                if (key == null) placeholder() else detail(key, close, open)
            }
        },
    )
}

/**
 * Daftar berhalaman dari amplop `{data, meta}` server (pola `usePaginatedList` web). Satu-satunya pengurus halaman;
 * layar memberi [fetch] untuk halaman ke-N dengan filter yang sedang berlaku.
 */
class Pager<T>(private val fetch: suspend (page: Int) -> Paged<T>) {
    data class State<T>(val items: List<T> = emptyList(), val page: Int = 0, val lastPage: Int = 1, val total: Int = 0, val loadingMore: Boolean = false) {
        val hasMore: Boolean get() = page < lastPage
    }

    /** Muat halaman 1 (ganti filter/segarkan). */
    suspend fun first(): State<T> = fetch(1).let { State(it.data, it.meta.currentPage, it.meta.lastPage, it.meta.total) }

    /** Muat halaman berikut dan sambungkan. Galat dilempar ke pemanggil; data lama tidak hilang. */
    suspend fun next(current: State<T>): State<T> {
        if (!current.hasMore) return current
        val res = fetch(current.page + 1)
        return current.copy(items = current.items + res.data, page = res.meta.currentPage, lastPage = res.meta.lastPage, total = res.meta.total, loadingMore = false)
    }
}

/**
 * Isi daftar dengan tarik-untuk-segarkan dan muat-bertahap di ujung. [items] membangun baris; [onEnd] dipanggil saat
 * baris terakhir terlihat dan masih ada halaman.
 */
@OptIn(ExperimentalMaterialApi::class)
@Composable
fun <T> PagedListContent(
    state: Load<Pager.State<T>>,
    onRefresh: () -> Unit,
    onEnd: () -> Unit,
    empty: String,
    header: (LazyListScope.() -> Unit)? = null,
    items: LazyListScope.(List<T>) -> Unit,
) {
    LoadContent(state, onRefresh) { page ->
        val listState = rememberLazyListState()
        val atEnd by remember { derivedStateOf { listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index == listState.layoutInfo.totalItemsCount - 1 } }
        LaunchedEffect(atEnd, page.page) { if (atEnd && page.hasMore && !page.loadingMore) onEnd() }
        LazyColumn(state = listState, modifier = Modifier.fillMaxSize()) {
            header?.invoke(this)
            if (page.items.isEmpty()) item { Box(Modifier.fillMaxWidth().padding(32.dp)) { MessageBox(empty) } }
            items(page.items)
            if (page.loadingMore) item { Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() } }
        }
    }
}

/** Salin keadaan [Load] siap dengan bendera muat-lagi; selain Ready dibiarkan. */
fun <T> Load<Pager.State<T>>.withLoadingMore(on: Boolean): Load<Pager.State<T>> =
    if (this is Load.Ready) copy(data = data.copy(loadingMore = on)) else this

