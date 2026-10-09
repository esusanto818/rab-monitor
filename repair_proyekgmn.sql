BEGIN;

-- ============================================================================
-- 1. UNIFIKASI MASTER DATA & PEMBERSIHAN TABEL DUPLIKAT
-- ============================================================================

-- A. Unifikasi Suppliers: Pindahkan data dari `suppliers` ke `m_suppliers` jika ada, lalu migrasi FK
ALTER TABLE purchase_orders 
    ALTER COLUMN supplier_id TYPE bigint;

-- Update FK purchase_orders ke m_suppliers
ALTER TABLE purchase_orders 
    DROP CONSTRAINT IF EXISTS purchase_orders_supplier_id_fkey,
    ADD CONSTRAINT fk_po_m_suppliers FOREIGN KEY (supplier_id) REFERENCES m_suppliers(id);

-- Hapus tabel duplikat suppliers
DROP TABLE IF EXISTS suppliers CASCADE;


-- B. Unifikasi Projects & Warehouse
DROP TABLE IF EXISTS m_projects CASCADE;
DROP TABLE IF EXISTS m_warehouse CASCADE;


-- C. Unifikasi Chart of Accounts (COA)
-- Pindahkan penandaan project_id ke journal_lines agar journal_entry_lines bisa dihapus
ALTER TABLE journal_lines 
    ADD COLUMN IF NOT EXISTS project_id bigint;

ALTER TABLE journal_lines 
    ADD CONSTRAINT fk_journal_lines_project FOREIGN KEY (project_id) REFERENCES projects(id);

DROP TABLE IF EXISTS journal_entry_lines CASCADE;
DROP TABLE IF EXISTS coa_accounts CASCADE;


-- D. Unifikasi Barang/Material: Pindahkan acuan `materials` ke `items`
-- Ubah budget_items, material_receipt_items, po_items, material_out_items agar menggunakan item_id
ALTER TABLE budget_items RENAME COLUMN material_id TO item_id;
ALTER TABLE budget_items ALTER COLUMN item_id TYPE bigint;

ALTER TABLE po_items RENAME COLUMN material_id TO item_id;
ALTER TABLE po_items ALTER COLUMN item_id TYPE bigint;

ALTER TABLE material_receipt_items RENAME COLUMN material_id TO item_id;
ALTER TABLE material_receipt_items ALTER COLUMN item_id TYPE bigint;

ALTER TABLE material_out_items RENAME COLUMN material_id TO item_id;
ALTER TABLE material_out_items ALTER COLUMN item_id TYPE bigint;

-- Hapus tabel materials
DROP TABLE IF EXISTS materials CASCADE;


-- ============================================================================
-- 2. PENYELARASAN TIPE DATA (PK & FK ID -> BIGINT)
-- ============================================================================

-- A. Table: Projects & Warehouses
ALTER TABLE projects ALTER COLUMN id TYPE bigint;
ALTER TABLE projects ALTER COLUMN pic_id TYPE bigint;
ALTER TABLE projects ALTER COLUMN location_id TYPE bigint;

ALTER TABLE warehouses ALTER COLUMN id TYPE bigint;
ALTER TABLE warehouses ALTER COLUMN project_id TYPE bigint;
ALTER TABLE warehouses ALTER COLUMN location_id TYPE bigint;

-- B. Table: Department Executive & User System
ALTER TABLE m_department_executive ALTER COLUMN department_id TYPE bigint;
ALTER TABLE m_department_executive ALTER COLUMN employee_id TYPE bigint;

ALTER TABLE m_user_system ALTER COLUMN employee_id TYPE bigint;

-- C. Table: Transactions (GRN, PO, Invoices, Payments)
ALTER TABLE grn_hdr ALTER COLUMN supplier_id TYPE bigint;
ALTER TABLE purchase_invoice_hdr ALTER COLUMN supplier_id TYPE bigint;
ALTER TABLE supplier_payments ALTER COLUMN supplier_id TYPE bigint;
ALTER TABLE payment_entries ALTER COLUMN customer_id TYPE bigint;
ALTER TABLE purchase_payment_entries ALTER COLUMN supplier_id TYPE bigint;
ALTER TABLE purchase_return_hdr ALTER COLUMN supplier_id TYPE bigint;
ALTER TABLE purchase_return_dtl ALTER COLUMN item_id TYPE bigint;


-- ============================================================================
-- 3. PENAMBAHAN MISSING FOREIGN KEY CONSTRAINTS
-- ============================================================================

-- A. Foreign Keys pada Module Purchasing & Payment
ALTER TABLE purchase_payment_entries
    ADD CONSTRAINT fk_ppe_supplier FOREIGN KEY (supplier_id) REFERENCES m_suppliers(id),
    ADD CONSTRAINT fk_ppe_account FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id);

ALTER TABLE payment_entries
    ADD CONSTRAINT fk_pe_customer FOREIGN KEY (customer_id) REFERENCES m_customer(id);

ALTER TABLE purchase_return_hdr
    ADD CONSTRAINT fk_prh_supplier FOREIGN KEY (supplier_id) REFERENCES m_suppliers(id);

ALTER TABLE purchase_return_dtl
    ADD CONSTRAINT fk_prd_item FOREIGN KEY (item_id) REFERENCES items(id);

-- B. Foreign Keys pada Module HR & Master Area
ALTER TABLE m_department_executive
    ADD CONSTRAINT fk_mde_department FOREIGN KEY (department_id) REFERENCES m_department(id),
    ADD CONSTRAINT fk_mde_employee FOREIGN KEY (employee_id) REFERENCES m_employee(id);

ALTER TABLE m_project_budget
    ALTER COLUMN project_id TYPE bigint,
    ALTER COLUMN item_id TYPE bigint,
    ADD CONSTRAINT fk_mpb_project FOREIGN KEY (project_id) REFERENCES projects(id),
    ADD CONSTRAINT fk_mpb_item FOREIGN KEY (item_id) REFERENCES items(id);

-- C. Foreign Keys pada Transaksi Material -> Items
ALTER TABLE budget_items
    ADD CONSTRAINT fk_budget_items_item FOREIGN KEY (item_id) REFERENCES items(id);

ALTER TABLE po_items
    ADD CONSTRAINT fk_po_items_item FOREIGN KEY (item_id) REFERENCES items(id);

ALTER TABLE material_receipt_items
    ADD CONSTRAINT fk_mri_item FOREIGN KEY (item_id) REFERENCES items(id);

ALTER TABLE material_out_items
    ADD CONSTRAINT fk_moi_item FOREIGN KEY (item_id) REFERENCES items(id);

COMMIT;
