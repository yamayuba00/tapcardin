# Setiap <button> mentah wajib menyatakan kursor pointer: kelas statis berisi `cursor-`, atau kelas terikat
# (`:class`) yang diatur komponen. Aturan CSS dasar di app.css tetap memberi pointer sebagai jaring pengaman.
import pathlib, re, sys

sys.stdout.reconfigure(encoding='utf-8')
bad = []
for p in pathlib.Path('resources/js').rglob('*.vue'):
    for m in re.finditer(r'<button\b[^>]*>', p.read_text(encoding='utf-8'), re.S):
        tag = m.group(0)
        if 'cursor-' not in tag and ':class=' not in tag:
            bad.append(f"{p}: {' '.join(tag.split())[:100]}")
print("\n".join(bad))
sys.exit(1 if bad else 0)
