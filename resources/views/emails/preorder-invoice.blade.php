<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $preorder->preorder_number }}</title>
</head>
<body style="font-family: sans-serif; color: #1a1a1a;">
    @if ($store_identity['name'] ?? null)
        <h2>{{ $store_identity['name'] }}</h2>
    @endif
    @if ($store_identity['address'] ?? null)
        <p style="color: #666; font-size: 12px;">{{ $store_identity['address'] }}</p>
    @endif

    <p>Halo {{ $preorder->customer->name ?? '' }},</p>

    @if ($documentType === 'payment_invoice' && $payment)
        <p>Terima kasih, pembayaran Anda untuk pre-order <strong>{{ $preorder->preorder_number }}</strong> sebesar
            <strong>Rp {{ number_format((float) $payment->amount, 0, ',', '.') }}</strong> telah kami terima.</p>
    @else
        <p>Berikut invoice untuk pre-order Anda <strong>{{ $preorder->preorder_number }}</strong>.</p>
    @endif

    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <thead>
            <tr>
                <th style="text-align: left; border-bottom: 1px solid #ddd; padding: 6px 0;">Item</th>
                <th style="text-align: right; border-bottom: 1px solid #ddd; padding: 6px 0;">Qty</th>
                <th style="text-align: right; border-bottom: 1px solid #ddd; padding: 6px 0;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($preorder->items as $item)
                <tr>
                    <td style="padding: 6px 0;">{{ $item->name_snapshot }}</td>
                    <td style="text-align: right; padding: 6px 0;">{{ $item->qty }}</td>
                    <td style="text-align: right; padding: 6px 0;">Rp {{ number_format((float) $item->line_total, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p>Total pesanan: <strong>Rp {{ number_format((float) $preorder->total_amount, 0, ',', '.') }}</strong></p>
    @if ($documentType === 'payment_invoice' && $payment)
        <p>Dibayar kali ini: <strong>Rp {{ number_format((float) $payment->amount, 0, ',', '.') }}</strong></p>
    @endif
    <p>Sudah dibayar: <strong>Rp {{ number_format((float) $preorder->paid_amount, 0, ',', '.') }}</strong></p>
    <p>Sisa tagihan: <strong>Rp {{ number_format($preorder->outstanding(), 0, ',', '.') }}</strong></p>

    @if (!empty($payment_channels))
        <h3>Cara pembayaran</h3>
        <ul>
            @foreach ($payment_channels as $channel)
                <li>
                    {{ $channel['provider'] }}
                    @if ($channel['account_number'])
                        — {{ $channel['account_number'] }}
                    @endif
                    @if ($channel['account_name'])
                        (a.n. {{ $channel['account_name'] }})
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($footer_text)
        <p style="color: #888; font-size: 12px; margin-top: 24px;">{{ $footer_text }}</p>
    @endif

    <p style="color: #888; font-size: 12px;">Email ini dikirim otomatis oleh sistem kasir {{ $store_identity['name'] ?? 'toko' }}.</p>
</body>
</html>
