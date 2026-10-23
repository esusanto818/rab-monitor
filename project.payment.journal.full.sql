-- ============================================================
-- PERBAIKAN LENGKAP: jurnal otomatis Pembayaran Termin  (database proyekGMN)
-- Menggantikan 2026-10-16.project_payment_journal.sql dan 2026-10-16.reset_trial_payment.sql
-- Jalankan:
--   psql -h localhost -p 5432 -U postgres -d proyekGMN -v ON_ERROR_STOP=1 -f 2026-10-16.project_payment_journal_FULL.sql
-- Aman dijalankan ulang (idempotent).
-- ============================================================

-- ------------------------------------------------------------
-- 0) KONFIGURASI AKUN  (kosongkan '' = dicari otomatis berdasarkan nama akun)
--    bank_code : akun debit cadangan di rule (saat posting diganti akun Kas/Bank pilihan user)
--    ar_code   : Piutang Usaha (kredit untuk bagian yang dialokasikan ke termin)
--    dep_code  : Deposit / Uang Muka Client - kewajiban (kredit untuk lebih bayar)
-- ------------------------------------------------------------
CREATE TEMP TABLE _cfg AS
SELECT '1111'::text AS bank_code,
       ''::text     AS ar_code,      -- contoh isi manual: '1210'
       ''::text     AS dep_code;     -- contoh isi manual: '2150'

-- ------------------------------------------------------------
-- 1) Nilai enum reference_type (di luar transaksi agar kompatibel semua versi PostgreSQL)
-- ------------------------------------------------------------
ALTER TYPE public.reference_type ADD VALUE IF NOT EXISTS 'project_payment';

-- ------------------------------------------------------------
-- 2) Rule posting PRJ_PAY_AR & PRJ_PAY_DEP di fiscal_config (insert / update bila sudah ada)
-- ------------------------------------------------------------
DO $$
DECLARE
    c       record;
    v_bank  bigint;
    v_ar    bigint;
    v_dep   bigint;
BEGIN
    SELECT * INTO c FROM _cfg;

    SELECT id INTO v_bank FROM public.chart_of_accounts WHERE account_code = c.bank_code;
    IF v_bank IS NULL THEN
        RAISE EXCEPTION 'Akun bank dengan kode % tidak ditemukan. Ubah bank_code di bagian 0.', c.bank_code;
    END IF;

    -- Piutang Usaha
    IF c.ar_code <> '' THEN
        SELECT id INTO v_ar FROM public.chart_of_accounts WHERE account_code = c.ar_code;
    ELSE
        SELECT id INTO v_ar FROM public.chart_of_accounts
         WHERE is_active AND account_name ILIKE '%piutang%'
         ORDER BY CASE WHEN account_name ILIKE '%piutang usaha%' THEN 0 ELSE 1 END, account_code
         LIMIT 1;
    END IF;
    IF v_ar IS NULL THEN
        RAISE EXCEPTION 'Akun Piutang Usaha tidak ditemukan. Isi ar_code di bagian 0 (cek: SELECT account_code, account_name FROM chart_of_accounts ORDER BY 1).';
    END IF;

    -- Deposit / Uang Muka Client (utamakan akun bertipe kewajiban agar tidak tertukar dengan uang muka pembelian)
    IF c.dep_code <> '' THEN
        SELECT id INTO v_dep FROM public.chart_of_accounts WHERE account_code = c.dep_code;
    ELSE
        SELECT id INTO v_dep FROM public.chart_of_accounts
         WHERE is_active AND (account_name ILIKE '%uang muka%' OR account_name ILIKE '%deposit%' OR account_name ILIKE '%titipan%')
         ORDER BY (account_type::text ILIKE ANY (ARRAY['%liab%', '%kewajiban%', '%hutang%'])) DESC,
                  CASE WHEN account_name ILIKE '%uang muka%penjualan%' OR account_name ILIKE '%uang muka%pelanggan%'
                         OR account_name ILIKE '%uang muka%client%' OR account_name ILIKE '%deposit%' THEN 0 ELSE 1 END,
                  account_code
         LIMIT 1;
    END IF;
    IF v_dep IS NULL THEN
        RAISE EXCEPTION 'Akun Deposit/Uang Muka Client tidak ditemukan. Isi dep_code di bagian 0, atau buat akunnya dulu di Chart of Accounts.';
    END IF;

    RAISE NOTICE 'PRJ_PAY_AR  : debit % -> kredit % (%)', c.bank_code,
        (SELECT account_code FROM public.chart_of_accounts WHERE id = v_ar),
        (SELECT account_name FROM public.chart_of_accounts WHERE id = v_ar);
    RAISE NOTICE 'PRJ_PAY_DEP : debit % -> kredit % (%)', c.bank_code,
        (SELECT account_code FROM public.chart_of_accounts WHERE id = v_dep),
        (SELECT account_name FROM public.chart_of_accounts WHERE id = v_dep);

    IF EXISTS (SELECT 1 FROM public.fiscal_config WHERE rule_code = 'PRJ_PAY_AR') THEN
        UPDATE public.fiscal_config SET debit_account_id = v_bank, credit_account_id = v_ar, is_active = true
         WHERE rule_code = 'PRJ_PAY_AR';
    ELSE
        INSERT INTO public.fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
        VALUES ('PRJ_PAY_AR', 'Pembayaran Termin Proyek - Pelunasan Piutang', v_bank, v_ar, true);
    END IF;

    IF EXISTS (SELECT 1 FROM public.fiscal_config WHERE rule_code = 'PRJ_PAY_DEP') THEN
        UPDATE public.fiscal_config SET debit_account_id = v_bank, credit_account_id = v_dep, is_active = true
         WHERE rule_code = 'PRJ_PAY_DEP';
    ELSE
        INSERT INTO public.fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
        VALUES ('PRJ_PAY_DEP', 'Pembayaran Termin Proyek - Lebih Bayar (Deposit Client)', v_bank, v_dep, true);
    END IF;
