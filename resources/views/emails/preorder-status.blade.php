<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $subjectTheme }} — {{ $preorder->preorder_number }}</title>
</head>
<body style="font-family: sans-serif; color: #1a1a1a;">
    <h2>{{ $storeName }}</h2>
    <p>Halo {{ $preorder->customer->name }},</p>
    <p>Status pre-order Anda <strong>{{ $preorder->preorder_number }}</strong> telah diperbarui menjadi:
        <strong>{{ $subjectTheme }}</strong>.</p>

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

    <p>Total: <strong>Rp {{ number_format((float) $preorder->total_amount, 0, ',', '.') }}</strong></p>
    @if ($documentType === 'invoice')
        <p>Sisa tagihan: <strong>Rp {{ number_format($preorder->outstanding(), 0, ',', '.') }}</strong></p>
    @endif

    <p>Untuk melihat atau mencetak {{ $documentType === 'receipt' ? 'struk' : 'invoice' }} lengkap, silakan hubungi toko kami atau kunjungi kembali tempat Anda memesan.</p>

    <p style="color: #888; font-size: 12px;">Email ini dikirim otomatis oleh sistem kasir {{ $storeName }}.</p>
</body>
</html>
