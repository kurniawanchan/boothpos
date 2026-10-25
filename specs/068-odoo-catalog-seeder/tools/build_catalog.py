#!/usr/bin/env python3
"""068 — alat dev: bangkitkan catalog.json + foto katalog WJM dari ekspor Odoo dan catalog-mapping.md.

    python3 -I specs/068-odoo-catalog-seeder/tools/build_catalog.py \\
        --src "<folder berisi tiga ekspor XLSX Odoo>" \\
        --mapping specs/068-odoo-catalog-seeder/catalog-mapping.md \\
        --out database/seeders/data/wjm-odoo

Hanya pustaka standar, dan hanya untuk mesin pengembang (research D2). Keputusan kategori, varian, harga
varian, dan tautan grup diambil dari catalog-mapping.md yang ditinjau pemilik; ekspor XLSX hanya memberi
fakta sumber (nama, harga, jumlah varian Odoo, foto). Kolom Responsible (nama staf), stok, harga pokok,
satuan, dan BoM tidak pernah dibawa ke keluaran, dan berkas XLSX tidak disalin (FR-012, FR-020).

Format 2 (069): satu alat untuk dua katalog (WJM 3 dan WJM 2). Berkas sumber dikenali menurut akhiran model
Odoo dan kolomnya menurut alias Inggris/Indonesia; nilai atribut yang tidak ada di ekspor dapat diisi dari
bagian "## Nilai atribut" di tabel pemetaan; kebijakan per outlet (outlet tujuan, stasiun, penarikan item) ikut
dari baris kepala tabel pemetaan, bukan dari kode.

Halaman tambahan (069, catatan 2026-10-25): ekspor produk yang terpotong per halaman datang sebagai berkas
tambahan "... (product.template) page N.xlsx" di folder yang sama. Barisnya ditambahkan sesudah berkas utama.
Halaman tambahan boleh tanpa kolom jumlah varian; jumlahnya lalu diturunkan dari tabel pemetaan dan dicatat
lewat `variant_count_override`, bukan dikarang sebagai angka Odoo.

Semua masalah dikumpulkan dulu. Bila ada satu saja, tidak ada berkas yang ditulis dan alat keluar dengan
kode 1, supaya catalog.json tidak pernah setengah jadi.
"""
import argparse
import base64
import binascii
import datetime
import hashlib
import itertools
import json
import re
import sys
import zipfile
from pathlib import Path
import xml.etree.ElementTree as ET

# Ekspor Odoo diberi nama menurut bahasa antarmuka; yang tetap hanya akhiran modelnya (research D2).
MODEL_SUFFIXES = {'categories': '(pos.category).xlsx', 'attributes': '(product.attribute).xlsx', 'products': '(product.template).xlsx'}

# Halaman tambahan ekspor produk: "<nama> (product.template) page 2.xlsx", diurutkan menurut nomor halaman.
PAGE_PATTERN = re.compile(r'\(product\.template\) page (\d+)\.xlsx$')
NO_COUNT_REASON = 'Jumlah varian dari tabel pemetaan; halaman ekspor tanpa kolom jumlah varian'

# Judul kolom per bahasa antarmuka Odoo (research D2). Kolom yang tidak ada dianggap kosong.
ALIASES = {
    'category_name': ('Display Name', 'Nama Tampilan'),
    'attribute': ('Attribute', 'Atribut'),
    'values': ('Values', 'Nilai'),
    'creation_mode': ('Variants Creation Mode', 'Mode Pembuatan Varian'),
    'name': ('Name', 'Nama'),
    'price': ('Sales Price', 'Harga Jual'),
    'variant_count': ('# Product Variants', '# Varian Produk'),
    'image': ('Image', 'Gambar 1024'),
    'kit': ('Is Kits',),
    'bom': ('# Bill of Material',),
    'item_attributes': ('Product Attributes', 'Atribut produk'),
}
REQUIRED_COLUMNS = {'categories': ('category_name',), 'attributes': ('attribute',), 'products': ('name', 'price', 'variant_count')}

NS = {
    'm': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
    'r': 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
}
REL_NS = '{http://schemas.openxmlformats.org/package/2006/relationships}'
MAX_PART_BYTES = 200 * 1024 * 1024
MAX_PHOTO_BYTES = 5 * 1024 * 1024  # sama dengan ImageUploadService::MAX_KILOBYTES
TRUNCATED_PREFIXES = ('The content of this cell is too long', 'Konten pada kolom ini terlalu panjang')
PLACEHOLDER = '(belum diisi {})'
MAGIC = {b'\xff\xd8\xff': '.jpg', b'\x89PNG\r\n\x1a\n': '.png'}


# --- Pembaca XLSX --------------------------------------------------------------------------------

