-- ============================================================================
-- budget_items_unique_index.sql
-- Perbaiki "Gagal menyimpan RAB: SQLSTATE[42P10] ... there is no unique or exclusion
-- constraint matching the ON CONFLICT specification" (rabCRD.php -> save_rab).
--
-- Penyebab : save_rab memakai
--              ON CONFLICT (project_id, material_id) WHERE category = 'Material' AND material_id IS NOT NULL
--            sedangkan unique index pasangannya belum ada di database. Karena index itu juga yang
--            mencegah baris ganda, ketiadaannya sekaligus menjelaskan RAB ganda sebelumnya.
-- ============================================================================

-- LANGKAH 1: cek RAB ganda di SEMUA proyek (harus 0 baris sebelum index bisa dibuat)
SELECT p.name AS proyek, bi.project_id, bi.material_id, m.material_name,
       COUNT(*)                                   AS jumlah_baris,
       ARRAY_AGG(bi.id ORDER BY bi.id)            AS ids,
       ARRAY_AGG(bi.qty_plan ORDER BY bi.id)      AS qty,
       ARRAY_AGG(bi.unit_price_plan ORDER BY bi.id) AS harga
  FROM budget_items bi
  JOIN projects  p ON p.id = bi.project_id
  JOIN materials m ON m.id = bi.material_id
 WHERE bi.category = 'Material' AND bi.material_id IS NOT NULL
 GROUP BY p.name, bi.project_id, bi.material_id, m.material_name
HAVING COUNT(*) > 1
 ORDER BY p.name, m.material_name;

-- LANGKAH 2: kalau LANGKAH 1 menampilkan baris, hapus duplikat yang IDENTIK (qty, harga, toleransi sama)
--            dengan menyimpan id terkecil, lalu buat index -- dalam satu transaksi.
--            Duplikat yang nilainya berbeda tidak disentuh; pembuatan index akan gagal dan
--            seluruh transaksi di-rollback, jadi data tidak berubah sampai Anda memutuskan manual.
--            (Jalankan sebelum ada PO baru. po_items.budget_item_id bersifat ON DELETE SET NULL.)
BEGIN;

DELETE FROM budget_items b
 USING budget_items k
 WHERE b.category = 'Material' AND k.category = 'Material'
   AND b.material_id IS NOT NULL
   AND b.project_id = k.project_id AND b.material_id = k.material_id
   AND b.id > k.id
   AND b.qty_plan         IS NOT DISTINCT FROM k.qty_plan
   AND b.unit_price_plan  IS NOT DISTINCT FROM k.unit_price_plan
   AND b.tolerance_pct    IS NOT DISTINCT FROM k.tolerance_pct;

-- Predikat HARUS persis sama dengan klausa ON CONFLICT di rabCRD.php
CREATE UNIQUE INDEX IF NOT EXISTS ux_budget_items_project_material
    ON public.budget_items (project_id, material_id)
 WHERE category = 'Material' AND material_id IS NOT NULL;

COMMIT;

-- LANGKAH 3: verifikasi
SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'budget_items';
