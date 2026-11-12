package com.boothpos.customer

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.ui.AppRoute
import dagger.hilt.android.AndroidEntryPoint

/**
 * Satu-satunya activity. Tidak membaca extra maupun data Intent apa pun, jadi tidak ada masukan
 * dari aplikasi lain yang perlu divalidasi.
 */
@AndroidEntryPoint
class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        setContent {
            BoothTheme {
                AppRoute()
            }
        }
    }
}
