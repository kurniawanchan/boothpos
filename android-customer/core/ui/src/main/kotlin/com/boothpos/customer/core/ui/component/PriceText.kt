package com.boothpos.customer.core.ui.component

import androidx.compose.material3.LocalTextStyle
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import com.boothpos.customer.core.ui.format.RupiahFormatter
import java.math.BigDecimal

/** Harga dalam Rupiah. Nilainya selalu berasal dari server; komponen ini hanya memformat. */
@Composable
fun PriceText(
    amount: BigDecimal,
    modifier: Modifier = Modifier,
    style: TextStyle = LocalTextStyle.current,
    color: Color = Color.Unspecified,
) {
    Text(text = RupiahFormatter.format(amount), modifier = modifier, style = style, color = color)
}
