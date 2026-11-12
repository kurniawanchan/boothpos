package com.boothpos.customer.feature.checkout.presentation.address

import androidx.lifecycle.SavedStateHandle
import app.cash.turbine.test
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.data.testing.FakeAddressRepository
import com.boothpos.customer.core.data.testing.FakeSessionRepository
import com.boothpos.customer.core.data.testing.MainDispatcherRule
import com.boothpos.customer.core.data.testing.TestData
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.feature.checkout.domain.AddressField
import com.boothpos.customer.feature.checkout.presentation.AddressFormRoute
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
class AddressFormViewModelTest {

    @get:Rule
    val mainRule = MainDispatcherRule()

    private val account = TestData.account()
    private val session = FakeSessionRepository(SessionState.SignedIn(account, expiresAt = null))
    private val addresses = FakeAddressRepository()

    private fun handle(addressId: Long? = null) =
        if (addressId == null) SavedStateHandle() else SavedStateHandle(mapOf(AddressFormRoute.ADDRESS_ID_ARG to addressId))

    private fun viewModel(handle: SavedStateHandle = handle()) = AddressFormViewModel(handle, session, addresses)

    private fun AddressFormViewModel.form() = uiState.value as AddressFormUiState.Form

    private fun AddressFormViewModel.fill() {
        onFieldChange(AddressField.Label, "Rumah")
        onFieldChange(AddressField.AddressLine, "Jl. Contoh No. 1")
        onFieldChange(AddressField.City, "Kota Uji")
        onFieldChange(AddressField.Province, "Provinsi Uji")
    }

    @Test
    fun `alamat baru diisi awal dari profil lalu tersimpan dan alamat pertama menjadi utama`() =
        runTest(mainRule.dispatcher) {
            val viewModel = viewModel()
            runCurrent()

            val initial = viewModel.form()
            assertTrue(initial.isNew)
            assertEquals(account.name, initial.draft.recipientName)
            assertEquals(account.phone, initial.draft.phone)
            assertFalse(initial.defaultLocked)

            viewModel.events.test {
                viewModel.fill()
                viewModel.submit()
                runCurrent()
                assertEquals(AddressFormEvent.Saved, awaitItem())
            }

            val saved = addresses.addresses.single()
            assertEquals("Rumah", saved.label)
            assertEquals("Jl. Contoh No. 1", saved.addressLine)
            assertNull(saved.postalCode)
            assertNull(saved.notes)
            // Server memaksa alamat pertama menjadi alamat utama.
            assertTrue(saved.isDefault)
        }

    @Test
    fun `kolom wajib yang kosong ditolak di perangkat tanpa memanggil server`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        runCurrent()
        viewModel.onFieldChange(AddressField.RecipientName, " ")
        viewModel.onFieldChange(AddressField.Label, "x".repeat(51))

        viewModel.submit()
        runCurrent()

        val errors = viewModel.form().errors
        assertEquals(AddressFieldError.Local(FieldIssue.Required), errors[AddressField.RecipientName])
        assertEquals(AddressFieldError.Local(FieldIssue.Required), errors[AddressField.AddressLine])
        assertEquals(AddressFieldError.Local(FieldIssue.Required), errors[AddressField.City])
        assertEquals(AddressFieldError.Local(FieldIssue.Required), errors[AddressField.Province])
        assertEquals(AddressFieldError.Local(FieldIssue.TooLong(50)), errors[AddressField.Label])
        assertNull("Telepon dari profil sudah terisi", errors[AddressField.Phone])
        assertFalse(viewModel.form().submitting)
        assertTrue(addresses.addresses.isEmpty())

