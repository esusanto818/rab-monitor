-- ============================================================================
-- Migration: Cleanup berdasarkan hasil discovery_audit.sql
-- ----------------------------------------------------------------------------
-- Dibagi 4 bagian, urutkan dari paling aman ke yang perlu Anda tinjau:
--   A. Preview isi tabel kecil SEBELUM diarsipkan (baca dulu hasilnya)
--   B. DROP tabel yang benar2 kosong (0 baris, 0 referensi -- tanpa risiko)
--   C. ARSIPKAN (rename, bukan DROP) tabel kecil tak terpakai tapi ada isinya
--   D. Tambah Primary Key ke tabel payroll/HR yang datanya besar & nyata --
--      TIDAK di-drop/rename, cuma dikasih identitas baris yang selama ini
--      tidak ada sama sekali.
--
--   psql -h <host> -U <user> -d proyekGMN -v ON_ERROR_STOP=1 -f migration_cleanup.sql
-- ============================================================================


-- ----------------------------------------------------------------------------
-- A. PREVIEW -- baca hasil SELECT ini dulu sebelum lanjut ke bagian C.
--    Kalau ternyata ada baris yang masih relevan, STOP, jangan lanjutkan
--    rename tabel itu di bagian C -- kabari saya datanya seperti apa.
-- ----------------------------------------------------------------------------
\echo '--- isi suppliers (2 baris, sebelum diarsipkan) ---'
SELECT * FROM public.suppliers;

\echo '--- isi m_projects (2 baris, sebelum diarsipkan) ---'
SELECT * FROM public.m_projects;

\echo '--- isi warehouses00 (2 baris, sebelum diarsipkan) ---'
SELECT * FROM public.warehouses00;


-- ----------------------------------------------------------------------------
-- B. DROP -- 0 baris & 0 referensi terkonfirmasi lewat discovery_audit.sql.
--    Tidak ada data yang hilang.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS public.payment_entries;           -- 0 baris, customer_id tidak ber-FK ke mana pun
DROP TABLE IF EXISTS public.purchase_payment_entries;   -- 0 baris, duplikat lama dari supplier_payments
DROP TABLE IF EXISTS public.m_project_budget;           -- 0 baris sama sekali (total_baris=0 di discovery)


-- ----------------------------------------------------------------------------
-- C. ARSIPKAN (rename) -- bukan DROP, supaya reversibel. Tidak ada FK yang
--    menunjuk ke tabel-tabel ini (dicek lewat grep FOREIGN KEY di seluruh
--    dump), tapi masing-masing masih punya 2 baris -- lihat hasil bagian A
--    dulu. Setelah Anda yakin datanya memang tidak relevan, tabel dengan
--    prefix zz_deprecated_ ini aman di-DROP kapan saja.
-- ----------------------------------------------------------------------------
ALTER TABLE IF EXISTS public.suppliers     RENAME TO zz_deprecated_suppliers;
ALTER TABLE IF EXISTS public.m_projects    RENAME TO zz_deprecated_m_projects;
ALTER TABLE IF EXISTS public.warehouses00  RENAME TO zz_deprecated_warehouses00;


-- ----------------------------------------------------------------------------
-- D. TAMBAH PRIMARY KEY -- 12 tabel payroll/HR ini isinya data sungguhan
--    (ribuan-puluhan ribu baris per discovery_audit.sql: employee_absence_
--    detail_period saja 48.490 baris) -- TIDAK disentuh datanya sama sekali,
--    cuma ditambah kolom identitas baris karena SEMUANYA saat ini tidak
--    punya cara apa pun untuk menunjuk satu baris spesifik (tidak ada PK,
--    dan beberapa bahkan tidak punya kolom "id" sama sekali).
--
--    Kolom baru diberi nama "row_id" (bukan "id") karena employee_thr dan
--    employee_rekening_mayapada SUDAH punya kolom "id" sendiri (bigint,
--    tanpa constraint apa pun, kemungkinan berisi duplikat) -- row_id tidak
--    akan bentrok dan tidak mengubah/menghapus kolom "id" lama itu.
--
--    ADD COLUMN ... PRIMARY KEY otomatis mengisi seluruh baris lama secara
--    berurutan, tidak butuh nilai unik yang sudah ada di data.
-- ----------------------------------------------------------------------------
ALTER TABLE public.employee_absence_detail_period ADD COLUMN row_id bigserial PRIMARY KEY;
ALTER TABLE public.employee_absence_header_period  ADD COLUMN row_id bigserial PRIMARY KEY;
ALTER TABLE public.employee_payroll_dtl_close      ADD COLUMN row_id bigserial PRIMARY KEY;
ALTER TABLE public.employee_payroll_hdr_close      ADD COLUMN row_id bigserial PRIMARY KEY;
ALTER TABLE public.employee_slip_periode           ADD COLUMN row_id bigserial PRIMARY KEY;
ALTER TABLE public.employee_wages_periode          ADD COLUMN row_id bigserial PRIMARY KEY;
ALTER TABLE public.employee_thr                    ADD COLUMN row_id bigserial PRIMARY KEY;
ALTER TABLE public.employee_rekening_mayapada       ADD COLUMN row_id bigserial PRIMARY KEY;
ALTER TABLE public.employee_new_rek1                ADD COLUMN row_id bigserial PRIMARY KEY;
ALTER TABLE public.employee_premi_062018            ADD COLUMN row_id bigserial PRIMARY KEY;
ALTER TABLE public.backup_employee_department       ADD COLUMN row_id bigserial PRIMARY KEY;
ALTER TABLE public.backup_employee_section          ADD COLUMN row_id bigserial PRIMARY KEY;

\echo ''
\echo '=== SELESAI. Cek ulang: semua tabel di atas sekarang punya PK? ==='
SELECT c.relname,
       EXISTS (SELECT 1 FROM pg_constraint pk WHERE pk.conrelid=c.oid AND pk.contype='p') AS punya_pk
FROM pg_class c
JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public' AND c.relkind='r'
  AND c.relname IN (
    'employee_absence_detail_period','employee_absence_header_period',
    'employee_payroll_dtl_close','employee_payroll_hdr_close',
    'employee_slip_periode','employee_wages_periode','employee_thr',
    'employee_rekening_mayapada','employee_new_rek1','employee_premi_062018',
    'backup_employee_department','backup_employee_section'
  )
ORDER BY 1;
