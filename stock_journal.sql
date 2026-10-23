-- 2026-10-11.stock_journal.sql  (v2: label enum huruf kecil, sesuai enum reference_type yang sudah ada)
-- Jalankan statement SATU PER SATU (atau lewat psql). Bila ada yang error, kirim pesan error-nya.

-- 0. Sinkronkan sequence (jaga-jaga: id COA lama diinsert manual sehingga sequence bisa tertinggal -> duplicate key)
SELECT setval(pg_get_serial_sequence('chart_of_accounts','id'), (SELECT MAX(id) FROM chart_of_accounts));
SELECT setval(pg_get_serial_sequence('fiscal_config','id'), GREATEST((SELECT COALESCE(MAX(id),0) FROM fiscal_config), 1));

-- 1. Akun WIP baru (aset); induk = ASET (1000)
INSERT INTO chart_of_accounts (account_code, account_name, account_type, parent_id, is_active)
SELECT '1700', 'Pekerjaan Dalam Proses (WIP) Proyek', 'asset', p.id, true
  FROM chart_of_accounts p
 WHERE p.account_code = '1000'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '1700');

-- 2. Nilai enum baru. Jalankan sebagai statement sendiri (autocommit), BUKAN di dalam BEGIN...COMMIT bersama bagian 3.
ALTER TYPE reference_type ADD VALUE IF NOT EXISTS 'material_out';
ALTER TYPE reference_type ADD VALUE IF NOT EXISTS 'supplier_return';

-- 3. Mapping akun di fiscal_config
INSERT INTO fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT 'ISSUE_PROJECT', 'Issue material ke proyek (Dr WIP / Cr Persediaan Bahan Baku)', d.id, c.id, true
  FROM chart_of_accounts d, chart_of_accounts c
 WHERE d.account_code = '1700' AND c.account_code = '1500'
   AND NOT EXISTS (SELECT 1 FROM fiscal_config WHERE rule_code = 'ISSUE_PROJECT');

INSERT INTO fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT 'RETURN_SUPPLIER', 'Kirim retur supplier (Dr GRN Clearing / Cr Persediaan Bahan Baku)', d.id, c.id, true
  FROM chart_of_accounts d, chart_of_accounts c
 WHERE d.account_code = '2600' AND c.account_code = '1500'
   AND NOT EXISTS (SELECT 1 FROM fiscal_config WHERE rule_code = 'RETURN_SUPPLIER');

INSERT INTO fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT 'RETURN_CREDIT_NOTE', 'Nota kredit retur (Dr Hutang Usaha / Cr GRN Clearing)', d.id, c.id, true
  FROM chart_of_accounts d, chart_of_accounts c
 WHERE d.account_code = '2100' AND c.account_code = '2600'
   AND NOT EXISTS (SELECT 1 FROM fiscal_config WHERE rule_code = 'RETURN_CREDIT_NOTE');

-- 4. Verifikasi
SELECT account_code, account_name FROM chart_of_accounts WHERE account_code = '1700';
SELECT rule_code, debit_account_id, credit_account_id FROM fiscal_config
 WHERE rule_code IN ('ISSUE_PROJECT','RETURN_SUPPLIER','RETURN_CREDIT_NOTE');
SELECT enumlabel FROM pg_enum WHERE enumtypid = 'reference_type'::regtype AND enumlabel IN ('material_out','supplier_return');
