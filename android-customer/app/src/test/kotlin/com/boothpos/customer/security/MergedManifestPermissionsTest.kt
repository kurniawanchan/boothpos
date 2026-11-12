package com.boothpos.customer.security

import android.app.Application
import android.content.Context
import android.content.pm.PackageManager
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.assertEquals
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/**
 * Izin yang diminta oleh manifest GABUNGAN (aplikasi + semua modul + pustaka), dibaca lewat
 * PackageManager Robolectric yang memuat manifest hasil penggabungan build debug. Berbeda dari
 * `SecurityConfigurationTest`, yang hanya membaca berkas sumber dan tidak melihat izin yang dibawa
 * modul lain atau pustaka (itulah sebabnya klaim lama "hanya INTERNET" tidak pernah benar:
 * `core/data` menambah ACCESS_NETWORK_STATE).
 *
 * Application tiruan: kelas aplikasi Hilt asli tidak perlu (dan tidak boleh) dibangun di sini.
 */
@RunWith(AndroidJUnit4::class)
@Config(application = Application::class)
class MergedManifestPermissionsTest {

    @Test
    fun `manifest gabungan hanya meminta internet dan status jaringan`() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val info = context.packageManager.getPackageInfo(context.packageName, PackageManager.GET_PERMISSIONS)

        val requested = info.requestedPermissions.orEmpty().toSet()

        // Izin bertanda tangan `<paket>.DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION` dideklarasikan dan
        // diminta oleh androidx.core sendiri (receiver dinamis tak-diekspor); bukan izin sistem.
        val androidxOwn = "${context.packageName}.DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION"
        assertEquals(
            setOf("android.permission.INTERNET", "android.permission.ACCESS_NETWORK_STATE"),
            requested - androidxOwn,
        )
    }
}