def _safe_xml(z, part):
    # Ekspor dari luar repo diperlakukan sebagai masukan tak tepercaya: tolak bagian raksasa (zip bomb)
    # dan deklarasi DTD/entitas (billion laughs / XXE). Bagian XLSX yang sah tidak pernah membawa DTD.
    if z.getinfo(part).file_size > MAX_PART_BYTES:
        raise ValueError(f'{part}: ukuran tak-terkompresi melebihi batas')
    raw = z.read(part)
    if b'<!DOCTYPE' in raw[:4096].upper() or b'<!ENTITY' in raw.upper():
        raise ValueError(f'{part}: deklarasi DTD/entitas tidak diizinkan')
    return ET.fromstring(raw)


def _col_index(ref):
    idx = 0
    for ch in re.match(r'([A-Z]+)', ref).group(1):
        idx = idx * 26 + (ord(ch) - 64)
    return idx - 1


def _cell_value(c, shared):
    t = c.get('t')
    v = c.find('m:v', NS)
    if t == 's' and v is not None:
        return shared[int(v.text)]
    if t == 'inlineStr':
        return ''.join(x.text or '' for x in c.iter(f"{{{NS['m']}}}t"))
    if t == 'b' and v is not None:
        return v.text == '1'
    if v is None or v.text is None:
        return None
    try:
        num = float(v.text)
        return int(num) if num.is_integer() else num
    except ValueError:
        return v.text


def read_sheet(path):
    """Baris lembar pertama sebagai dict judul-kolom → nilai (sel jarang ditempatkan menurut huruf kolom)."""
    with zipfile.ZipFile(path) as z:
        shared = []
        if 'xl/sharedStrings.xml' in z.namelist():
            for si in _safe_xml(z, 'xl/sharedStrings.xml').findall('m:si', NS):
                shared.append(''.join(t.text or '' for t in si.iter(f"{{{NS['m']}}}t")))
        wb = _safe_xml(z, 'xl/workbook.xml')
        rels = _safe_xml(z, 'xl/_rels/workbook.xml.rels')
        rel_map = {r.get('Id'): r.get('Target') for r in rels.findall(f'{REL_NS}Relationship')}
        sheet = wb.find('m:sheets', NS)[0]
        target = rel_map[sheet.get(f"{{{NS['r']}}}id")].lstrip('/')
        target = target if target.startswith('xl/') else 'xl/' + target
        rows = []
        for row in _safe_xml(z, target).iter(f"{{{NS['m']}}}row"):
            cells = {_col_index(c.get('r')): _cell_value(c, shared) for c in row.findall('m:c', NS)}
            rows.append([cells.get(i) for i in range((max(cells) + 1) if cells else 0)])
    if not rows:
        return []
    header = rows[0]
    return [{h: (r[i] if i < len(r) else None) for i, h in enumerate(header) if h} for r in rows[1:]]


def exported_on(path):
    """Tanggal ekspor dari docProps/core.xml; jatuh ke tanggal ubah berkas."""
    with zipfile.ZipFile(path) as z:
        if 'docProps/core.xml' in z.namelist():
            core = _safe_xml(z, 'docProps/core.xml')
            for tag in ('modified', 'created'):
                el = core.find(f'{{http://purl.org/dc/terms/}}{tag}')
                if el is not None and el.text and re.match(r'\d{4}-\d{2}-\d{2}', el.text):
                    return el.text[:10]
    return datetime.date.fromtimestamp(path.stat().st_mtime).isoformat()


def find_sources(src):
    """Satu berkas per model Odoo, dikenali menurut akhiran nama; (peta, galat)."""
    found, errors = {}, []
    for key, suffix in MODEL_SUFFIXES.items():
        matches = sorted(p for p in src.iterdir() if p.is_file() and p.name.endswith(suffix) and not p.name.startswith('~'))
        if len(matches) != 1:
            errors.append(f"Folder sumber harus berisi tepat satu berkas '*{suffix}', ditemukan {len(matches)}")
        else:
            found[key] = matches[0]
    return found, errors


def find_pages(src):
    """Berkas halaman tambahan ekspor produk, berurutan menurut nomor halaman."""
    pages = [(int(m.group(1)), p) for p in src.iterdir()
             if p.is_file() and not p.name.startswith('~') and (m := PAGE_PATTERN.search(p.name))]
    return [p for _, p in sorted(pages)]


def _get(row, key):
    """Nilai kolom menurut alias; None bila kolomnya tidak ada atau selnya kosong."""
    for header in ALIASES[key]:
        if header in row and row[header] not in (None, ''):
            return row[header]
    return None


def _has_column(rows, key):
    return bool(rows) and any(h in rows[0] for h in ALIASES[key])


def _norm(value):
    """Rapikan spasi: awal/akhir dibuang, spasi beruntun menjadi satu (research D12)."""
    return re.sub(r'\s+', ' ', str(value)).strip()