        // Mengetik menghapus galat kolom itu saja.
        viewModel.onFieldChange(AddressField.City, "Kota Uji")
        assertNull(viewModel.form().errors[AddressField.City])
        assertEquals(AddressFieldError.Local(FieldIssue.Required), viewModel.form().errors[AddressField.Province])
    }

    @Test
    fun `galat validasi server tampil di kolomnya`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        runCurrent()
        viewModel.fill()
        addresses.failure = ApiError.Http(
            status = 422,
            code = ApiErrorCodes.VALIDATION_FAILED,
            fieldErrors = mapOf(
                "postal_code" to listOf("Kode pos tidak valid."),
                "recipient_name" to listOf("Nama penerima wajib diisi."),
            ),
        )

        viewModel.submit()
        runCurrent()

        val form = viewModel.form()
        assertEquals(AddressFieldError.Server("Kode pos tidak valid."), form.errors[AddressField.PostalCode])
        assertEquals(AddressFieldError.Server("Nama penerima wajib diisi."), form.errors[AddressField.RecipientName])
        assertNull(form.formError)
        assertFalse(form.submitting)
    }

    @Test
    fun `batas jumlah alamat dan galat jaringan tampil sebagai galat formulir dan isian dipertahankan`() =
        runTest(mainRule.dispatcher) {
            val viewModel = viewModel()
            runCurrent()
            viewModel.fill()
            val limit = ApiError.Http(409, ApiErrorCodes.ADDRESS_LIMIT_REACHED, detail = "Maksimal 10 alamat.")
            addresses.failure = limit

            viewModel.submit()
            runCurrent()
            assertEquals(limit, viewModel.form().formError)
            assertEquals("Jl. Contoh No. 1", viewModel.form().draft.addressLine)

            addresses.failure = ApiError.Network
            viewModel.submit()
            runCurrent()
            assertEquals(ApiError.Network, viewModel.form().formError)

            // Sesi berakhir ditangani alur sesi, bukan galat formulir.
            addresses.failure = ApiError.Http(401, ApiErrorCodes.UNAUTHENTICATED)
            viewModel.submit()
            runCurrent()
            assertNull(viewModel.form().formError)
            assertFalse(viewModel.form().submitting)
        }

    @Test
    fun `ketukan ganda pada simpan hanya membuat satu alamat`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        runCurrent()
        viewModel.fill()

        viewModel.submit()
        viewModel.submit()
        assertTrue(viewModel.form().submitting)
        runCurrent()

        assertEquals(1, addresses.addresses.size)
    }

    @Test
    fun `mengubah alamat memuat isinya dan menjadikannya utama melepas alamat utama sebelumnya`() =
        runTest(mainRule.dispatcher) {
            addresses.addresses = listOf(address(id = 5), address(id = 6, label = "Kantor", isDefault = false))
            val viewModel = viewModel(handle(addressId = 6))
            runCurrent()

            val form = viewModel.form()
            assertFalse(form.isNew)
            assertEquals("Kantor", form.draft.label)
            assertEquals("Jl. Contoh No. 6", form.draft.addressLine)
            assertFalse(form.defaultLocked)

            viewModel.onFieldChange(AddressField.Notes, "Lantai 2")
            viewModel.onDefaultChange(true)
            viewModel.submit()
            runCurrent()

            val updated = addresses.addresses.first { it.id == 6L }
            assertEquals("Lantai 2", updated.notes)
            assertTrue(updated.isDefault)
            assertFalse(addresses.addresses.first { it.id == 5L }.isDefault)
            assertEquals(2, addresses.addresses.size)
        }

    @Test
    fun `alamat utama tidak dapat dicabut dari formulirnya sendiri`() = runTest(mainRule.dispatcher) {
        addresses.addresses = listOf(address(id = 5))
        val viewModel = viewModel(handle(addressId = 5))
        runCurrent()
        assertTrue(viewModel.form().defaultLocked)

        viewModel.onDefaultChange(false)

        assertTrue(viewModel.form().draft.isDefault)
    }

    @Test
    fun `alamat yang sudah tidak ada tampil sebagai tidak ditemukan saat dibuka maupun saat disimpan`() =
        runTest(mainRule.dispatcher) {
            addresses.addresses = listOf(address(id = 5))
            val missing = viewModel(handle(addressId = 99))
            runCurrent()
            assertEquals(AddressFormUiState.NotFound, missing.uiState.value)

            val stale = viewModel(handle(addressId = 5))
            runCurrent()
            // Dihapus dari perangkat lain setelah formulir terbuka: server menjawab 404 not_found.
            addresses.failure = ApiError.Http(404, ApiErrorCodes.NOT_FOUND)
            stale.submit()
            runCurrent()
            assertEquals(AddressFormUiState.NotFound, stale.uiState.value)
        }

    @Test
    fun `gagal memuat alamat yang diubah dapat dicoba lagi`() = runTest(mainRule.dispatcher) {
        addresses.addresses = listOf(address(id = 5))
        addresses.failure = ApiError.Network
        val viewModel = viewModel(handle(addressId = 5))
        runCurrent()
        assertEquals(AddressFormUiState.LoadFailed(ApiError.Network), viewModel.uiState.value)

        addresses.failure = null
        viewModel.retryLoad()
        runCurrent()

        assertEquals("Rumah", viewModel.form().draft.label)
    }

    @Test
    fun `suntingan yang belum disimpan bertahan setelah proses dipulihkan dan state tidak membocorkan alamat`() =
        runTest(mainRule.dispatcher) {
            val handle = handle()
            val first = viewModel(handle)
            runCurrent()
            first.fill()
            first.onDefaultChange(true)

            val restored = viewModel(handle)
            runCurrent()

            assertEquals("Jl. Contoh No. 1", restored.form().draft.addressLine)
            assertEquals("Kota Uji", restored.form().draft.city)
            assertTrue(restored.form().draft.isDefault)
            assertFalse(restored.form().toString().contains("Contoh"))
            assertFalse(restored.form().toString().contains(account.name))
        }
}
