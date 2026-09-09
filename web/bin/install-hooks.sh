#!/bin/bash
# AI PBX — git hook kurulumu.
#
# Kullanım: bash bin/install-hooks.sh
#
# Git hook'ları depoda versiyonlanmaz (.git/hooks git'e girmez), bu yüzden
# her klonda/sunucuda bir kez çalıştırılması gerekir.
set -e

REPO="$(cd "$(dirname "$0")/.." && pwd)"
HOOK="$REPO/.git/hooks/pre-commit"

if [ ! -d "$REPO/.git" ]; then
    echo "HATA: $REPO bir git deposu değil." >&2
    exit 1
fi

cat > "$HOOK" <<'HOOKEOF'
#!/bin/bash
# AI PBX duman testi — bin/install-hooks.sh tarafından kuruldu.
# Atlamak için: git commit --no-verify   (alışkanlık hâline getirme!)
REPO="$(git rev-parse --show-toplevel)"

echo "Duman testi çalışıyor..."
if ! php "$REPO/bin/smoke.php"; then
    echo ""
    echo "COMMIT DURDURULDU — duman testi başarısız (yukarıdaki hatalara bak)."
    echo "Gerçekten gerekiyorsa: git commit --no-verify"
    exit 1
fi

# Birim testleri KOŞULLU: test veritabanı kurulmamış bir klonda hook commit'i
# engellemesin (bin/setup-test-db.sh henüz çalıştırılmamış olabilir).
if [ -x "$REPO/vendor/bin/phpunit" ] && [ -f "$REPO/tests/.env.test" ]; then
    echo "Birim testleri çalışıyor..."
    if ! php "$REPO/vendor/bin/phpunit"; then
        echo ""
        echo "COMMIT DURDURULDU — birim testleri başarısız."
        echo "Gerçekten gerekiyorsa: git commit --no-verify"
        exit 1
    fi
fi
HOOKEOF

chmod +x "$HOOK"
echo "Kuruldu: $HOOK"
