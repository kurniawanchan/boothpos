#!/usr/bin/env python3
"""Hapus kunci terjemahan dari resources/js/locales/{id,en}.json (pasangan merge_locale.py).

Pemakaian:  python3 -I remove_locale.py keys.txt
keys.txt: satu kunci bertitik per baris ("events.title"), atau awalan namespace berakhiran ".*"
("preorders.*" = seluruh namespace). Kunci yang tidak ada dilewati (idempoten). Kedua bahasa
dibersihkan bersamaan supaya paritas (tes localeParity) tetap terjaga; namespace yang menjadi kosong
ikut dihapus. Format keluaran identik dengan berkas yang ada (indent 2, ensure_ascii=False).
"""
import fcntl, json, os, sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
LOCALES = os.path.join(ROOT, 'resources', 'js', 'locales')


def remove(tree, parts):
    """Hapus satu kunci; kembalikan jumlah daun yang dihapus."""
    head, rest = parts[0], parts[1:]
    if head == '*' and not rest:
        count = sum(1 for _ in _leaves(tree))
        tree.clear()
        return count
    if head not in tree:
        return 0
    if not rest:
        node = tree.pop(head)
        return sum(1 for _ in _leaves(node)) if isinstance(node, dict) else 1
    if not isinstance(tree[head], dict):
        return 0
    n = remove(tree[head], rest)
    if not tree[head]:
        del tree[head]
    return n


def _leaves(node):
    if isinstance(node, dict):
        for v in node.values():
            yield from _leaves(v)
    else:
        yield node


def main(path):
    keys = [l.strip() for l in open(path, encoding='utf-8') if l.strip() and not l.startswith('#')]
    with open(os.path.join(LOCALES, '.lock'), 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        for lang in ('id', 'en'):
            file = os.path.join(LOCALES, f'{lang}.json')
            tree = json.load(open(file, encoding='utf-8'))
            removed = sum(remove(tree, k.split('.')) for k in keys)
            with open(file, 'w', encoding='utf-8') as out:
                json.dump(tree, out, indent=2, ensure_ascii=False)
                out.write('\n')
            print(f'{lang}: {removed} kunci dihapus')
        os.remove(os.path.join(LOCALES, '.lock'))


if __name__ == '__main__':
    main(sys.argv[1])