def _is_option_only(mode):
    text = str(mode or '').lower()
    return 'option' in text or 'opsi' in text


# --- Pembaca catalog-mapping.md ------------------------------------------------------------------

def _cells(line):
    return [c.strip() for c in line.strip().strip('|').split('|')]


def _none_if_dash(text):
    text = (text or '').strip()
    return None if text in ('', '—', '-') else text


def parse_mapping(text, errors):
    mapping = {'status': None, 'approved_by_role': None, 'approved_on': None, 'initial_stock': None,
               'outlet': None, 'withdraw_others': None, 'stations': {'Bar': ['Bar'], 'Dapur': ['Dapur']},
               'categories': {}, 'attribute_values': {}, 'item_names': [], 'deferred': {}, 'special_prices': [],
               'variant_names': [], 'photo_overrides': [], 'group_links': []}
    h2, h3 = None, None
    for line in text.splitlines():
        status = re.match(r'^\*\*Status\*\*:\s*(\S+)', line)
        if status:
            token = status.group(1)
            mapping['status'] = {'DRAF': 'draft', 'Disetujui': 'approved'}.get(token)
            if mapping['status'] is None:
                errors.append(f'Status persetujuan tidak dikenal: {token!r} (harus DRAF atau Disetujui)')
            continue
        approver = re.match(r'^\*\*Disetujui oleh \(peran\)\*\*:\s*(.*?)\s*·\s*\*\*Tanggal\*\*:\s*(.*?)\s*$', line)
        if approver:
            mapping['approved_by_role'] = _none_if_dash(approver.group(1))
            mapping['approved_on'] = _none_if_dash(approver.group(2))
            continue
        stock = re.match(r'^\*\*Stok awal\*\*:\s*(.*?)\s*$', line)
        if stock:
            # "—" = tanpa pelacakan stok (FR-013); angka = stok awal tiap varian baru, pelacakan dinyalakan.
            value = _none_if_dash(stock.group(1))
            if value is not None and not re.fullmatch(r'\d+', value.replace('.', '')):
                errors.append(f'Stok awal tidak terbaca: {value!r} (harus bilangan bulat ≥ 0 atau —)')
            else:
                mapping['initial_stock'] = None if value is None else int(value.replace('.', ''))
            continue
        outlet = re.match(r'^\*\*Outlet\*\*:\s*(.*?)\s*·\s*\*\*Kode usulan\*\*:\s*(.*?)\s*·\s*\*\*Buat outlet\*\*:\s*(.*?)\s*$', line)
        if outlet:
            create_when = {'mode kosong': 'mode_empty', 'demo bila belum ada': 'demo_missing'}.get(outlet.group(3).lower())
            codes = [c.strip().upper() for c in outlet.group(2).split(',') if c.strip()]
            if create_when is None:
                errors.append(f"Baris **Outlet**: 'Buat outlet' harus 'mode kosong' atau 'DEMO bila belum ada', bukan {outlet.group(3)!r}")
            if not codes or len(codes) > 5 or any(not re.fullmatch(r'[A-Z0-9]{3}', c) for c in codes):
                errors.append(f"Baris **Outlet**: 'Kode usulan' harus 1–5 kode 3 huruf/angka, bukan {outlet.group(2)!r}")
            mapping['outlet'] = {'name': outlet.group(1).strip(), 'codes': codes, 'create_when': create_when}
            continue
        withdraw = re.match(r'^\*\*Tarik item lain dari menu outlet\*\*:\s*LIVE\s+(ya|tidak)\s*,\s*DEMO\s+(ya|tidak)\s*$', line, re.I)
        if withdraw:
            mapping['withdraw_others'] = {'live': withdraw.group(1).lower() == 'ya', 'demo': withdraw.group(2).lower() == 'ya'}
            continue
        stations = re.match(r'^\*\*Stasiun\*\*:\s*(.*?)\s*$', line)
        if stations:
            parsed = {}
            for part in stations.group(1).split(';'):
                role, sep, names = part.partition('=')
                role = role.strip()
                if role not in ('Bar', 'Dapur') or not sep or not [n for n in names.split(',') if n.strip()]:
                    errors.append(f"Baris **Stasiun** tidak terbaca: {part.strip()!r} (bentuk: Bar = nama, alias; Dapur = nama, alias)")
                    continue
                parsed[role] = [n.strip() for n in names.split(',') if n.strip()]
            if set(parsed) == {'Bar', 'Dapur'}:
                mapping['stations'] = parsed
            else:
                errors.append('Baris **Stasiun** harus memuat peran Bar dan Dapur')
            continue
        if line.startswith('## '):
            h2, h3 = line[3:].strip(), None
            continue
        heading = re.match(r'^### (.+?)(?: → (.+?))? \((Bar|Dapur)\)( — tanpa item)?\s*$', line)
        if heading:
            h3 = heading.group(1).strip()
            mapping['categories'][h3] = {'target': (heading.group(2) or heading.group(1)).strip(), 'station': heading.group(3),
                                         'empty': heading.group(4) is not None, 'rows': []}
            continue
        if not line.startswith('|') or line.startswith('|---'):
            continue
        cells = _cells(line)
        if h2 == 'Pemetaan per item' and h3 and cells[0] != 'Item':
            if len(cells) < 5:
                errors.append(f'Baris pemetaan tidak lengkap: {line.strip()}')
                continue
            try:
                price = int(cells[1].replace('.', ''))
            except ValueError:
                errors.append(f"Harga tidak terbaca untuk '{cells[0]}': {cells[1]!r}")
                continue
            mapping['categories'][h3]['rows'].append(
                {'item': cells[0], 'price': price, 'variants': cells[2], 'note': cells[4]})
        elif h2 == 'Harga varian khusus' and cells[0] != 'Item':
            try:
                mapping['special_prices'].append((cells[0], cells[1], int(cells[2].replace('.', ''))))
            except (IndexError, ValueError):
                errors.append(f'Baris harga varian khusus tidak terbaca: {line.strip()}')
        elif h2 == 'Nilai atribut' and cells[0] != 'Atribut':
            values = [v.strip() for v in cells[1].split(',') if v.strip()] if len(cells) > 1 else []
            if not values:
                errors.append(f'Baris nilai atribut tidak lengkap: {line.strip()}')
                continue
            mapping['attribute_values'][_norm(cells[0])] = values
        elif h2 == 'Item ditunda' and cells[0] != 'Item':
            if len(cells) < 2 or not cells[1]:
                errors.append(f'Baris item ditunda tidak lengkap: {line.strip()}')
                continue
            mapping['deferred'][_norm(cells[0])] = cells[1]
        elif h2 == 'Nama item khusus' and cells[0] != 'Item sumber':
            if len(cells) < 2 or not cells[1]:
                errors.append(f'Baris nama item khusus tidak lengkap: {line.strip()}')
                continue
            mapping['item_names'].append((_norm(cells[0]), _norm(cells[1])))
        elif h2 == 'Nama varian khusus' and cells[0] != 'Item':
            if len(cells) < 3 or not cells[2]:
                errors.append(f'Baris nama varian khusus tidak lengkap: {line.strip()}')
                continue
            mapping['variant_names'].append((cells[0], cells[1], cells[2]))
        elif h2 == 'Foto pengganti' and cells[0] != 'Item':
            if len(cells) < 2 or not cells[1]:
                errors.append(f'Baris foto pengganti tidak lengkap: {line.strip()}')
                continue
            mapping['photo_overrides'].append((cells[0], cells[1]))
        elif h2 == 'Grup pilihan' and cells[0] != 'Grup':
            if len(cells) < 2:
                errors.append(f'Baris grup pilihan tidak lengkap: {line.strip()}')
                continue
            mapping['group_links'].append((cells[0], cells[1]))
    if mapping['status'] is None and not any('Status' in e for e in errors):
        errors.append('Baris **Status** tidak ditemukan di tabel pemetaan')
    if mapping['outlet'] is None and not any('**Outlet**' in e for e in errors):
        errors.append('Baris **Outlet** tidak ditemukan di tabel pemetaan')
    if mapping['withdraw_others'] is None:
        errors.append('Baris **Tarik item lain dari menu outlet** tidak ditemukan di tabel pemetaan')
    return mapping


