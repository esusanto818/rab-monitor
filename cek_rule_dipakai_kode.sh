#!/bin/bash
# ============================================================
# cek_rule_dipakai_kode.sh
# Menghitung, untuk setiap aturan posting di fiscal_config, berapa file program (.php / .js) yang menyebut kodenya.
# Aturan yang disebut 0 file dan 0 jurnal (lihat list_akun_aturan_tidak_terpakai.sql) paling aman dinonaktifkan.
# Hanya membaca; tidak mengubah apa pun.
# Jalankan dari mana saja:  bash cek_rule_dipakai_kode.sh
# ============================================================
cd ~/proyekgmn || { echo "Folder ~/proyekgmn tidak ditemukan"; exit 1; }
printf "%-24s %s\n" "ATURAN" "DISEBUT DI (file)"
psql -h localhost -p 5432 -U postgres -d proyekGMN -Atc "SELECT rule_code FROM fiscal_config ORDER BY rule_code" | while read -r r; do
    files=$(grep -rlE "['\"]$r['\"]" --include=*.php --include=*.js . 2>/dev/null | grep -v -E 'ext-3.4.1|js-func/vis-|node_modules' )
    n=$(printf "%s" "$files" | grep -c . )
    printf "%-24s %s" "$r" "$n"
    [ "$n" -gt 0 ] && printf "   (%s)" "$(printf "%s" "$files" | head -3 | tr '\n' ' ')"
    printf "\n"
done
