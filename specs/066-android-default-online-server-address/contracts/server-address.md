# Contract — 066

## `GET /api/v1/server-address` (publik, tablet) — TIDAK BERUBAH
`{"selected": "local|online", "url": string|null}`. Bila `selected=online` dan alamat online kosong, `url` tetap `null`; jatuhan ke alamat bawaan dilakukan di aplikasi, bukan di server (alamat bawaan tidak pernah dikirim server ke tablet).

## `GET /api/v1/settings/server-address` (owner/admin) — penambahan aditif
```json
{"local": "http://192.168.1.219:8010", "online": null, "selected": "online",
 "default_online": "https://smasher-nullify-subgroup.ngrok-free.dev"}
```
`default_online`: string|null, read-only; null bila konfigurasi dikosongkan (web menyembunyikan petunjuk).

## `PUT /api/v1/settings/server-address` — TIDAK BERUBAH
`default_online` pada body diabaikan (tidak divalidasi, tidak disimpan).

## Perilaku aplikasi (ServerDiscovery)
| Jawaban server | Alamat online bawaan | Hasil |
|---|---|---|
| url terisi | apa pun | `Found(url)` (probe bila beda dari yang menjawab) |
| `selected=online`, url null | terisi, terjangkau | `Found(bawaan)` |
| `selected=online`, url null | terisi, tak terjangkau | `Unreachable` |
| `selected=online`, url null | kosong | `NotConfigured("online")` |
| `selected=local`, url null | apa pun | `NotConfigured("local")` |