def _short_label(value):
    return value.split(' (')[0].strip()


def spec_attribute_names(spec):
    """Nama atribut yang dirujuk kolom "Varian usulan" (untuk dicocokkan dengan atribut item di Odoo)."""
    spec = spec.strip()
    if spec == 'Standard':
        return set()
    if '×' in spec:
        return {a.strip() for a in spec.partition('→')[0].split('×')}
    return {spec.partition(':')[0].strip()}


def expand_variants(spec, attributes, item, errors, placeholders=None):
    """Kolom "Varian usulan" → daftar varian {name, values} (data-model §5).

    Nilai `?` berarti nama belum diisi pemilik (069 research D3): diberi nama sementara "(belum diisi n)", dicatat
    di `placeholders`, dan hanya sah untuk atribut yang nilainya memang tidak ada di ekspor."""
    spec = spec.strip()
    if spec == 'Standard':
        return [{'name': 'Standard', 'values': {}}]

    def variant_attribute(name):
        attr = attributes.get(name)
        if attr is None or not attr['creates_variants']:
            errors.append(f"Item '{item}': '{name}' bukan atribut pembentuk varian di sumber")
            return None
        return attr

    if '×' in spec:
        left, _, info = spec.partition('→')
        names = [a.strip() for a in left.split('×')]
        attrs = [variant_attribute(n) for n in names]
        if None in attrs:
            return []
        variants = [{'name': ' '.join(_short_label(v) for v in combo), 'values': dict(zip(names, combo))}
                    for combo in itertools.product(*(a['values'] for a in attrs))]
        listed = [n.strip() for n in info.split(',') if n.strip()]
        if listed and listed != [v['name'] for v in variants]:
            errors.append(f"Item '{item}': nama setelah '→' {listed} ≠ hasil hitungan {[v['name'] for v in variants]}")
        return variants

    name, sep, values_text = spec.partition(':')
    if not sep:
        errors.append(f"Item '{item}': kolom varian tidak dikenali: {spec!r}")
        return []
    attr = variant_attribute(name.strip())
    if attr is None:
        return []
    values = [v.strip() for v in values_text.split(',') if v.strip()]
    if '?' in values:
        if attr['values_from'] == 'odoo':
            errors.append(f"Item '{item}': '?' hanya untuk atribut yang nilainya tidak ada di ekspor; '{attr['name']}' punya nilai di sumber")
            return []
        resolved = []
        for position, value in enumerate(values, 1):
            if value == '?':
                value = PLACEHOLDER.format(position)
                if value not in attr['values']:
                    attr['values'].append(value)
                if placeholders is not None:
                    placeholders.append(f"{item} / {attr['name']} #{position}")
            resolved.append(value)
        values = resolved
    unknown = [v for v in values if v not in attr['values']]
    if unknown:
        errors.append(f"Item '{item}': nilai {unknown} tidak ada di atribut '{attr['name']}'")
        return []
    return [{'name': v, 'values': {attr['name']: v}} for v in values]