END $$;

-- ------------------------------------------------------------
-- 3) Hapus data trial PPAY-2610-00001 (tidak punya jurnal; aman bila sudah terhapus)
-- ------------------------------------------------------------
BEGIN;

UPDATE public.project_invoices v
   SET paid_amount = GREATEST(v.paid_amount - i.amount_paid, 0)
  FROM public.project_payment_items i
  JOIN public.project_payments p ON p.id = i.payment_id
 WHERE i.invoice_id = v.id AND p.payment_no = 'PPAY-2610-00001';

DELETE FROM public.general_ledger
 WHERE journal_line_id IN (
       SELECT l.id FROM public.journal_lines l
         JOIN public.journal_entries j ON j.id = l.journal_id
        WHERE j.reference_type::text = 'project_payment'
          AND j.reference_id IN (SELECT id FROM public.project_payments WHERE payment_no = 'PPAY-2610-00001'));
DELETE FROM public.journal_lines
 WHERE journal_id IN (
       SELECT j.id FROM public.journal_entries j
        WHERE j.reference_type::text = 'project_payment'
          AND j.reference_id IN (SELECT id FROM public.project_payments WHERE payment_no = 'PPAY-2610-00001'));
DELETE FROM public.journal_entries
 WHERE reference_type::text = 'project_payment'
   AND reference_id IN (SELECT id FROM public.project_payments WHERE payment_no = 'PPAY-2610-00001');

DELETE FROM public.project_payment_items
 WHERE payment_id IN (SELECT id FROM public.project_payments WHERE payment_no = 'PPAY-2610-00001');
DELETE FROM public.project_payments WHERE payment_no = 'PPAY-2610-00001';

COMMIT;

-- ------------------------------------------------------------
-- 4) VERIFIKASI
-- ------------------------------------------------------------
-- a) rule harus 2 baris
SELECT f.rule_code, d.account_code AS debit, c.account_code AS kredit, c.account_name AS nama_kredit, f.is_active
  FROM public.fiscal_config f
  JOIN public.chart_of_accounts d ON d.id = f.debit_account_id
  JOIN public.chart_of_accounts c ON c.id = f.credit_account_id
 WHERE f.rule_code IN ('PRJ_PAY_AR', 'PRJ_PAY_DEP');
-- b) nilai enum harus ada 'project_payment'
SELECT enumlabel FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid
 WHERE t.typname = 'reference_type' AND enumlabel = 'project_payment';
-- c) termin harus kembali paid_amount 0 (bila belum ada pembayaran lain)
SELECT inv_no, amount, paid_amount FROM public.project_invoices ORDER BY id;
-- d) periode akuntansi untuk tanggal transaksi harus ada & belum closed
SELECT period_name, start_date, end_date, is_closed FROM public.accounting_periods ORDER BY start_date DESC LIMIT 3;
