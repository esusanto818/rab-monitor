-- ============================================================================
-- 2026-10-01.rab_currency.sql  --  Mata uang anggaran (RAB) per proyek
--
-- Anggaran sebuah proyek bisa berbeda dari mata uang dasar, mis. proyek dibiayai pihak luar dalam USD,
-- sedangkan pembelian bisa IDR (dalam negeri) atau USD (impor) -> dua mata uang di PO.
-- Seluruh RAB sebuah proyek memakai SATU mata uang anggaran; harga satuan RAB (budget_items.unit_price_plan)
-- dinyatakan dalam mata uang itu. Kurs anggaran = nilai tukar ke mata uang dasar (untuk ringkasan & laporan).
--
-- Disarankan menjalankan 2026-10-01.settings.sql lebih dulu (mata uang dasar dari Pengaturan Sistem).
-- Aman diulang. Proyek lama otomatis memakai mata uang dasar dengan kurs 1 (perilaku tidak berubah).
-- ============================================================================
BEGIN;

ALTER TABLE public.projects ADD COLUMN IF NOT EXISTS budget_currency_id bigint REFERENCES public.m_currency(id);
ALTER TABLE public.projects ADD COLUMN IF NOT EXISTS budget_exch_rate  numeric(15,4) NOT NULL DEFAULT 1;
ALTER TABLE public.projects ADD COLUMN IF NOT EXISTS budget_rate_date  date;

DO $$
DECLARE base_id bigint;
BEGIN
    IF to_regclass('public.finance_settings') IS NOT NULL THEN
        SELECT base_currency_id INTO base_id FROM public.finance_settings WHERE id = 1;
    END IF;
    IF base_id IS NULL THEN
        SELECT id INTO base_id FROM public.m_currency WHERE UPPER(TRIM(init)) = 'IDR' ORDER BY id LIMIT 1;
    END IF;
    IF base_id IS NULL THEN
        RAISE EXCEPTION 'Mata uang dasar (IDR) tidak ditemukan di m_currency. Jalankan 2026-10-01.settings.sql dulu.';
    END IF;

    UPDATE public.projects SET budget_currency_id = base_id WHERE budget_currency_id IS NULL;
    -- Proyek baru yang dibuat modul lain (tanpa mengisi kolom ini) otomatis memakai mata uang dasar
    EXECUTE format('ALTER TABLE public.projects ALTER COLUMN budget_currency_id SET DEFAULT %s', base_id);
END $$;

ALTER TABLE public.projects DROP CONSTRAINT IF EXISTS projects_budget_exch_rate_chk;
ALTER TABLE public.projects ADD CONSTRAINT projects_budget_exch_rate_chk CHECK (budget_exch_rate > 0);

COMMIT;

-- Verifikasi
SELECT p.id, p.name, c.init AS mata_uang_anggaran, p.budget_exch_rate FROM public.projects p
  LEFT JOIN public.m_currency c ON c.id = p.budget_currency_id ORDER BY p.id;
