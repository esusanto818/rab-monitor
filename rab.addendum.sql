-- PLAFON RAB: penanda baris tambahan yang memakai cadangan (kontrak - budget) saat proyek berjalan
-- Dipakai oleh rabCRD.php (save_rab / load_rab) dan rab.js
ALTER TABLE budget_items ADD COLUMN IF NOT EXISTS is_addendum     boolean   NOT NULL DEFAULT false;
ALTER TABLE budget_items ADD COLUMN IF NOT EXISTS addendum_reason text;
ALTER TABLE budget_items ADD COLUMN IF NOT EXISTS addendum_by     integer;       -- m_user_system.id pencatat tambahan terakhir
ALTER TABLE budget_items ADD COLUMN IF NOT EXISTS addendum_at     timestamp;

-- CEK DATA LAMA (informasi saja): proyek yang sudah melanggar aturan plafon.
-- Proyek ini tidak bisa menyimpan RAB / mengubah Master Proyek sampai angkanya dibereskan.
SELECT p.id, p.name, p.status,
       p.contract_value,
       p.budget * COALESCE(NULLIF(p.budget_exch_rate, 0), 1)                                          AS budget_base,
       COALESCE(r.total, 0) * COALESCE(NULLIF(p.budget_exch_rate, 0), 1)                              AS rab_base,
       CASE WHEN COALESCE(p.contract_value, 0) <= 0 THEN 'Nilai kontrak belum diisi'
            WHEN p.budget * COALESCE(NULLIF(p.budget_exch_rate, 0), 1) > p.contract_value THEN 'Budget > Nilai kontrak'
            WHEN COALESCE(r.total, 0) * COALESCE(NULLIF(p.budget_exch_rate, 0), 1) > p.contract_value THEN 'RAB > Nilai kontrak'
       END AS masalah
  FROM projects p
  LEFT JOIN (SELECT project_id, SUM(qty_plan * unit_price_plan) AS total
               FROM budget_items WHERE category = 'Material' GROUP BY project_id) r ON r.project_id = p.id
 WHERE COALESCE(p.contract_value, 0) <= 0
    OR p.budget * COALESCE(NULLIF(p.budget_exch_rate, 0), 1) > p.contract_value
    OR COALESCE(r.total, 0) * COALESCE(NULLIF(p.budget_exch_rate, 0), 1) > p.contract_value
 ORDER BY p.id;
