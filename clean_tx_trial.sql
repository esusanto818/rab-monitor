-- ============================================================================
-- clean_transaksi_trial.sql
-- Bersihkan data TRANSAKSI untuk trial entry ulang: PO -> GRN -> WH Rcv -> (Invoice/Payment/Return)
-- + Issue/Material Out, ledger stok, dan akuntansi turunannya.
--
-- DIPERTAHANKAN (tidak disentuh):
--   Master : projects, budget_items (RAB), clients, materials, m_suppliers, m_currency, warehouses,
--            bin_locations, item_*, m_* (alamat, kontak, wilayah), tax_exchange_rate_*
--   Akuntansi master : chart_of_accounts, coa_accounts, fiscal_config, accounting_periods
--   Sistem : user, menu & hak akses (m_user_*, m_form_*), HR (m_employee dst), log audit (data_log_*)
--
-- DIHAPUS (semua baris, sequence kembali ke 1):
--   Pengadaan : purchase_orders, po_items, grn_hdr, grn_dtl, wh_receiving_hdr, wh_receiving_dtl,
--               purchase_invoice_hdr/dtl, supplier_payments/_items, purchase_return_hdr/dtl
--   Stok      : stock_movements, material_out_header/_items, material_issue_allocations,
--               material_receipt_header/_items
--   Akuntansi : journal_entries, journal_lines, journal_entry_lines, general_ledger
--   Keuangan  : financial_incomes, financial_expenses, project_invoices, project_payments(+_items)
--
-- CARA PAKAI
--   1) Backup dulu (data uji pun, jaga-jaga):
--        pg_dump -h localhost -p 5432 -U postgres -Fc proyekGMN > backup_sebelum_clean_$(date +%F_%H%M).dump
--   2) Dry-run: ganti baris COMMIT di bawah menjadi ROLLBACK, jalankan, cek angka "sebelum -> sesudah".
--   3) Jalankan sungguhan dengan COMMIT:
--        psql -h localhost -p 5432 -U postgres -d proyekGMN -f clean_transaksi_trial.sql
--
-- PENGAMAN: berhenti kalau database bukan 'proyekGMN'; tidak memakai CASCADE (kalau ada tabel lain
-- yang merujuk tabel daftar, PostgreSQL menolak dan seluruh transaksi di-rollback, jadi master tidak ikut terhapus).
-- Tabel yang tidak ada di database dilewati dengan NOTICE.
-- ============================================================================
\set ON_ERROR_STOP on

BEGIN;

DO $$
DECLARE
    wanted   text[] := ARRAY[
        -- Pengadaan & penerimaan
        'purchase_return_dtl', 'purchase_return_hdr',
        'supplier_payment_items', 'supplier_payments',
        'purchase_invoice_dtl', 'purchase_invoice_hdr',
        'wh_receiving_dtl', 'wh_receiving_hdr',
        'grn_dtl', 'grn_hdr',
        'po_items', 'purchase_orders',
        -- Stok & issue
        'stock_movements',
        'material_issue_allocations', 'material_out_items', 'material_out_header',
        'material_receipt_items', 'material_receipt_header',
        -- Akuntansi
        'general_ledger', 'journal_lines', 'journal_entry_lines', 'journal_entries',
        -- Keuangan proyek
        'project_payment_items', 'project_payments', 'project_invoices',
        'financial_incomes', 'financial_expenses'
    ];
    existing text[] := '{}';
    t        text;
    n_before bigint;
    n_after  bigint;
    before_n bigint[] := '{}';
    i        int;
BEGIN
    IF current_database() <> 'proyekGMN' THEN
        RAISE EXCEPTION 'Dibatalkan: database aktif "%", bukan proyekGMN.', current_database();
    END IF;

    FOREACH t IN ARRAY wanted LOOP
        IF to_regclass('public.' || quote_ident(t)) IS NULL THEN
            RAISE NOTICE 'dilewati (tabel tidak ada): %', t;
        ELSE
            EXECUTE format('SELECT count(*) FROM public.%I', t) INTO n_before;
            existing  := existing  || t;
            before_n  := before_n  || n_before;
        END IF;
    END LOOP;

    EXECUTE 'TRUNCATE TABLE '
         || (SELECT string_agg('public.' || quote_ident(x), ', ') FROM unnest(existing) AS x)
         || ' RESTART IDENTITY';

    FOR i IN 1 .. array_length(existing, 1) LOOP
        EXECUTE format('SELECT count(*) FROM public.%I', existing[i]) INTO n_after;
        RAISE NOTICE '% : % -> %', rpad(existing[i], 28), before_n[i], n_after;
    END LOOP;
END $$;

-- Opsional: kosongkan juga log audit (hapus tanda -- bila diinginkan)
-- TRUNCATE TABLE public.data_log_insert, public.data_log_update, public.data_log_delete RESTART IDENTITY;

-- Opsional: reset total proyek & RAB (biasanya TIDAK, karena PO butuh proyek + RAB). Hapus tanda -- bila perlu:
-- TRUNCATE TABLE public.budget_items RESTART IDENTITY;   -- RAB
-- (projects tidak bisa di-TRUNCATE tanpa menyertakan tabel yang merujuknya; gunakan DELETE bila benar-benar perlu)

COMMIT;     -- <- ganti ke ROLLBACK untuk dry-run

-- Verifikasi sequence (is_called = f artinya nomor berikutnya kembali ke 1):
SELECT 'po_items' AS seq, last_value, is_called FROM po_items_id_seq
UNION ALL SELECT 'purchase_orders', last_value, is_called FROM purchase_orders_id_seq
UNION ALL SELECT 'grn_hdr',         last_value, is_called FROM grn_hdr_id_seq
UNION ALL SELECT 'wh_receiving_hdr', last_value, is_called FROM wh_receiving_hdr_id_seq;
