-- =====================================================================
-- stock.control.ddl.sql
-- Tabel baru: Stock Transfer & Stock Opname  (PostgreSQL)
-- Jalankan sekali. Aman diulang (IF NOT EXISTS).
-- =====================================================================

-- ---------- STOCK TRANSFER ----------
CREATE TABLE IF NOT EXISTS stock_transfer_hdr (
    id                 serial PRIMARY KEY,
    ref_no             varchar(50)  NOT NULL UNIQUE,
    transfer_date      date         NOT NULL DEFAULT CURRENT_DATE,
    from_warehouse_id  integer      NOT NULL REFERENCES warehouses(id),
    to_warehouse_id    integer      NOT NULL REFERENCES warehouses(id),
    status             varchar(20)  NOT NULL DEFAULT 'posted',   -- posted | reversed
    remark             text,
    created_by         integer,
    created_at         timestamp    NOT NULL DEFAULT now(),
    reversed_at        timestamp,
    reversed_by        integer,
    CONSTRAINT chk_stock_transfer_wh CHECK (from_warehouse_id <> to_warehouse_id)
);

CREATE TABLE IF NOT EXISTS stock_transfer_dtl (
    id                  serial PRIMARY KEY,
    transfer_hdr_id     integer       NOT NULL REFERENCES stock_transfer_hdr(id) ON DELETE CASCADE,
    item_id             integer       NOT NULL REFERENCES materials(id),
    qty                 numeric(14,4) NOT NULL CHECK (qty > 0),
    unit_cost           numeric(15,4) NOT NULL DEFAULT 0,        -- biaya layer FIFO asal
    remark              text
);

-- ---------- STOCK OPNAME ----------
CREATE TABLE IF NOT EXISTS stock_opname_hdr (
    id            serial PRIMARY KEY,
    ref_no        varchar(50) NOT NULL UNIQUE,
    opname_date   date        NOT NULL DEFAULT CURRENT_DATE,
    warehouse_id  integer     NOT NULL REFERENCES warehouses(id),
    status        varchar(20) NOT NULL DEFAULT 'draft',          -- draft | posted | reversed
    remark        text,
    created_by    integer,
    created_at    timestamp   NOT NULL DEFAULT now(),
    posted_at     timestamp,
    posted_by     integer,
    reversed_at   timestamp,
    reversed_by   integer
);

CREATE TABLE IF NOT EXISTS stock_opname_dtl (
    id              serial PRIMARY KEY,
    opname_hdr_id   integer       NOT NULL REFERENCES stock_opname_hdr(id) ON DELETE CASCADE,
    item_id         integer       NOT NULL REFERENCES materials(id),
    system_qty      numeric(14,4) NOT NULL DEFAULT 0,            -- stok sistem (di-refresh saat posting)
    counted_qty     numeric(14,4) NOT NULL DEFAULT 0 CHECK (counted_qty >= 0),
    diff_qty        numeric(14,4) NOT NULL DEFAULT 0,            -- counted - system
    unit_cost       numeric(15,4) NOT NULL DEFAULT 0,
    remark          text,
    CONSTRAINT uq_stock_opname_item UNIQUE (opname_hdr_id, item_id)
);

-- ---------- INDEX untuk perhitungan stok / FIFO / stock card ----------
CREATE INDEX IF NOT EXISTS idx_stockmov_item_wh_date
    ON stock_movements (item_id, warehouse_id, movement_date, id);
CREATE INDEX IF NOT EXISTS idx_stockmov_ref
    ON stock_movements (reference_type, reference_id);
CREATE INDEX IF NOT EXISTS idx_mat_out_items_hdr
    ON material_out_items (material_out_id);
CREATE INDEX IF NOT EXISTS idx_mat_out_hdr_project
    ON material_out_header (project_id, status);

-- ---------- REGISTRASI MENU (sesuaikan group_id & seq dengan data Anda) ----------
-- INSERT INTO m_form_name (group_id, seq, title, php_url, js_url, main_table, active) VALUES
--   (<GROUP_WMS>, '10', 'Warehouse Issuing', 'whissuingCRD.php',   'whissuing.js',   'material_out_header', true),
--   (<GROUP_WMS>, '20', 'Stock Transfer',    'stocktransferCRD.php','stocktransfer.js','stock_transfer_hdr',  true),
--   (<GROUP_WMS>, '30', 'Stock Card',        'stockcardCRD.php',   'stockcard.js',   'stock_movements',     true),
--   (<GROUP_WMS>, '40', 'Stock Opname',      'stockopnameCRD.php', 'stockopname.js', 'stock_opname_hdr',    true);
