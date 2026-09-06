<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kode Aktivasi — {{ $company->name }}</title>
</head>
<body style="font-family: sans-serif; color: #1a1a1a;">
    <h2>{{ $storeName }}</h2>
    <p>Halo {{ $company->contact_name }},</p>
    <p>Berikut kode aktivasi untuk perusahaan <strong>{{ $company->name }}</strong>:</p>

    <p style="font-size: 28px; font-weight: bold; letter-spacing: 4px; margin: 16px 0;">{{ $code }}</p>

    <p>Masukkan kode ini untuk mengaktifkan akun owner Anda. Kode berlaku selama 24 jam sejak email ini dikirim.</p>

    <p style="color: #888; font-size: 12px;">Email ini dikirim otomatis oleh sistem {{ $storeName }}. Jika Anda tidak meminta kode ini, abaikan email ini.</p>
</body>
</html>
