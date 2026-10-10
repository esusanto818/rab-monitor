-- ============================================================================
-- Discovery Script #2 -- HANYA SELECT, tidak mengubah apa pun.
--   psql -h <host> -U <user> -d proyekGMN -f discovery_audit_2.sql > hasil_discovery_2.txt
-- ============================================================================

\echo '=== 1. KONFIRMASI PALING PENTING: apakah tabel payroll/HR ini benar sudah dihapus? ==='
\echo '    (kalau psql bilang "does not exist" utk salah satu, tabel itu memang sudah hilang)'
SELECT 'employee_absence_detail_period' AS tabel, to_regclass('public.employee_absence_detail_period') IS NOT NULL AS masih_ada
UNION ALL SELECT 'employee_payroll_dtl_close', to_regclass('public.employee_payroll_dtl_close') IS NOT NULL
UNION ALL SELECT 'employee_payroll_hdr_close', to_regclass('public.employee_payroll_hdr_close') IS NOT NULL
UNION ALL SELECT 'employee_slip_periode',      to_regclass('public.employee_slip_periode') IS NOT NULL
UNION ALL SELECT 'employee_wages_periode',     to_regclass('public.employee_wages_periode') IS NOT NULL
UNION ALL SELECT 'employee_thr',               to_regclass('public.employee_thr') IS NOT NULL
UNION ALL SELECT 'employee_rekening_mayapada', to_regclass('public.employee_rekening_mayapada') IS NOT NULL;

\echo ''
\echo '=== 2. Kalau ada yang MASIH ADA di atas -- cek jumlah baris (jangan sampai isinya kosong tanpa sadar) ==='
DO $$
DECLARE
    t text;
    n bigint;
BEGIN
    FOREACH t IN ARRAY ARRAY['employee_absence_detail_period','employee_payroll_dtl_close',
                              'employee_payroll_hdr_close','employee_slip_periode',
                              'employee_wages_periode','employee_thr','employee_rekening_mayapada']
    LOOP
        IF to_regclass('public.' || t) IS NOT NULL THEN
            EXECUTE format('SELECT count(*) FROM public.%I', t) INTO n;
            RAISE NOTICE '%: % baris', t, n;
        END IF;
    END LOOP;
END $$;

\echo ''
\echo '=== 3. m_warehouse vs warehouses -- duplikat baru yang belum pernah dicek ==='
SELECT 'warehouses' AS tabel, count(*) AS jumlah_baris FROM public.warehouses
UNION ALL
SELECT 'm_warehouse', count(*) FROM public.m_warehouse;

\echo '--- isi m_warehouse (kalau ada) ---'
SELECT * FROM public.m_warehouse LIMIT 10;

\echo ''
\echo '=== 4. Ulangi cek suppliers & m_projects -- pastikan masih sama seperti audit sebelumnya ==='
DO $$
DECLARE
    t text;
    n bigint;
BEGIN
    FOREACH t IN ARRAY ARRAY['suppliers','m_suppliers','m_projects','projects']
    LOOP
        IF to_regclass('public.' || t) IS NOT NULL THEN
            EXECUTE format('SELECT count(*) FROM public.%I', t) INTO n;
            RAISE NOTICE '%: % baris', t, n;
        ELSE
            RAISE NOTICE '%: TABEL SUDAH TIDAK ADA', t;
        END IF;
    END LOOP;
END $$;

\echo ''
\echo '=== 5. View yang mungkin rusak -- apakah masih ada & masih bisa dijalankan? ==='
SELECT viewname, definition IS NOT NULL AS ada
FROM pg_views
WHERE schemaname = 'public'
  AND viewname IN ('view_grn_invoice_status', 'v_material_budget_monitoring', 'view_stock_balance');

\echo '--- coba jalankan v_material_budget_monitoring (kandidat rusak krn rab_materials dihapus) ---'
DO $$
BEGIN
    PERFORM * FROM public.v_material_budget_monitoring LIMIT 3;
    RAISE NOTICE 'v_material_budget_monitoring: OK, bisa dijalankan';
EXCEPTION WHEN undefined_table OR others THEN
    RAISE NOTICE 'v_material_budget_monitoring: RUSAK -- %', SQLERRM;
END $$;

\echo '--- coba jalankan view_grn_invoice_status (harus tetap jalan normal) ---'
DO $$
BEGIN
    PERFORM * FROM public.view_grn_invoice_status LIMIT 3;
    RAISE NOTICE 'view_grn_invoice_status: OK, bisa dijalankan';
EXCEPTION WHEN undefined_table OR others THEN
    RAISE NOTICE 'view_grn_invoice_status: RUSAK -- %', SQLERRM;
END $$;

\echo ''
\echo '=== 6. Pastikan m_bin_location memang sudah tidak ada (konfirmasi bug whrcvCRD.php) ==='
SELECT to_regclass('public.m_bin_location') AS m_bin_location_masih_ada,
       to_regclass('public.bin_locations') AS bin_locations_masih_ada;

\echo ''
\echo '=== SELESAI -- upload seluruh output ini ==='
