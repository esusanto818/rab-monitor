-- =====================================================================
-- supplier.return.ddl.sql
-- Retur Supplier (material cacat / rusak) - PostgreSQL. Aman diulang.
-- =====================================================================

-- 1) Tandai jenis dokumen material_out_header:
--    PROJECT         = issue ke proyek (menghitung kuota RAB +5%)
--    SUPPLIER_RETURN = issue W/H untuk retur ke supplier (TIDAK menghitung kuota RAB)
ALTER TABLE material_out_header
    ADD COLUMN IF NOT EXISTS issue_type varchar(20) NOT NULL DEFAULT 'PROJECT';

CREATE INDEX IF NOT EXISTS idx_mat_out_hdr_type ON material_out_header (issue_type, status);

-- 2) Header klaim / retur
CREATE TABLE IF NOT EXISTS supplier_return_hdr (
    id               serial PRIMARY KEY,
    ref_no           varchar(50)  NOT NULL UNIQUE,
    return_date      date         NOT NULL DEFAULT CURRENT_DATE,
    project_id       integer      NOT NULL REFERENCES projects(id),
    warehouse_id     integer      NOT NULL REFERENCES warehouses(id),
    supplier_id      integer      NOT NULL REFERENCES m_suppliers(id),
    po_id            integer      REFERENCES purchase_orders(id),     -- referensi (opsional)
    supplier_do_no   varchar(100),                                    -- no. surat jalan penerimaan asal
    status           varchar(20)  NOT NULL DEFAULT 'Diajukan',        -- Diajukan | Dikirim | Diganti | Kredit | Dibatalkan
    remark           text,
    -- pengiriman (issue W/H)
    material_out_id  integer      REFERENCES material_out_header(id),
    sent_date        date,
    carrier          varchar(100),                                    -- kurir / penerima di supplier
    -- penyelesaian
    resolution_type  varchar(20),                                     -- Ganti Barang | Kredit
    resolution_date  date,
    credit_note_no   varchar(100),
    credit_amount    numeric(18,2),
    resolution_note  text,
    created_by       integer,
    created_at       timestamp    NOT NULL DEFAULT now(),
    updated_at       timestamp    NOT NULL DEFAULT now()
);

-- 3) Detail klaim
CREATE TABLE IF NOT EXISTS supplier_return_dtl (
    id             serial PRIMARY KEY,
    return_hdr_id  integer       NOT NULL REFERENCES supplier_return_hdr(id) ON DELETE CASCADE,
    material_id    integer       NOT NULL REFERENCES materials(id),
    qty_claim      numeric(14,4) NOT NULL CHECK (qty_claim > 0),
    unit_cost      numeric(15,4) NOT NULL DEFAULT 0,                  -- rata-rata FIFO, terisi saat dikirim
    defect_type    varchar(40)   NOT NULL,
    defect_note    text,
    evidence_ref   varchar(255)                                       -- no. foto / link folder bukti
);

CREATE INDEX IF NOT EXISTS idx_sup_ret_dtl_hdr ON supplier_return_dtl (return_hdr_id);
CREATE INDEX IF NOT EXISTS idx_sup_ret_hdr_wh  ON supplier_return_hdr (warehouse_id, status);

-- 4) Registrasi menu (sesuaikan group_id & seq)
-- INSERT INTO m_form_name (group_id, seq, title, php_url, js_url, main_table, active)
-- VALUES (<GROUP_WMS>, '50', 'Retur Supplier (Cacat)', 'supplierreturnCRD.php', 'supplierreturn.js', 'supplier_return_hdr', true);
