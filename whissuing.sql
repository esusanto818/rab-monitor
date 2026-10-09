-- Warehouse Issuing (Material Out) - FIFO berbasis WH Receiving
-- Jalankan SEKALI, sebelum memakai warehouse.issuingCRD.php
BEGIN;

-- 1. Nilai rupiah per baris issue (= jumlah alokasi FIFO). unit_cost numeric(15,2) terlalu kasar
--    untuk menyimpan biaya rata-rata dari beberapa layer, jadi total disimpan terpisah.
ALTER TABLE material_out_items
    ADD COLUMN IF NOT EXISTS amount numeric(18,2) NOT NULL DEFAULT 0;

-- Backfill data lama yang unit_cost-nya sudah terisi
UPDATE material_out_items SET amount = ROUND(qty_out * unit_cost, 2)
WHERE amount = 0 AND unit_cost > 0;

-- 2. Jejak FIFO: tiap baris issue diambil dari layer RCV (wh_receiving_dtl) yang mana, berapa qty, biaya berapa
CREATE TABLE IF NOT EXISTS material_issue_allocations (
    id            serial PRIMARY KEY,
    out_item_id   integer       NOT NULL REFERENCES material_out_items(id) ON DELETE CASCADE,
    layer_source  varchar(10)   NOT NULL DEFAULT 'WHRCV',
    layer_id      integer       NOT NULL,              -- wh_receiving_dtl.id
    qty           numeric(14,4) NOT NULL CHECK (qty > 0),
    unit_cost     numeric(15,4) NOT NULL,
    amount        numeric(18,2) NOT NULL,
    created_at    timestamp     NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_mia_layer ON material_issue_allocations (layer_source, layer_id);
CREATE INDEX IF NOT EXISTS ix_mia_item  ON material_issue_allocations (out_item_id);

CREATE INDEX IF NOT EXISTS ix_moh_project ON material_out_header (project_id, status);
CREATE INDEX IF NOT EXISTS ix_moi_header  ON material_out_items (material_out_id);

-- 3. View layer FIFO (untuk laporan / cek manual). Sisa layer =
--    qty RCV - qty retur pembelian (posted) - qty issue (posted, belum reversed)
CREATE OR REPLACE VIEW v_stock_fifo_layers AS
SELECT h.warehouse_id,
       d.item_id,
       d.id                AS layer_id,
       h.ref_no            AS rcv_ref,
       h.received_date,
       d.lot_no,
       d.unit_cost,
       d.qty_received,
       COALESCE(ret.q, 0)  AS qty_returned,
       COALESCE(al.q, 0)   AS qty_issued,
       d.qty_received - COALESCE(ret.q, 0) - COALESCE(al.q, 0) AS remaining
FROM wh_receiving_dtl d
JOIN wh_receiving_hdr h ON h.id = d.wh_receiving_hdr_id
LEFT JOIN (
    SELECT rd.wh_receiving_dtl_id AS id, SUM(rd.qty_returned) AS q
    FROM purchase_return_dtl rd
    JOIN purchase_return_hdr rh ON rh.id = rd.return_hdr_id
    WHERE rh.status = 'posted'
    GROUP BY rd.wh_receiving_dtl_id
) ret ON ret.id = d.id
LEFT JOIN (
    SELECT a.layer_id AS id, SUM(a.qty) AS q
    FROM material_issue_allocations a
    JOIN material_out_items  i  ON i.id  = a.out_item_id
    JOIN material_out_header oh ON oh.id = i.material_out_id
    WHERE a.layer_source = 'WHRCV' AND oh.status = 'posted' AND oh.reversed_at IS NULL
    GROUP BY a.layer_id
) al ON al.id = d.id
WHERE h.status = 'posted' AND h.reversed_at IS NULL;

COMMIT;

-- 4. Registrasi menu (sesuaikan group_id / seq dengan m_form_group Anda), lalu beri akses user:
-- INSERT INTO m_form_name (group_id, seq, title, php_url, main_table, active)
-- VALUES (<group_id_inventory>, '<seq>', 'Warehouse Issuing', 'views/warehouse.issuing.php', 'material_out_header', true);
-- INSERT INTO m_user_access_menu (user_id, form_id, access_level)
-- SELECT <user_id>, id, 1 FROM m_form_name WHERE title = 'Warehouse Issuing';
