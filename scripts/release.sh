#!/bin/bash
# AiPBX sunucu sürümü yayınlar (geliştirici aracı).
#
#   scripts/release.sh 2.1.0
#
# 1. CHANGELOG.md'de "## 2.1.0" başlıklı bölüm olmalı (yenilikler buradan alınır).
# 2. VERSION dosyası güncellenir, commit edilir.
# 3. Açıklamalı git etiketi v2.1.0 oluşturulur (açıklama = CHANGELOG bölümü;
#    kurulumlardaki `aipbx-update --check` ve portalın "Yenilikler" kutusu bunu gösterir).
# 4. Commit ve etiket GitHub'a gönderilir, GitHub Release oluşturulur (gh varsa).
#
# Sürüm numarası: MAJOR.MINOR.PATCH — geriye dönük uyumsuz değişiklik (ör. sütun
# silen migration, kaldırılan API) MAJOR; yeni özellik MINOR; düzeltme PATCH.
set -euo pipefail

VER="${1:-}"
[[ "$VER" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "kullanım: $0 X.Y.Z" >&2; exit 64; }
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

[[ "$(git rev-parse --abbrev-ref HEAD)" == main ]] || { echo "main dalında olmalısınız" >&2; exit 1; }
git diff --quiet && git diff --cached --quiet || { echo "Commit edilmemiş değişiklik var" >&2; exit 1; }
git rev-parse -q --verify "refs/tags/v$VER" >/dev/null && { echo "v$VER zaten var" >&2; exit 1; }

PREV="$(tr -d '[:space:]' < VERSION)"
# VERSION ya yayınlanacak sürümün kendisi (henüz etiketlenmemiş) ya da ondan küçük olmalı.
if [[ "$PREV" != "$VER" && "$(printf '%s\n%s\n' "$PREV" "$VER" | sort -V | tail -1)" != "$VER" ]]; then
    echo "Yeni sürüm ($VER) mevcut sürümden ($PREV) büyük olmalı" >&2; exit 1
fi

NOTES="$(awk -v v="## $VER" '$0 == v {f=1; next} /^## / && f {exit} f' CHANGELOG.md | sed -e :a -e '/^\n*$/{$d;N;ba' -e '}')"
[[ -n "${NOTES// }" ]] || { echo "CHANGELOG.md içinde '## $VER' bölümü yok veya boş" >&2; exit 1; }

echo "$VER" > VERSION
git add VERSION CHANGELOG.md
git diff --cached --quiet || git commit -q -m "release: v$VER"
git tag -a "v$VER" -m "$NOTES"
git push -q origin main
git push -q origin "v$VER"
if command -v gh >/dev/null 2>&1; then
    gh release create "v$VER" --title "AiPBX v$VER" --notes "$NOTES" >/dev/null && echo "GitHub Release oluşturuldu."
fi
echo "v$VER yayınlandı. Kurulumlar: sudo aipbx-update (veya portal → Sistem Güncelleme)"
