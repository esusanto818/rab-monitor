-- ============================================================================
-- Discovery Script -- HANYA SELECT, tidak mengubah apa pun. Aman dijalankan
-- langsung ke database produksi.
--
-- Cara pakai: jalankan lewat psql supaya semua label section ikut tercetak,
-- lalu upload seluruh output-nya (bukan cuma sebagian):
--   psql -h <host> -U <user> -d proyekGMN -f discovery_audit.sql > hasil_discovery.txt
-- ============================================================================

\echo '=== 1. suppliers vs m_suppliers -- mana yang benar-benar berisi data & dipakai ==='
SELECT 'suppliers' AS tabel, count(*) AS jumlah_baris FROM public.suppliers
UNION ALL
SELECT 'm_suppliers', count(*) FROM public.m_suppliers;

\echo ''
\echo '=== 2. payment_entries & purchase_payment_entries -- ada data atau kosong ==='
SELECT 'payment_entries' AS tabel, count(*) AS jumlah_baris FROM public.payment_entries
UNION ALL
SELECT 'purchase_payment_entries', count(*) FROM public.purchase_payment_entries;

\echo ''
\echo '=== 3. Contoh isi payment_entries (kalau ada) -- utk lihat customer_id-nya nunjuk ke mana ==='
SELECT * FROM public.payment_entries LIMIT 5;

\echo ''
\echo '=== 4. projects vs m_projects -- mana yang aktif dipakai ==='
SELECT 'projects' AS tabel, count(*) AS jumlah_baris FROM public.projects
UNION ALL
SELECT 'm_projects', count(*) FROM public.m_projects;

\echo ''
\echo '=== 5. m_project_budget.project_id -- cocok ke projects atau ke m_projects? ==='
SELECT
    count(*) AS total_baris,
    count(*) FILTER (WHERE pb.project_id IN (SELECT id FROM public.projects))  AS cocok_ke_projects_aktif,
    count(*) FILTER (WHERE pb.project_id IN (SELECT id FROM public.m_projects)) AS cocok_ke_m_projects_legacy
FROM public.m_project_budget pb;

\echo ''
\echo '=== 6. Tabel HR/payroll tanpa Primary Key -- jumlah baris tiap tabel (kosong = aman diabaikan dulu) ==='
SELECT 'backup_employee_department' t, count(*) n FROM public.backup_employee_department
UNION ALL SELECT 'backup_employee_section', count(*) FROM public.backup_employee_section
UNION ALL SELECT 'employee_absence_detail_period', count(*) FROM public.employee_absence_detail_period
UNION ALL SELECT 'employee_absence_header_period', count(*) FROM public.employee_absence_header_period
UNION ALL SELECT 'employee_new_rek1', count(*) FROM public.employee_new_rek1
UNION ALL SELECT 'employee_payroll_dtl_close', count(*) FROM public.employee_payroll_dtl_close
UNION ALL SELECT 'employee_payroll_hdr_close', count(*) FROM public.employee_payroll_hdr_close
UNION ALL SELECT 'employee_premi_062018', count(*) FROM public.employee_premi_062018
UNION ALL SELECT 'employee_rekening_mayapada', count(*) FROM public.employee_rekening_mayapada
UNION ALL SELECT 'employee_slip_periode', count(*) FROM public.employee_slip_periode
UNION ALL SELECT 'employee_thr', count(*) FROM public.employee_thr
UNION ALL SELECT 'employee_wages_periode', count(*) FROM public.employee_wages_periode
UNION ALL SELECT 'warehouses00', count(*) FROM public.warehouses00
ORDER BY 2 DESC;

\echo ''
\echo '=== 7. app.username -- apakah SUDAH ada default di level database/role (di luar dump schema)? ==='
SELECT
    r.rolname,
    d.datname,
    s.setconfig
FROM pg_db_role_setting s
LEFT JOIN pg_roles r ON r.oid = s.setrole
LEFT JOIN pg_database d ON d.oid = s.setdatabase
WHERE s.setconfig::text ILIKE '%app.username%'
   OR d.datname = current_database();

\echo ''
\echo '=== 8. accounting_periods -- ada periode terbuka utk hari ini? (dibutuhkan AutoPostJournal) ==='
SELECT id, start_date, end_date, is_closed
FROM public.accounting_periods
WHERE current_date BETWEEN start_date AND end_date;

\echo ''
\echo '=== 9. fiscal_config -- rule PROJECT_* sudah ada & aktif? (cek migration billing sudah jalan atau belum) ==='
SELECT rule_code, debit_account_id, credit_account_id, is_active
FROM public.fiscal_config
WHERE rule_code LIKE 'PROJECT_%'
ORDER BY rule_code;

\echo ''
\echo '=== 10. Pre-check FK yang akan divalidasi -- HARUS KOSONG semua sebelum VALIDATE CONSTRAINT ==='
\echo '--- grn_dtl.grade_id yang tidak ada di item_grade ---'
SELECT id, grade_id FROM public.grn_dtl
WHERE grade_id IS NOT NULL AND grade_id NOT IN (SELECT id FROM public.item_grade);

\echo '--- wh_receiving_dtl.grade_id yang tidak ada di item_grade ---'
SELECT id, grade_id FROM public.wh_receiving_dtl
WHERE grade_id IS NOT NULL AND grade_id NOT IN (SELECT id FROM public.item_grade);

\echo '--- stock_movements.grade_id yang tidak ada di item_grade ---'
SELECT id, grade_id FROM public.stock_movements
WHERE grade_id IS NOT NULL AND grade_id NOT IN (SELECT id FROM public.item_grade);

\echo '--- m_bin_location.warehouse_id yang tidak ada di warehouses ---'
SELECT id, warehouse_id FROM public.m_bin_location
WHERE warehouse_id IS NOT NULL AND warehouse_id NOT IN (SELECT id FROM public.warehouses);

\echo '--- m_address: customer_id/supplier_id yang tidak valid ---'
SELECT id, customer_id, supplier_id FROM public.m_address
WHERE (customer_id IS NOT NULL AND customer_id NOT IN (SELECT id FROM public.m_customer))
   OR (supplier_id IS NOT NULL AND supplier_id NOT IN (SELECT id FROM public.m_suppliers));

\echo '--- m_contact_no: customer_id/supplier_id yang tidak valid ---'
SELECT id, customer_id, supplier_id FROM public.m_contact_no
WHERE (customer_id IS NOT NULL AND customer_id NOT IN (SELECT id FROM public.m_customer))
   OR (supplier_id IS NOT NULL AND supplier_id NOT IN (SELECT id FROM public.m_suppliers));

\echo '--- m_contact_person: customer_id/supplier_id yang tidak valid ---'
SELECT id, customer_id, supplier_id FROM public.m_contact_person
WHERE (customer_id IS NOT NULL AND customer_id NOT IN (SELECT id FROM public.m_customer))
   OR (supplier_id IS NOT NULL AND supplier_id NOT IN (SELECT id FROM public.m_suppliers));

\echo '--- purchase_invoice_hdr.status di luar posted/reversed ---'
SELECT id, status FROM public.purchase_invoice_hdr
WHERE status NOT IN ('posted', 'reversed');

\echo '--- material_out_header.status di luar posted/reversed ---'
SELECT id, status FROM public.material_out_header
WHERE status NOT IN ('posted', 'reversed');

\echo ''
\echo '=== SELESAI -- upload seluruh output ini ==='
