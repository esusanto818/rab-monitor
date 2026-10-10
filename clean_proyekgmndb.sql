-- 1. Hapus kolom customer_id integer di m_address (karena primary key/FK utama menggunakan character varying)
ALTER TABLE m_address DROP COLUMN IF EXISTS customer_id;
-- Opsi: Tambahkan ulang FK jika merujuk ke clients/m_customer
-- ALTER TABLE m_address ADD COLUMN client_id character varying(15) REFERENCES clients(id);

-- 2. Hapus kolom customer_id integer di m_contact_no
ALTER TABLE m_contact_no DROP COLUMN IF EXISTS customer_id;

-- 3. Hapus kolom customer_id integer di m_contact_person
ALTER TABLE m_contact_person DROP COLUMN IF EXISTS customer_id;


BEGIN;

-- TRUNCATE TABEL TRANSAKSI & LOG
TRUNCATE TABLE 
    -- Log Sistem
    data_log_insert,
    data_log_update,
    data_log_delete,
    
    -- Proyek & RAB
    rab_materials,
    budget_items,
    projects,
    
    -- Pengadaan & Penerimaan
    grn_dtl,
    grn_hdr,
    po_items,
    purchase_orders,
    
    -- Stok & Gudang
    stock_movements,
    material_out_items,
    material_out_hdr,
    material_receipt_items,
    material_receipt_hdr,
    
    -- Penggajian & Absensi
    employee_payroll_dtl,
    employee_payroll_hdr,
    employee_sal_dtl,
    employee_sal_hdr,
    employee_absence,
    
    -- Akuntansi & Keuangan
    project_payments,
    project_invoices,
    supplier_payments,
    purchase_invoice_dtl,
    purchase_invoice_hdr,
    general_ledger,
    journal_entry_lines,
    journal_entries,
    journal_lines
CASCADE;

-- RESET ALL SEQUENCES (Mengembalikan auto-increment ke 1)
DO $$ 
DECLARE 
    seq RECORD;
BEGIN
    FOR seq IN SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema = 'public' LOOP
        EXECUTE 'ALTER SEQUENCE ' || quote_ident(seq.sequence_name) || ' RESTART WITH 1';
    END LOOP;
END $$;

COMMIT;
