-- ============================================================
-- 2026-10-16.project_termin_revenue_account.sql
-- Pisahkan pendapatan termin proyek dari "4200 Pendapatan Jasa Custom/Repair".
--  1) buat akun 4300 Pendapatan Proyek (Termin) di bawah 4000 PENDAPATAN (bila belum ada)
--  2) arahkan fiscal_config.PROJECT_INVOICE (sisi kredit) ke akun 4300
--  3) pindahkan baris kredit jurnal project_invoice yang sudah terposting ke akun 4300
-- Aman dijalankan ulang. OPSIONAL: lewati file ini bila 4200 memang mau dipakai.
-- ============================================================
BEGIN;

INSERT INTO chart_of_accounts (account_code, account_name, account_type, parent_id, is_active)
SELECT '4300', 'Pendapatan Proyek (Termin)',
       (SELECT account_type FROM chart_of_accounts WHERE account_code = '4200'),   -- tipe sama dgn 4200 (revenue)
       (SELECT id FROM chart_of_accounts WHERE account_code = '4000'),
       true
WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '4300');

UPDATE fiscal_config
   SET credit_account_id = (SELECT id FROM chart_of_accounts WHERE account_code = '4300')
 WHERE rule_code = 'PROJECT_INVOICE';

UPDATE journal_lines
   SET account_id = (SELECT id FROM chart_of_accounts WHERE account_code = '4300')
 WHERE credit > 0
   AND journal_id IN (SELECT id FROM journal_entries WHERE reference_type::text = 'project_invoice');

COMMIT;

-- ------------------------------------------------------------
-- OPSIONAL (keputusan akuntansi): bahan yang di-issue ke proyek langsung jadi beban (HPP) ketimbang ditahan di WIP.
-- Default aturan ISSUE_PROJECT = Dr 1700 WIP / Cr 1500 Persediaan. Pelepasan WIP -> HPP belum ada otomatisnya,
-- jadi selama WIP tidak dilepas, Laba Rugi tidak menampilkan biaya material. Untuk mengubah:
--   UPDATE fiscal_config
--      SET debit_account_id = (SELECT id FROM chart_of_accounts WHERE account_code = '5100')
--    WHERE rule_code = 'ISSUE_PROJECT';
-- ------------------------------------------------------------
