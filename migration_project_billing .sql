-- ============================================================================
-- Migration: Project Billing (Termin) & Client Payment
-- ----------------------------------------------------------------------------
-- Menambahkan modul penagihan jasa konstruksi ke klien per proyek:
--   projects.contract_value  -> nilai kontrak (terpisah dari budget biaya)
--   project_invoices         -> termin (satu baris = satu termin/DP)
--   project_payments         -> header pembayaran dari klien
--   project_payment_items    -> alokasi pembayaran ke termin
--
-- WAJIB DIISI sebelum menjalankan: bagian "1. Ganti kode akun" di bawah.
-- Jalankan sebagai satu file lewat psql (bukan disalin manual satu-satu),
-- supaya ALTER TYPE ... ADD VALUE ter-commit sebelum dipakai INSERT di bawahnya.
--   psql -h <host> -U <user> -d <db> -v ON_ERROR_STOP=1 -f migration_project_billing.sql
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 0. Nilai kontrak proyek (terpisah dari projects.budget yang berisi biaya)
-- ----------------------------------------------------------------------------
ALTER TABLE public.projects
    ADD COLUMN IF NOT EXISTS contract_value numeric(18,2) NOT NULL DEFAULT 0;


-- ----------------------------------------------------------------------------
-- 1. Termin / tagihan proyek
--    Satu baris = satu termin (atau DP). Tanpa tabel detail karena tidak ada
--    rincian item -- nominalnya langsung persentase atau nilai bebas dari
--    contract_value.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.project_invoices (
    id            serial PRIMARY KEY,
    inv_no        character varying(30)  NOT NULL,
    project_id    integer                NOT NULL REFERENCES public.projects(id),
    termin_name   character varying(100) NOT NULL,
    invoice_date  date                   NOT NULL,
    percentage    numeric(5,2),                                  -- opsional; NULL kalau nominal bebas
    amount        numeric(18,2)          NOT NULL,
    paid_amount   numeric(18,2)          NOT NULL DEFAULT 0,
    remark        text                   NOT NULL DEFAULT '',
    created_at    timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT project_invoices_inv_no_key UNIQUE (inv_no),
    CONSTRAINT project_invoices_amount_check CHECK (amount > 0),
    CONSTRAINT project_invoices_paid_amount_check CHECK (paid_amount >= 0)
);

CREATE INDEX IF NOT EXISTS idx_project_invoices_project_id ON public.project_invoices(project_id);


-- ----------------------------------------------------------------------------
-- 2. Pembayaran klien (header) -- pola sama dengan supplier_payments:
--    kas/bank masuk, dialokasikan ke termin, sisa lebih jadi deposit (DP klien).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.project_payments (
    id                serial PRIMARY KEY,
    payment_no        character varying(30)  NOT NULL,
    client_id         integer                NOT NULL REFERENCES public.clients(id),
    account_id        integer                REFERENCES public.chart_of_accounts(id),  -- kas/bank penerima
    payment_date      date                   NOT NULL,
    payment_method    character varying(20)  NOT NULL DEFAULT 'BANK_TRANSFER',
    total_amount      numeric(18,2)          NOT NULL DEFAULT 0,   -- kas/bank masuk
    allocated_amount  numeric(18,2)          NOT NULL DEFAULT 0,   -- dialokasikan ke termin
    excess_amount     numeric(18,2)          NOT NULL DEFAULT 0,   -- lebih bayar -> deposit klien
    deposit_applied   numeric(18,2)          NOT NULL DEFAULT 0,   -- pakai saldo deposit lama
    reference_no      character varying(50)  NOT NULL DEFAULT '',
    notes             text                   NOT NULL DEFAULT '',
    created_by        integer,
    created_at        timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT project_payments_payment_no_key UNIQUE (payment_no),
    CONSTRAINT project_payments_amounts_check CHECK (
        total_amount >= 0 AND allocated_amount >= 0 AND excess_amount >= 0 AND deposit_applied >= 0
    )
);

CREATE INDEX IF NOT EXISTS idx_project_payments_client_id ON public.project_payments(client_id);

CREATE TABLE IF NOT EXISTS public.project_payment_items (
    id          serial PRIMARY KEY,
    payment_id  integer NOT NULL REFERENCES public.project_payments(id),
    invoice_id  integer NOT NULL REFERENCES public.project_invoices(id),
    amount_paid numeric(18,2) NOT NULL CHECK (amount_paid > 0)
);

CREATE INDEX IF NOT EXISTS idx_project_payment_items_payment_id ON public.project_payment_items(payment_id);
CREATE INDEX IF NOT EXISTS idx_project_payment_items_invoice_id ON public.project_payment_items(invoice_id);


