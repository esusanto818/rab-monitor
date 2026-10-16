-- No. DO Supplier dicatat di GRN, diturunkan ke W/H RCV dan Retur Pembelian (trace GRN -> W/H RCV -> Retur)
ALTER TABLE grn_hdr ADD COLUMN IF NOT EXISTS supplier_do_no varchar(100);
CREATE INDEX IF NOT EXISTS idx_grn_hdr_supplier_do_no ON grn_hdr (supplier_do_no);

-- Backfill (opsional): GRN lama yang sudah punya retur dengan DO yang diisi manual -> salin ke GRN.
-- Jalur: supplier_return_hdr.wh_receiving_hdr_id -> wh_receiving_hdr.grn_hdr_id
UPDATE grn_hdr g
   SET supplier_do_no = x.do_no
  FROM (SELECT DISTINCT ON (r.grn_hdr_id) r.grn_hdr_id, h.supplier_do_no AS do_no
          FROM supplier_return_hdr h
          JOIN wh_receiving_hdr r ON r.id = h.wh_receiving_hdr_id
         WHERE COALESCE(h.supplier_do_no, '') <> ''
         ORDER BY r.grn_hdr_id, h.id) x
 WHERE g.id = x.grn_hdr_id AND COALESCE(g.supplier_do_no, '') = '';
