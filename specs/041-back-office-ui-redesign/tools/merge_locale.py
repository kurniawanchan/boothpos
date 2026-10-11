#!/usr/bin/env python3
"""Gabungkan kunci terjemahan baru ke resources/js/locales/{id,en}.json dengan aman untuk dipakai paralel.

Pemakaian:  python3 -I merge_locale.py additions.json
additions.json: {"id": {"namespace": {"key": "teks"}}, "en": {"namespace": {"key": "text"}}}

- Memakai kunci berkas (flock) sehingga beberapa pekerja yang berjalan bersamaan tidak saling menimpa.
- Menggabung secara mendalam; menolak MENIMPA kunci yang sudah ada dengan nilai berbeda (keluar dengan kode 2),
  supaya terjemahan milik layar lain tidak diubah diam-diam. Kunci yang sama persis dilewati.
- Kedua bahasa HARUS membawa himpunan kunci yang sama (paritas dijaga tes localeParity).
- Format keluaran identik dengan berkas yang ada (indent 2, ensure_ascii=False, baris baru di akhir).
"""
import fcntl, json, os, sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
LOCALES = os.path.join(ROOT, 'resources', 'js', 'locales')


def flatten(d, prefix=''):
    for k, v in d.items():
        key = f'{prefix}{k}'
        if isinstance(v, dict):
            yield from flatten(v, key + '.')
        else:
            yield key


def merge(dst, src, path, conflicts):
    for k, v in src.items():
        here = f'{path}{k}'
        if isinstance(v, dict):
            node = dst.setdefault(k, {})
            if not isinstance(node, dict):
                conflicts.append(f'{here}: bukan objek di berkas')
                continue
            merge(node, v, here + '.', conflicts)
        elif k in dst:
            if dst[k] != v:
                conflicts.append(f'{here}: sudah ada dengan nilai berbeda')
        else:
            dst[k] = v


def main():
    if len(sys.argv) != 2:
        print(__doc__)
        return 1
    add = json.load(open(sys.argv[1], encoding='utf-8'))
    if set(add) != {'id', 'en'}:
        print('additions.json harus berisi tepat dua kunci: id dan en')
        return 1
    if set(flatten(add['id'])) != set(flatten(add['en'])):
        only_id = sorted(set(flatten(add['id'])) - set(flatten(add['en'])))
        only_en = sorted(set(flatten(add['en'])) - set(flatten(add['id'])))
        print('Paritas gagal. Hanya di id:', only_id, '| hanya di en:', only_en)
        return 1
    with open('/tmp/boothpos-locale-merge.lock', 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        data, conflicts = {}, []
        for lang in ('id', 'en'):
            data[lang] = json.load(open(os.path.join(LOCALES, f'{lang}.json'), encoding='utf-8'))
            merge(data[lang], add[lang], '', conflicts)
        if conflicts:
            print('Konflik (tidak ada yang ditulis):')
            print('\n'.join(sorted(set(conflicts))))
            return 2
        for lang in ('id', 'en'):
            with open(os.path.join(LOCALES, f'{lang}.json'), 'w', encoding='utf-8') as f:
                f.write(json.dumps(data[lang], ensure_ascii=False, indent=2) + '\n')
    print('OK: kunci digabung ke id.json dan en.json')
    return 0


if __name__ == '__main__':
    sys.exit(main())
