-- ============================================================================
-- 2026-10-01.grn_currency.sql  --  GRN & WH Receiving multi currency
--
-- Aturan nilai:
--   grn_dtl.unit_cost            : harga per unit dalam MATA UANG PO (seperti sebelumnya)
--   grn_dtl.unit_cost_base       : harga per unit dalam MATA UANG DASAR = unit_cost x kurs PO (dihitung server)
--   grn_hdr.currency_id/exch_rate: snapshot mata uang & kurs PO saat GRN dibuat
--   wh_receiving_dtl.unit_cost   : SEKARANG = MATA UANG DASAR  -> dasar layer FIFO, stock_movements, jurnal GRN_RCV
--   wh_receiving_dtl.unit_cost_txn / currency_id / exch_rate : harga & kurs asal (jejak audit)
--
-- Jalankan SETELAH 2026-10-01.po_currency.sql. Aman diulang.
-- ============================================================================
BEGIN;

ALTER TABLE public.grn_hdr ADD COLUMN IF NOT EXISTS currency_id bigint REFERENCES public.m_currency(id);
ALTER TABLE public.grn_hdr ADD COLUMN IF NOT EXISTS exch_rate   numeric(15,4) NOT NULL DEFAULT 1;
ALTER TABLE public.grn_hdr DROP CONSTRAINT IF EXISTS grn_hdr_exch_rate_chk;
ALTER TABLE public.grn_hdr ADD CONSTRAINT grn_hdr_exch_rate_chk CHECK (exch_rate > 0);

ALTER TABLE public.grn_dtl ADD COLUMN IF NOT EXISTS unit_cost_base numeric(15,4) NOT NULL DEFAULT 0;

ALTER TABLE public.wh_receiving_dtl ADD COLUMN IF NOT EXISTS unit_cost_txn numeric(15,4);
ALTER TABLE public.wh_receiving_dtl ADD COLUMN IF NOT EXISTS currency_id    bigint REFERENCES public.m_currency(id);
ALTER TABLE public.wh_receiving_dtl ADD COLUMN IF NOT EXISTS exch_rate      numeric(15,4);

-- Backfill data lama: ambil mata uang & kurs dari PO
UPDATE public.grn_hdr g
   SET currency_id = po.currency_id, exch_rate = COALESCE(NULLIF(po.exch_rate, 0), 1)
  FROM public.purchase_orders po
 WHERE po.id = g.po_id AND g.currency_id IS NULL;

UPDATE public.grn_dtl d
   SET unit_cost_base = ROUND(d.unit_cost * COALESCE(g.exch_rate, 1), 4)
  FROM public.grn_hdr g
 WHERE g.id = d.grn_hdr_id AND d.unit_cost_base = 0;

-- WH Rcv lama: unit_cost sebelumnya tidak dikonversi -> dianggap sudah mata uang dasar
UPDATE public.wh_receiving_dtl SET unit_cost_txn = unit_cost WHERE unit_cost_txn IS NULL;

COMMIT;

-- Verifikasi
SELECT g.ref_no, c.init AS mata_uang, g.exch_rate FROM public.grn_hdr g
  LEFT JOIN public.m_currency c ON c.id = g.currency_id ORDER BY g.id;
