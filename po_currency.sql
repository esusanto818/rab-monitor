-- ============================================================================
-- 2026-10-01.po_currency.sql  --  PO multi currency terhadap anggaran (RAB)
--
-- Satu PO = satu mata uang (purchase_orders.currency_id, exch_rate = mata uang dasar per 1 unit mata uang PO).
-- Anggaran proyek punya mata uang sendiri (projects.budget_currency_id, lihat 2026-10-01.rab_currency.sql).
-- Agar PO dapat dibandingkan dengan RAB, nilai tiap baris PO disimpan dalam 3 mata uang, DIBEKUKAN saat PO disimpan:
--     po_items.amount_budget  : nilai dalam mata uang ANGGARAN  (dasar pengecekan RAB / budget)
--     po_items.amount_base    : nilai dalam mata uang DASAR     (dasar pembukuan & laporan)
-- dan header menyimpan snapshot:
--     purchase_orders.budget_currency_id / budget_exch_rate (mata uang dasar per 1 unit mata uang anggaran, saat PO)
--     purchase_orders.total_budget / total_base
-- Konversi ke mata uang anggaran:  nilai PO x (kurs PO / kurs anggaran)   [mata uang sama -> x 1]
--
-- Jalankan SETELAH 2026-10-01.rab_currency.sql. Aman diulang.
-- ============================================================================
BEGIN;

ALTER TABLE public.purchase_orders ADD COLUMN IF NOT EXISTS budget_currency_id bigint REFERENCES public.m_currency(id);
ALTER TABLE public.purchase_orders ADD COLUMN IF NOT EXISTS budget_exch_rate  numeric(15,4) NOT NULL DEFAULT 1;
ALTER TABLE public.purchase_orders ADD COLUMN IF NOT EXISTS total_base        numeric(18,2)  NOT NULL DEFAULT 0;
ALTER TABLE public.purchase_orders ADD COLUMN IF NOT EXISTS total_budget      numeric(18,2)  NOT NULL DEFAULT 0;

ALTER TABLE public.po_items ADD COLUMN IF NOT EXISTS amount_base   numeric(18,2) NOT NULL DEFAULT 0;
ALTER TABLE public.po_items ADD COLUMN IF NOT EXISTS amount_budget numeric(18,2) NOT NULL DEFAULT 0;

ALTER TABLE public.purchase_orders DROP CONSTRAINT IF EXISTS purchase_orders_budget_exch_rate_chk;
ALTER TABLE public.purchase_orders ADD CONSTRAINT purchase_orders_budget_exch_rate_chk CHECK (budget_exch_rate > 0);

-- Backfill PO lama: dianggap semua dalam satu mata uang dengan anggaran (kurs PO untuk nilai dasar)
UPDATE public.po_items i
   SET amount_budget = ROUND(i.qty * i.unit_price, 2),
       amount_base   = ROUND(i.qty * i.unit_price * po.exch_rate, 2)
  FROM public.purchase_orders po
 WHERE po.id = i.po_id AND i.amount_budget = 0 AND i.amount_base = 0;

UPDATE public.purchase_orders po
   SET budget_currency_id = COALESCE(po.budget_currency_id, (SELECT p.budget_currency_id FROM public.projects p WHERE p.id = po.project_id)),
       total_base   = COALESCE((SELECT SUM(i.amount_base)   FROM public.po_items i WHERE i.po_id = po.id), 0),
       total_budget = COALESCE((SELECT SUM(i.amount_budget) FROM public.po_items i WHERE i.po_id = po.id), 0);

COMMIT;

-- Verifikasi
SELECT po.po_number, po.status, c.init AS mata_uang_po, bc.init AS mata_uang_anggaran, po.total_budget, po.total_base
  FROM public.purchase_orders po
  LEFT JOIN public.m_currency c  ON c.id  = po.currency_id
  LEFT JOIN public.m_currency bc ON bc.id = po.budget_currency_id
 ORDER BY po.id;
