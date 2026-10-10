-- ============================================================
-- Migration : 2026-10-09b.return_from_rcv.sql
-- Purpose   : Retur Pembelian dibuat dari dokumen W/H RCV (supplier, gudang, proyek, PO diturunkan dari RCV)
-- Jalankan SETELAH 2026-10-09.issue_return_fix.sql
-- ============================================================
BEGIN;

ALTER TABLE public.supplier_return_hdr
    ADD COLUMN IF NOT EXISTS wh_receiving_hdr_id integer REFERENCES public.wh_receiving_hdr(id);

CREATE INDEX IF NOT EXISTS idx_srh_rcv ON public.supplier_return_hdr (wh_receiving_hdr_id);

COMMIT;

-- Cek (opsional): gudang yang terikat proyek. Bila project_id NULL, itu sebabnya dropdown Gudang kosong.
-- SELECT id, code, name, project_id FROM public.warehouses ORDER BY id;