# --- Perakitan katalog ---------------------------------------------------------------------------

def _to_int(value):
    return int(float(value)) if value not in (None, '') else 0


def _photo(value, item, errors):
    """(nama berkas, bytes, masalah) dari sel Image."""
    if value in (None, ''):
        return None, None, 'missing'
    text = str(value)
    if text.startswith(TRUNCATED_PREFIXES):
        return None, None, 'truncated'
    try:
        data = base64.b64decode(''.join(text.split()), validate=True)
    except (binascii.Error, ValueError):
        errors.append(f"Item '{item}': sel Image bukan base64 yang sah")
        return None, None, None
    return _checked_photo(data, item, errors)


def _checked_photo(data, item, errors):
    """Aturan yang sama untuk foto dari ekspor maupun foto pengganti: JPEG/PNG, paling besar 5 MB."""
    ext = next((e for magic, e in MAGIC.items() if data.startswith(magic)), None)
    if ext is None:
        errors.append(f"Item '{item}': foto bukan JPEG/PNG")
        return None, None, None
    if len(data) > MAX_PHOTO_BYTES:
        errors.append(f"Item '{item}': foto melebihi 5 MB")
        return None, None, None
    return hashlib.sha256(data).hexdigest()[:12] + ext, data, None


def build(src, mapping_path):
    """(catalog, foto, galat, catatan). Semua masalah dikumpulkan dulu; catatan = hal yang perlu dilihat pembaca."""
    errors = []
    notes = {'placeholders': [], 'tidied': []}
    files, file_errors = find_sources(src)
    errors += file_errors
    if file_errors:
        return None, {}, errors, notes
    mapping = parse_mapping(mapping_path.read_text(encoding='utf-8'), errors)

    sheets = {key: read_sheet(path) for key, path in files.items()}
    pages = find_pages(src)
    page_rows = []
    for page in pages:
        rows = read_sheet(page)
        for column in ('name', 'price'):
            if rows and not _has_column(rows, column):
                errors.append(f"{page.name}: kolom {' / '.join(ALIASES[column])} tidak ditemukan")
        no_count = bool(rows) and not _has_column(rows, 'variant_count')
        for r in rows:
            r['__no_count'] = no_count
        page_rows += rows
    for key, required in REQUIRED_COLUMNS.items():
        for column in required:
            if sheets[key] and not _has_column(sheets[key], column):
                errors.append(f"{files[key].name}: kolom {' / '.join(ALIASES[column])} tidak ditemukan")
    if errors:
        return None, {}, errors, notes
    sheets['products'] = sheets['products'] + page_rows

    # Kategori: tiap kategori sumber punya bagian ### di tabel pemetaan; yang bertanda "tanpa item" dikecualikan.
    source_categories = [_norm(_get(r, 'category_name')) for r in sheets['categories'] if _get(r, 'category_name')]
    for name in source_categories:
        if name not in mapping['categories']:
            errors.append(f"Kategori sumber '{name}' tidak punya bagian ### di tabel pemetaan")
    for name in mapping['categories']:
        if name not in source_categories:
            errors.append(f"Bagian ### '{name}' di tabel pemetaan bukan kategori sumber")
    categories, excluded_categories, targets = [], [], {}
    for name in source_categories:
        section = mapping['categories'].get(name)
        if section is None:
            continue
        if section['empty']:
            if section['rows']:
                errors.append(f"Kategori '{name}' bertanda 'tanpa item' tetapi punya baris item")
            excluded_categories.append({'source': 'categories', 'name': name, 'reason': 'Tanpa item di ekspor produk'})
            continue
        if not section['rows']:
            errors.append(f"Kategori '{name}' tidak punya baris item; tandai judulnya dengan '— tanpa item'")
        if section['target'] in targets:
            errors.append(f"Kategori sumber '{name}' dan '{targets[section['target']]}' bertujuan ke nama yang sama '{section['target']}'")
        targets[section['target']] = name
        categories.append({'name': section['target'], 'source_name': name, 'order': len(categories), 'station': section['station']})

    # Atribut: nilai dari ekspor bila ada (values_from=odoo), selain itu dari "## Nilai atribut" (pemetaan).
    attributes, current = [], None
    for r in sheets['attributes']:
        attr_name = _get(r, 'attribute')
        if attr_name:
            current = {'name': _norm(attr_name), 'creates_variants': not _is_option_only(_get(r, 'creation_mode')),
                       'values_from': 'odoo', 'values': []}
            attributes.append(current)
        value = _get(r, 'values')
        if value is not None and current is not None:
            value, prefix = str(value), current['name'] + ': '
            current['values'].append((value[len(prefix):] if value.startswith(prefix) else value).strip())
    file_value_count = sum(len(a['values']) for a in attributes)
    attr_by_name = {a['name']: a for a in attributes}
    for a in attributes:
        if not a['values']:
            a['values_from'] = 'pemetaan'
    for name, values in mapping['attribute_values'].items():
        attr = attr_by_name.get(name)
        if attr is None:
            errors.append(f"'## Nilai atribut': '{name}' bukan atribut sumber")
        elif attr['values_from'] == 'odoo':
            errors.append(f"'## Nilai atribut': '{name}' sudah punya nilai di ekspor; hapus barisnya")
        else:
            attr['values'] = list(values)

    # Baris lanjutan (Name kosong) hanya membawa BoM ke-2 dst.; tidak dihitung sebagai produk.
    products = [r for r in sheets['products'] if _get(r, 'name')]
    excluded, menu = [], []
    for r in products:
        ref = str(r.get('Internal Reference') or r.get('Referensi Internal') or '').strip()
        raw = str(_get(r, 'name'))
        if ref.startswith('nf_'):
            excluded.append({'source': 'products', 'name': raw.strip(),
                             'reason': f'Produk teknis Odoo ({ref}), bukan menu'})
        else:
            menu.append(r)

    rows_by_item = {}
    for section_name, section in mapping['categories'].items():
        for row in section['rows']:
            if row['item'] in rows_by_item:
                errors.append(f"Item '{row['item']}' muncul dua kali di tabel pemetaan")
            rows_by_item[row['item']] = {**row, 'category': section['target']}
    menu_names = {_norm(_get(r, 'name')) for r in menu}
    for name in rows_by_item:
        if name not in menu_names:
            errors.append(f"Baris pemetaan '{name}' tidak punya item sumber")

    odoo_attr_column = _has_column(sheets['products'], 'item_attributes')
    items, photos = [], {}
    for r in menu:
        raw = str(_get(r, 'name'))
        name = _norm(raw)
        if name != raw:
            notes['tidied'].append(f'{raw!r} → {name!r}')
        row = rows_by_item.get(name)
        if row is None:
            errors.append(f"Item sumber '{name}' tidak punya baris di tabel pemetaan")
            continue
        if name in mapping['deferred']:
            # Item ditunda: tetap dipertanggungjawabkan sebagai produk dikecualikan, dan dibuat kelak lewat jalan ulang.
            excluded.append({'source': 'products', 'name': name, 'reason': f"Ditunda: {mapping['deferred'][name]}"})
            continue
        price = _to_int(_get(r, 'price'))
        if row['price'] != price:
            errors.append(f"Item '{name}': harga di tabel pemetaan {row['price']} ≠ Sales Price sumber {price}")
        variants = expand_variants(row['variants'], attr_by_name, name, errors, notes['placeholders'])
        no_count = r.get('__no_count', False)
        odoo_count = max(len(variants), 1) if no_count else _to_int(_get(r, 'variant_count'))
        if odoo_attr_column:
            odoo_attrs = {_norm(x) for x in str(_get(r, 'item_attributes') or '').split(',') if x.strip()}
            mapped_attrs = spec_attribute_names(row['variants'])
            if odoo_attrs != mapped_attrs:
                errors.append(f"Item '{name}': atribut di tabel pemetaan {sorted(mapped_attrs)} ≠ atribut item di Odoo {sorted(odoo_attrs)}")
        override = re.search(r'≠Odoo:\s*([^;]+)', row['note'])
        override = override.group(1).strip() if override else (NO_COUNT_REASON if no_count else None)
        if variants and len(variants) != odoo_count and override is None:
            errors.append(f"Item '{name}': jumlah varian {len(variants)} ≠ Odoo {odoo_count} (tambahkan '≠Odoo: <alasan>' bila disengaja)")
        filename, data, issue = _photo(_get(r, 'image'), name, errors)
        if filename:
            photos[filename] = data
        items.append({
            'name': name,
            'category': row['category'],
            'price': price,
            'odoo_variant_count': odoo_count,
            'variants': [{'name': v['name'], 'price': price, 'values': v['values']} for v in variants],
            'variant_count_override': override,
            'photo': filename,
            'photo_issue': issue,
            'odoo_kit': str(_get(r, 'kit')).lower() == 'true' or _to_int(_get(r, 'bom')) > 0,
        })

    for name in mapping['deferred']:
        if name not in rows_by_item:
            errors.append(f"'## Item ditunda': '{name}' tidak ada di tabel pemetaan")
    items_by_name = {i['name']: i for i in items}
    for item, variant, price in mapping['special_prices']:
        target = next((v for v in items_by_name.get(item, {}).get('variants', []) if v['name'] == variant), None)
        if target is None:
            errors.append(f"Harga varian khusus: '{item}' / '{variant}' tidak ada di katalog")
        else:
            target['price'] = price

    # Sesudah harga: kedua tabel memakai nama varian hasil hitungan sebagai kunci, bukan nama barunya.
    for item, variant, new_name in mapping['variant_names']:
        target = next((v for v in items_by_name.get(item, {}).get('variants', []) if v['name'] == variant), None)
        if target is None:
            errors.append(f"Nama varian khusus: '{item}' / '{variant}' tidak ada di katalog")
        else:
            target['name'] = new_name
    for item in items:
        names = [v['name'] for v in item['variants']]
        if len(names) != len(set(names)):
            errors.append(f"Item '{item['name']}': nama varian kembar sesudah penggantian nama")

    # Foto pengganti untuk item yang fotonya terpotong/kosong di ekspor, atau yang diganti pemilik.
    # Berkasnya disimpan di samping tabel pemetaan; jalurnya relatif terhadap folder tabel itu.
    for item, relative in mapping['photo_overrides']:
        target = items_by_name.get(item)
        path = (mapping_path.parent / relative).resolve()
        if target is None:
            errors.append(f"Foto pengganti: item '{item}' tidak ada di katalog")
        elif mapping_path.parent.resolve() not in path.parents or not path.is_file():
            errors.append(f"Foto pengganti '{item}': berkas {relative!r} tidak ada di folder tabel pemetaan")
        else:
            filename, data, _ = _checked_photo(path.read_bytes(), item, errors)
            if filename:
                photos[filename] = data
                target['photo'], target['photo_issue'] = filename, None

    option_groups = [{'name': a['name'], 'from_attribute': a['name'], 'required': True,
                      'options': [v for v in a['values'] if v.lower() != 'test'], 'attach_to': []}
                     for a in attributes if not a['creates_variants'] and a['values']]
    groups_by_name = {g['name']: g for g in option_groups}
    for group, item in mapping['group_links']:
        if group not in groups_by_name:
            errors.append(f"Grup pilihan '{group}' bukan atribut opsi di sumber")
        elif item not in items_by_name:
            errors.append(f"Grup pilihan '{group}': item '{item}' tidak ada di katalog")
        else:
            groups_by_name[group]['attach_to'].append(item)

    # Nama item khusus: dipakai paling akhir, supaya semua tabel di atas memakai nama sumber sebagai kunci.
    for source_name, new_name in mapping['item_names']:
        target = items_by_name.get(source_name)
        if target is None:
            errors.append(f"Nama item khusus: '{source_name}' tidak ada di katalog")
            continue
        target['name'] = new_name
        for group in option_groups:
            group['attach_to'] = [new_name if n == source_name else n for n in group['attach_to']]
    pairs = [(i['category'], i['name']) for i in items]
    if len(pairs) != len(set(pairs)):
        errors.append('Ada dua item dengan nama dan kategori sama sesudah penggantian nama item')

    used = {(attr, value) for it in items for v in it['variants'] for attr, value in v['values'].items()}
    used_attrs = {attr for attr, _ in used}
    excluded += excluded_categories
    for a in attributes:
        if a['creates_variants'] and a['name'] not in used_attrs:
            excluded.append({'source': 'attributes', 'name': a['name'], 'reason': 'Tidak dipakai item mana pun'})
    for a in attributes:
        for value in a['values']:
            label = f"{a['name']}: {value}"
            if value.lower() == 'test':
                excluded.append({'source': 'attribute_values', 'name': label, 'reason': 'Data uji'})
            elif a['values_from'] == 'odoo' and a['creates_variants'] and a['name'] in used_attrs and (a['name'], value) not in used:
                excluded.append({'source': 'attribute_values', 'name': label, 'reason': 'Tidak dipakai varian mana pun'})
    # Atribut tanpa satu pun nilai dan tidak dipakai tidak bisa masuk katalog (nilai wajib ada); cukup tercatat sebagai dikecualikan.
    attributes = [a for a in attributes if a['values']]

    if mapping['status'] == 'approved':
        if not mapping['approved_by_role'] or not re.fullmatch(r'\d{4}-\d{2}-\d{2}', mapping['approved_on'] or ''):
            errors.append('Status Disetujui wajib disertai peran penyetuju dan tanggal (YYYY-MM-DD)')
        if notes['placeholders']:
            errors.append(f"Status Disetujui, tetapi {len(notes['placeholders'])} nama varian masih '?': "
                          + '; '.join(notes['placeholders'][:5]) + (' …' if len(notes['placeholders']) > 5 else ''))
    approval = {'status': mapping['status'] or 'draft',
                'approved_on': mapping['approved_on'] if mapping['status'] == 'approved' else None,
                'approved_by_role': mapping['approved_by_role'] if mapping['status'] == 'approved' else None}

    catalog = {
        'format': 2,
        'outlet': mapping['outlet'],
        'withdraw_others': mapping['withdraw_others'],
        'stations': mapping['stations'],
        'source': {
            'system': 'Odoo',
            'exported_on': exported_on(files['products']),
            'files': {**{key: path.name for key, path in files.items()}, **({'product_pages': [p.name for p in pages]} if pages else {})},
            'rows': {'categories': len(source_categories),
                     'attribute_values': file_value_count,
                     'products': len(products)},
        },
        'approval': approval,
        'initial_stock': mapping['initial_stock'],
        'categories': categories,
        'attributes': attributes,
        'option_groups': option_groups,
        'items': items,
        'excluded': excluded,
    }
    return catalog, photos, errors, notes