-- ----------------------------------------------------------------------------
-- 3. reference_type: nilai baru untuk jurnal termin & pembayaran klien
--    (harus statement sendiri, tidak boleh di dalam blok transaksi eksplisit
--    yang juga memakai nilai ini -- lihat catatan menjalankan file di atas)
-- ----------------------------------------------------------------------------
ALTER TYPE public.reference_type ADD VALUE IF NOT EXISTS 'project_invoice';
ALTER TYPE public.reference_type ADD VALUE IF NOT EXISTS 'project_payment';


-- ----------------------------------------------------------------------------
-- 4. GANTI KODE AKUN DI SINI sebelum menjalankan file ini -- edit langsung
--    tiga literal '1201' / '4101' / '2201' di bawah (bukan lewat psql \set,
--    supaya file ini tetap jalan walau dieksekusi dari tool selain psql).
--    Isi dengan account_code yang sudah ada di chart_of_accounts Anda.
-- ----------------------------------------------------------------------------
DO $$
DECLARE
    acc_piutang_usaha   constant varchar := '1200';  -- Piutang Usaha
    acc_pendapatan_jasa constant varchar := '4200';  -- Pendapatan Jasa Custom/Repair (paling dekat; lihat catatan chat kalau mau akun terpisah)
    acc_uang_muka_klien constant varchar := '2200';  -- Uang Muka Penjualan / Kelebihan pembayaran
    v_piutang    bigint;
    v_pendapatan bigint;
    v_um_klien   bigint;
BEGIN
    SELECT id INTO v_piutang    FROM public.chart_of_accounts WHERE account_code = acc_piutang_usaha;
    SELECT id INTO v_pendapatan FROM public.chart_of_accounts WHERE account_code = acc_pendapatan_jasa;
    SELECT id INTO v_um_klien   FROM public.chart_of_accounts WHERE account_code = acc_uang_muka_klien;

    IF v_piutang IS NULL THEN
        RAISE EXCEPTION 'Akun Piutang Usaha (code %) tidak ditemukan di chart_of_accounts. Ganti literal acc_piutang_usaha di bagian 4.', acc_piutang_usaha;
    END IF;
    IF v_pendapatan IS NULL THEN
        RAISE EXCEPTION 'Akun Pendapatan Jasa (code %) tidak ditemukan di chart_of_accounts. Ganti literal acc_pendapatan_jasa di bagian 4.', acc_pendapatan_jasa;
    END IF;
    IF v_um_klien IS NULL THEN
        RAISE EXCEPTION 'Akun Uang Muka Klien (code %) tidak ditemukan di chart_of_accounts. Ganti literal acc_uang_muka_klien di bagian 4.', acc_uang_muka_klien;
    END IF;

    -- Termin ditagih: Piutang Usaha (D) / Pendapatan Jasa (K)
    INSERT INTO public.fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
    VALUES ('PROJECT_INVOICE', 'Tagihan Termin Proyek', v_piutang, v_pendapatan, true)
    ON CONFLICT (rule_code) DO UPDATE SET
        debit_account_id = EXCLUDED.debit_account_id,
        credit_account_id = EXCLUDED.credit_account_id,
        is_active = true;

    -- Klien bayar termin: Kas/Bank (D, akun dari form) / Piutang Usaha (K)
    -- credit_account_id di sini hanya default fallback; PostSupplierPaymentJournal-
    -- style helper akan meng-override debit_account_id dari account_id form.
    INSERT INTO public.fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
    VALUES ('PROJECT_PAYMENT', 'Pembayaran Termin oleh Klien', v_piutang, v_piutang, true)
    ON CONFLICT (rule_code) DO UPDATE SET is_active = true;

    -- Lebih bayar / DP tanpa termin: Kas/Bank (D, dari form) / Uang Muka Klien (K)
    INSERT INTO public.fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
    VALUES ('PROJECT_ADV', 'Uang Muka / Deposit Klien', v_um_klien, v_um_klien, true)
    ON CONFLICT (rule_code) DO UPDATE SET is_active = true;
END $$;

-- ON CONFLICT (rule_code) butuh unique constraint; fiscal_config belum punya satu.
-- Tambahkan sekali (aman dijalankan berulang lewat IF NOT EXISTS di bawah):
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fiscal_config_rule_code_key'
    ) THEN
        ALTER TABLE public.fiscal_config ADD CONSTRAINT fiscal_config_rule_code_key UNIQUE (rule_code);
    END IF;
END $$;


-- ----------------------------------------------------------------------------
-- 5. purchase_payment_entries & payment_entries: tidak dipakai kode manapun
--    yang sudah diaudit, dan payment_entries.customer_id tidak ber-FK ke
--    clients maupun m_customer. Di-drop supaya tidak membingungkan modul
--    berikutnya. HAPUS DUA BARIS DI BAWAH kalau ternyata masih dipakai.
-- ----------------------------------------------------------------------------
-- DROP TABLE IF EXISTS public.purchase_payment_entries;
-- DROP TABLE IF EXISTS public.payment_entries;
-- (sengaja dikomentari -- aktifkan manual setelah Anda pastikan tidak dipakai)
