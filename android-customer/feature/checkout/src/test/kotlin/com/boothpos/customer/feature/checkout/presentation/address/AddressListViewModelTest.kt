package com.boothpos.customer.feature.checkout.presentation.address

import com.boothpos.customer.core.data.domain.address.AddressRepository
import com.boothpos.customer.core.data.domain.model.Address
import com.boothpos.customer.core.data.domain.model.AddressInput
import com.boothpos.customer.core.data.testing.FakeAddressRepository
import com.boothpos.customer.core.data.testing.MainDispatcherRule
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.feature.checkout.presentation.CheckoutTestData.address
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class AddressListViewModelTest {

    /** Daftar selalu berhasil dibaca; hanya perubahan berikutnya yang gagal dengan [nextMutationError]. */
    private class FlakyMutations(private val delegate: FakeAddressRepository) : AddressRepository by delegate {
        var nextMutationError: ApiError? = null
        var mutationCalls = 0

        private fun failure(): ApiResult.Failure? {
            mutationCalls++
            return nextMutationError?.let { error ->
                nextMutationError = null
                ApiResult.Failure(error)
            }
        }

        override suspend fun update(id: Long, input: AddressInput): ApiResult<Address> = failure() ?: delegate.update(id, input)

        override suspend fun delete(id: Long): ApiResult<Unit> = failure() ?: delegate.delete(id)
    }

    @get:Rule
    val mainRule = MainDispatcherRule()

    private val store = FakeAddressRepository(listOf(address(id = 6, label = "Kantor", isDefault = false), address(id = 5)))
    private val repository = FlakyMutations(store)

    private fun viewModel() = AddressListViewModel(repository)

    private fun AddressListViewModel.content() = uiState.value as AddressListUiState.Content

    @Test
    fun `daftar dimuat saat layar tampil dengan alamat utama lebih dulu`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        assertEquals(AddressListUiState.Loading, viewModel.uiState.value)

        viewModel.refresh()
        viewModel.refresh()
        runCurrent()

        assertEquals(listOf(5L, 6L), viewModel.content().addresses.map { it.id })
        assertFalse(viewModel.content().busy)
    }

    @Test
    fun `gagal memuat tampil sebagai galat dan dapat dicoba lagi`() = runTest(mainRule.dispatcher) {
        store.failure = ApiError.Network
        val viewModel = viewModel()
        viewModel.refresh()
        runCurrent()
        assertEquals(AddressListUiState.LoadFailed(ApiError.Network), viewModel.uiState.value)

        store.failure = null
        viewModel.refresh()
        runCurrent()

        assertEquals(2, viewModel.content().addresses.size)
    }

    @Test
    fun `menjadikan utama memindahkan tanda alamat utama`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        viewModel.refresh()
        runCurrent()

        viewModel.setDefault(6)
        viewModel.setDefault(6)
        assertTrue(viewModel.content().busy)
        runCurrent()

        assertEquals("Ketukan ganda mengirim satu permintaan", 1, repository.mutationCalls)
        val list = viewModel.content().addresses
        assertEquals(listOf(6L, 5L), list.map { it.id })
        assertTrue(list.first().isDefault)
        assertFalse(list.last().isDefault)

        // Alamat yang sudah utama tidak dikirim lagi.
        viewModel.setDefault(6)
        runCurrent()
        assertEquals(1, repository.mutationCalls)
    }

    @Test
    fun `hapus meminta konfirmasi lebih dulu dan batal tidak menghapus apa pun`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        viewModel.refresh()
        runCurrent()

        viewModel.requestDelete(6)
        assertEquals(6L, viewModel.content().pendingDelete?.id)
        viewModel.dismissDelete()
        assertNull(viewModel.content().pendingDelete)
        runCurrent()
        assertEquals(0, repository.mutationCalls)

        viewModel.requestDelete(6)
        viewModel.confirmDelete()
        runCurrent()

        assertEquals(listOf(5L), viewModel.content().addresses.map { it.id })
        assertNull(viewModel.content().pendingDelete)
        assertEquals(1, repository.mutationCalls)
    }

    @Test
    fun `alamat yang sudah dihapus di tempat lain cukup memuat ulang daftar`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        viewModel.refresh()
        runCurrent()
        // Sudah terhapus dari perangkat lain: server menjawab 404 not_found.
        store.addresses = store.addresses.filterNot { it.id == 6L }
        repository.nextMutationError = ApiError.Http(404, ApiErrorCodes.NOT_FOUND)

        viewModel.requestDelete(6)
        viewModel.confirmDelete()
        runCurrent()

        assertEquals(listOf(5L), viewModel.content().addresses.map { it.id })
        assertNull(viewModel.content().actionError)
    }

    @Test
    fun `perubahan yang gagal menampilkan galat dan daftar tetap tampil`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        viewModel.refresh()
        runCurrent()
        repository.nextMutationError = ApiError.Network

        viewModel.setDefault(6)
        runCurrent()

        val content = viewModel.content()
        assertEquals(ApiError.Network, content.actionError)
        assertFalse(content.busy)
        assertEquals(2, content.addresses.size)
        assertFalse(content.toString().contains("Contoh"))

        // Penyegaran yang gagal juga tidak mengganti daftar dengan layar galat.
        store.failure = ApiError.Timeout
        viewModel.refresh()
        runCurrent()
        assertEquals(ApiError.Timeout, viewModel.content().actionError)
        assertEquals(2, viewModel.content().addresses.size)
    }
}
