-- =============================================================
-- Migration: 2026-10-02.po_item_currency.sql
-- Tujuan  : Tambah mata uang + kurs per baris po_items.
--           Satu PO dapat memiliki item bervaluta IDR (beli lokal)
--           maupun USD (beli impor) sekaligus.
-- =============================================================

-- 1. Tambah kolom ke po_items (nullable agar backward-compat)
ALTER TABLE public.po_items
    ADD COLUMN IF NOT EXISTS currency_id  BIGINT  NULL REFERENCES public.m_currency(id),
    ADD COLUMN IF NOT EXISTS exch_rate    NUMERIC(15,4) NOT NULL DEFAULT 1.0000;

-- 2. Isi currency_id item lama dari header PO (agar data lama tetap konsisten)
UPDATE public.po_items pi
   SET currency_id = po.currency_id,
       exch_rate   = po.exch_rate
  FROM public.purchase_orders po
 WHERE po.id = pi.po_id
   AND pi.currency_id IS NULL;

-- Selesai (amount_base dihitung di aplikasi saat INSERT/UPDATE, tidak perlu kolom terpisah).
-- Jalankan via: psql -d <dbname> -f 2026-10-02.po_item_currency.sql
