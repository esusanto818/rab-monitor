
-- ============================================================
-- list_akun_aturan_tidak_terpakai.sql
-- Daftar akun (COA) dan aturan posting (fiscal_config) beserta bukti pemakaiannya, untuk memutuskan mana yang
-- aman dinonaktifkan. HANYA MEMBACA data (SELECT), tidak mengubah apa pun.
-- Jalankan:  psql -h localhost -U postgres -d proyekGMN -f list_akun_aturan_tidak_terpakai.sql
-- Catatan: sistem baru punya sedikit jurnal, jadi "0 jurnal" BUKAN bukti akun tidak diperlukan (mis. akun Kas/Bank/PPN
-- belum terpakai tetapi akan dipakai). Gabungkan dengan hasil cek_rule_dipakai_kode.sh (pemakaian di kode program).
-- ============================================================

\echo
\echo '=== 1) AKUN: yang BELUM pernah dipakai jurnal / tabel lain tampil paling atas ==='
SELECT c.account_code                         AS kode,
       c.account_name                         AS nama,
       c.account_type::text                   AS tipe,
       CASE WHEN c.is_active THEN 'aktif' ELSE 'nonaktif' END AS status,
       COALESCE(j.n, 0)                       AS baris_jurnal,
       j.terakhir                             AS jurnal_terakhir,
       (SELECT COUNT(*) FROM supplier_payments  x WHERE x.account_id = c.id)
     + (SELECT COUNT(*) FROM project_payments   x WHERE x.account_id = c.id)
     + (SELECT COUNT(*) FROM financial_expenses x WHERE x.account_id = c.id)
     + (SELECT COUNT(*) FROM financial_incomes  x WHERE x.account_id = c.id) AS dipakai_tabel_lain,
       COALESCE(r.rules, '-')                 AS dipakai_aturan_posting,
       CASE WHEN EXISTS (SELECT 1 FROM chart_of_accounts x WHERE x.parent_id = c.id) THEN 'induk' ELSE '' END AS induk
  FROM chart_of_accounts c
  LEFT JOIN (SELECT jl.account_id, COUNT(*) AS n, MAX(je.journal_date) AS terakhir
               FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
              GROUP BY jl.account_id) j ON j.account_id = c.id
  LEFT JOIN (SELECT acc, string_agg(rule_code, ', ' ORDER BY rule_code) AS rules
               FROM (SELECT debit_account_id  AS acc, rule_code FROM fiscal_config WHERE is_active
                     UNION
                     SELECT credit_account_id AS acc, rule_code FROM fiscal_config WHERE is_active) u
              GROUP BY acc) r ON r.acc = c.id
 ORDER BY (COALESCE(j.n, 0) > 0), c.account_code;

\echo
\echo '=== 2) ATURAN POSTING: jurnal_cocok = jumlah jurnal yang memuat pasangan akun debit/kredit aturan ini (perkiraan) ==='
SELECT f.rule_code                               AS aturan,
       f.rule_name                               AS keterangan,
       d.account_code || ' ' || d.account_name   AS akun_debit,
       k.account_code || ' ' || k.account_name   AS akun_kredit,
       CASE WHEN f.is_active THEN 'aktif' ELSE 'nonaktif' END AS status,
       (SELECT COUNT(DISTINCT je.id)
          FROM journal_entries je
          JOIN journal_lines ld ON ld.journal_id = je.id AND ld.account_id = f.debit_account_id  AND ld.debit  > 0
          JOIN journal_lines lk ON lk.journal_id = je.id AND lk.account_id = f.credit_account_id AND lk.credit > 0
         WHERE je.status::text IN ('posted', 'reversed')) AS jurnal_cocok
  FROM fiscal_config f
  JOIN chart_of_accounts d ON d.id = f.debit_account_id
  JOIN chart_of_accounts k ON k.id = f.credit_account_id
 ORDER BY jurnal_cocok, f.rule_code;

\echo
\echo '=== 3) ATURAN KEMBAR: beberapa aturan memakai pasangan akun debit/kredit yang sama (kemungkinan duplikat) ==='
SELECT d.account_code || ' / ' || k.account_code AS pasangan_akun_debit_kredit,
       COUNT(*)                                  AS jumlah_aturan,
       string_agg(f.rule_code, ', ' ORDER BY f.rule_code) AS aturan
  FROM fiscal_config f
  JOIN chart_of_accounts d ON d.id = f.debit_account_id
  JOIN chart_of_accounts k ON k.id = f.credit_account_id
 GROUP BY f.debit_account_id, f.credit_account_id, d.account_code, k.account_code
HAVING COUNT(*) > 1
 ORDER BY 1;