def write_outputs(out, catalog, photos):
    photo_dir = out / 'photos'
    photo_dir.mkdir(parents=True, exist_ok=True)
    for filename, data in photos.items():
        path = photo_dir / filename
        if not path.exists() or path.read_bytes() != data:
            path.write_bytes(data)
    for stale in photo_dir.iterdir():
        if stale.suffix in ('.jpg', '.png') and stale.name not in photos:
            stale.unlink()
    (out / 'catalog.json').write_text(json.dumps(catalog, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')


def main(argv=None):
    parser = argparse.ArgumentParser(description='Bangkitkan catalog.json katalog WJM dari ekspor Odoo.')
    parser.add_argument('--src', required=True, type=Path, help='folder berisi tiga ekspor XLSX Odoo')
    parser.add_argument('--mapping', required=True, type=Path, help='catalog-mapping.md yang ditinjau pemilik')
    parser.add_argument('--out', required=True, type=Path, help='folder keluaran (catalog.json + photos/)')
    args = parser.parse_args(argv)

    catalog, photos, errors, notes = build(args.src, args.mapping)
    if errors:
        print(f'{len(errors)} masalah; tidak ada berkas yang ditulis:', file=sys.stderr)
        for error in errors:
            print(f'  - {error}', file=sys.stderr)
        return 1

    write_outputs(args.out, catalog, photos)
    items = catalog['items']
    excluded = catalog['excluded']
    print(f"Katalog ditulis ke {args.out / 'catalog.json'}")
    print(f"  kategori {len(catalog['categories'])} · item {len(items)} · "
          f"varian {sum(len(i['variants']) for i in items)} · grup pilihan {len(catalog['option_groups'])}")
    print(f"  foto: {len(photos)} berkas untuk {sum(1 for i in items if i['photo'])} item, "
          f"terpotong {sum(1 for i in items if i['photo_issue'] == 'truncated')}, "
          f"kosong {sum(1 for i in items if i['photo_issue'] == 'missing')}")
    print('  dikecualikan: ' + ', '.join(
        f"{src} {sum(1 for e in excluded if e['source'] == src)}" for src in ('products', 'categories', 'attributes', 'attribute_values')))
    print(f"  persetujuan: {catalog['approval']['status']}")
    if notes['placeholders']:
        print(f"  nama varian sementara \"(belum diisi n)\": {len(notes['placeholders'])} (pemilik harus mengisi sebelum persetujuan)")
    if notes['tidied']:
        print(f"  nama dirapikan ({len(notes['tidied'])}): " + '; '.join(notes['tidied']))
    return 0


if __name__ == '__main__':
    sys.exit(main())
