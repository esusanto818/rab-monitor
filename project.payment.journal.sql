-- ============================================================
-- Migrasi: jurnal otomatis Pembayaran Termin (project_paymentCRD.php)
-- Jalankan BERURUTAN. Langkah 1 dijalankan terpisah (di luar transaksi) bila PostgreSQL < 12.
-- ============================================================

-- 1) Cek nama tipe enum journal_entries.reference_type, lalu tambah nilai 'project_payment'
SELECT n.nspname, t.typname
  FROM pg_attribute a
  JOIN pg_type t ON t.oid = a.atttypid
  JOIN pg_namespace n ON n.oid = t.typnamespace
 WHERE a.attrelid = 'public.journal_entries'::regclass AND a.attname = 'reference_type';
-- GANTI <typname> dengan hasil query di atas, lalu jalankan:
-- ALTER TYPE public.<typname> ADD VALUE IF NOT EXISTS 'project_payment';

-- 2) Lihat akun yang tersedia untuk dipilih sebagai akun lawan
SELECT id, account_code, account_name, account_type FROM public.chart_of_accounts WHERE is_active ORDER BY account_code;

-- 3) Rule posting. GANTI kode akun sesuai COA Anda:
--    <KODE_PIUTANG> = Piutang Usaha (kredit untuk bagian yang dialokasikan ke termin)
--    <KODE_DEPOSIT> = Deposit / Uang Muka Client (kredit untuk lebih bayar)
--    Akun debit pada rule hanya fallback; saat posting diganti akun Kas/Bank pilihan user.
INSERT INTO public.fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT 'PRJ_PAY_AR', 'Pembayaran Termin Proyek - Pelunasan Piutang',
       (SELECT id FROM public.chart_of_accounts WHERE account_code = '1111'),
       (SELECT id FROM public.chart_of_accounts WHERE account_code = '<KODE_PIUTANG>'), true
WHERE NOT EXISTS (SELECT 1 FROM public.fiscal_config WHERE rule_code = 'PRJ_PAY_AR');



-- 4) Verifikasi
SELECT f.rule_code, d.account_code AS debit, c.account_code AS kredit, f.is_active
  FROM public.fiscal_config f
  JOIN public.chart_of_accounts d ON d.id = f.debit_account_id
  JOIN public.chart_of_accounts c ON c.id = f.credit_account_id
 WHERE f.rule_code IN ('PRJ_PAY_AR', 'PRJ_PAY_DEP');
