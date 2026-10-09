-- ============================================================================
-- Migration: Cleanup berdasarkan hasil discovery_audit.sql & achema_proyekgmn03.md
-- ----------------------------------------------------------------------------
-- Dibagi 3 bagian:
--   A. Preview isi tabel kecil SEBELUM diarsipkan (baca dulu hasilnya)
--   B. DROP tabel yang benar2 kosong (0 baris, 0 referensi -- tanpa risiko)
--   C. ARSIPKAN (rename, bukan DROP) tabel kecil tak terpakai tapi ada isinya
--
-- Bagian "tambah Primary Key ke tabel payroll" dari versi sebelumnya DIHAPUS
-- dari file ini -- seluruh tabel payroll/HR sudah Anda hapus sendiri di luar
-- migration ini, jadi statement itu sekarang tidak relevan lagi (tabelnya
-- sudah tidak ada).
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
--    dump / diagram relasi ERD), tapi warehouses00 & suppliers masih ada
--    isinya -- lihat hasil bagian A dulu. m_warehouse baru ditambahkan di
--    sini (pola sama: duplikat lama dari `warehouses`, terisolasi total di
--    diagram relasi). Setelah Anda yakin datanya memang tidak relevan,
--    tabel dengan prefix zz_deprecated_ ini aman di-DROP kapan saja.
--
--    m_projects & warehouses00 SUDAH TIDAK ADA di skema Anda saat ini
--    (terkonfirmasi lewat achema_proyekgmn03.md) -- baris untuk keduanya
--    tetap dibiarkan di bawah karena "IF EXISTS" membuatnya otomatis
--    dilewati dengan aman, bukan error.
-- ----------------------------------------------------------------------------
ALTER TABLE IF EXISTS public.suppliers     RENAME TO zz_deprecated_suppliers;
ALTER TABLE IF EXISTS public.m_projects    RENAME TO zz_deprecated_m_projects;
ALTER TABLE IF EXISTS public.warehouses00  RENAME TO zz_deprecated_warehouses00;
ALTER TABLE IF EXISTS public.m_warehouse   RENAME TO zz_deprecated_m_warehouse;


\echo ''
\echo '=== SELESAI. Tabel yang masih berstatus aktif sekarang: ==='
SELECT c.relname
FROM pg_class c
JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public' AND c.relkind='r'
ORDER BY 1;
