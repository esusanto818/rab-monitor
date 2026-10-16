-- 2026-10-14 : Pembersihan versi mobile pertama (tabel project_progress & project_progress_photos buatan mobile v1).
-- Versi baru memakai tabel modul desktop: project_progress_log (alur klaim -> verifikasi).
-- JANGAN menyentuh project_progress_log.

-- 1) Kembalikan progress proyek uji yang sempat diubah langsung oleh mobile v1.
--    Ganti 0 dengan nilai ASLI sebelum tes bila bukan 0.
UPDATE public.projects SET progress = 0 WHERE name = 'Gedung Wisma A - Surabaya';

-- 2) Hapus tabel mobile v1 (berisi data uji saja). photos ikut terhapus bila tabel induk di-DROP CASCADE.
DROP TABLE IF EXISTS public.project_progress_photos;
DROP TABLE IF EXISTS public.project_progress;
