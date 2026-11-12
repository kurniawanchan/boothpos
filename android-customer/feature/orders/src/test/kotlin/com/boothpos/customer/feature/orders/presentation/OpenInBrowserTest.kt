package com.boothpos.customer.feature.orders.presentation

import android.app.Application
import android.content.Intent
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.SAFE_TRACKING_URL
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.Shadows.shadowOf

/** "Lacak paket" menyerahkan tautan ke peramban LUAR lewat Intent; aplikasi tidak merendernya sendiri. */
@RunWith(AndroidJUnit4::class)
class OpenInBrowserTest {

    private val application: Application = ApplicationProvider.getApplicationContext()

    @Test
    fun `tautan dibuka dengan ACTION_VIEW yang hanya membawa alamatnya`() {
        val opened = openInBrowser(application, SAFE_TRACKING_URL)

        assertTrue(opened)
        val intent = shadowOf(application).nextStartedActivity
        assertEquals(Intent.ACTION_VIEW, intent.action)
        assertEquals(SAFE_TRACKING_URL, intent.dataString)
        assertTrue(intent.hasCategory(Intent.CATEGORY_BROWSABLE))
        // Intent implisit: tidak menunjuk komponen aplikasi ini (tidak ada WebView/activity internal).
        assertNull(intent.component)
        assertNull(intent.`package`)
        // Tidak ada data lain (token, nomor pesanan, kontak) yang ikut diserahkan.
        assertNull(intent.extras)
        assertNull(shadowOf(application).nextStartedActivity)
    }

    @Test
    fun `tanpa aplikasi yang dapat membuka tautan hasilnya false bukan crash`() {
        shadowOf(application).checkActivities(true)

        val opened = openInBrowser(application, SAFE_TRACKING_URL)

        assertFalse(opened)
        assertNull(shadowOf(application).nextStartedActivity)
    }
}
