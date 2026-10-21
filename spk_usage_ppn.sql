-- ============================================================
-- 2026-10-19.spk_usage_ppn.sql
-- 1) SPK (Surat Perintah Kerja): tabel spk + spk_items, kolom material_out_header.spk_id
-- 2) Pengaturan proyek: PPN, retensi, DP minimum (projects.ppn_rate, ppn_inclusive, retention_pct, dp_min_pct)
-- 3) View pemakaian material vs progres: v_project_material_vs_progress (per proyek) dan
--    v_project_material_detail_vs_progress (per proyek per material)
-- 4) Menu: SPK, PEMAKAIAN MATERIAL VS PROGRES (salin grup/departemen/akses dari form Pembayaran Termin id 145)
-- Jalankan: psql -h localhost -U postgres -d proyekGMN -f spk_usage_ppn.sql      (aman diulang)
-- ============================================================
BEGIN;

-- ---------- 1) SPK ----------
CREATE TABLE IF NOT EXISTS spk (
    id          serial PRIMARY KEY,
    spk_no      varchar(50)  NOT NULL UNIQUE,
    spk_date    date         NOT NULL,
    project_id  integer      NOT NULL REFERENCES projects(id),
    foreman     varchar(100),                     -- mandor / pelaksana
    description text,                             -- uraian pekerjaan
    status      varchar(20)  NOT NULL DEFAULT 'Draft' CHECK (status IN ('Draft', 'Diterbitkan', 'Selesai', 'Dibatalkan')),
    issued_at   timestamp,
    issued_by   integer,
    closed_at   timestamp,
    closed_by   integer,
    remark      text,
    created_by  integer,
    created_at  timestamp    NOT NULL DEFAULT now(),
    updated_at  timestamp    NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_spk_project ON spk (project_id);

CREATE TABLE IF NOT EXISTS spk_items (
    id          serial PRIMARY KEY,
    spk_id      integer       NOT NULL REFERENCES spk(id) ON DELETE CASCADE,
    material_id integer       NOT NULL REFERENCES materials(id),
    qty         numeric(18,4) NOT NULL CHECK (qty > 0),
    unit        varchar(30),
    notes       text
);
CREATE INDEX IF NOT EXISTS idx_spk_items_spk ON spk_items (spk_id);

ALTER TABLE material_out_header ADD COLUMN IF NOT EXISTS spk_id integer REFERENCES spk(id);
CREATE INDEX IF NOT EXISTS idx_material_out_header_spk ON material_out_header (spk_id);

-- ---------- 2) Pengaturan proyek ----------
ALTER TABLE projects ADD COLUMN IF NOT EXISTS ppn_rate      numeric(5,2) NOT NULL DEFAULT 0;      -- % PPN (0 = tanpa PPN); proyek lama tetap 0
ALTER TABLE projects ADD COLUMN IF NOT EXISTS ppn_inclusive boolean      NOT NULL DEFAULT false;  -- true = nilai kontrak sudah termasuk PPN
ALTER TABLE projects ADD COLUMN IF NOT EXISTS retention_pct numeric(5,2) NOT NULL DEFAULT 0;      -- % retensi yang ditahan client
ALTER TABLE projects ADD COLUMN IF NOT EXISTS dp_min_pct    numeric(5,2) NOT NULL DEFAULT 30;     -- DP minimum (% nilai kontrak)

-- ---------- 3) View pemakaian material vs progres ----------
-- Pemakaian = issue proyek (posted) bernilai FIFO; pembanding = nilai RAB material (qty_plan x harga RAB, dikonversi ke IDR
-- memakai kurs anggaran proyek). Selisih = % pemakaian - % progres. > 10 poin = WASPADA, > 20 poin = KRITIS.
CREATE OR REPLACE VIEW v_project_material_vs_progress AS
WITH base AS (
    SELECT p.id AS project_id, p.name AS project_name, c.name AS client_name, p.status AS project_status,
           COALESCE(p.progress, 0)::numeric AS progress_pct,
           COALESCE(NULLIF(p.budget_exch_rate, 0), 1) AS rate
      FROM projects p
      LEFT JOIN clients c ON c.id = p.client_id
), rab AS (
    SELECT bi.project_id, SUM(bi.qty_plan * COALESCE(bi.unit_price_plan, 0)) AS rab_value
      FROM budget_items bi WHERE bi.material_id IS NOT NULL GROUP BY bi.project_id
), used AS (
    SELECT h.project_id, SUM(i.qty_out * i.unit_cost) AS used_value
      FROM material_out_header h JOIN material_out_items i ON i.material_out_id = h.id
     WHERE h.status = 'posted' AND h.issue_type = 'PROJECT' GROUP BY h.project_id
), calc AS (
    SELECT b.*, ROUND(COALESCE(r.rab_value, 0) * b.rate, 2) AS rab_value, ROUND(COALESCE(u.used_value, 0), 2) AS used_value
      FROM base b LEFT JOIN rab r ON r.project_id = b.project_id LEFT JOIN used u ON u.project_id = b.project_id
)
SELECT project_id, project_name, client_name, project_status, progress_pct, rab_value, used_value,
       CASE WHEN rab_value > 0 THEN ROUND(used_value / rab_value * 100, 2) END AS usage_pct,
       CASE WHEN rab_value > 0 THEN ROUND(used_value / rab_value * 100 - progress_pct, 2) END AS gap_pts,
       CASE WHEN rab_value <= 0 THEN 'TANPA RAB'
            WHEN used_value / rab_value * 100 - progress_pct > 20 THEN 'KRITIS'
            WHEN used_value / rab_value * 100 - progress_pct > 10 THEN 'WASPADA'
            ELSE 'NORMAL' END AS flag
  FROM calc;

CREATE OR REPLACE VIEW v_project_material_detail_vs_progress AS
WITH rab AS (
    SELECT bi.project_id, bi.material_id, SUM(bi.qty_plan) AS rab_qty,
           SUM(bi.qty_plan * COALESCE(bi.unit_price_plan, 0)) AS rab_value
      FROM budget_items bi WHERE bi.material_id IS NOT NULL GROUP BY bi.project_id, bi.material_id
), used AS (
    SELECT h.project_id, i.material_id, SUM(i.qty_out) AS used_qty, SUM(i.qty_out * i.unit_cost) AS used_value
      FROM material_out_header h JOIN material_out_items i ON i.material_out_id = h.id
     WHERE h.status = 'posted' AND h.issue_type = 'PROJECT' GROUP BY h.project_id, i.material_id
)
SELECT r.project_id, r.material_id, m.material_code, m.material_name, m.unit,
       r.rab_qty, COALESCE(u.used_qty, 0) AS used_qty,
       CASE WHEN r.rab_qty > 0 THEN ROUND(COALESCE(u.used_qty, 0) / r.rab_qty * 100, 2) END AS usage_pct,
       COALESCE(p.progress, 0) AS progress_pct,
       CASE WHEN r.rab_qty > 0 THEN ROUND(COALESCE(u.used_qty, 0) / r.rab_qty * 100 - COALESCE(p.progress, 0), 2) END AS gap_pts,
       ROUND(r.rab_value * COALESCE(NULLIF(p.budget_exch_rate, 0), 1), 2) AS rab_value,
       ROUND(COALESCE(u.used_value, 0), 2) AS used_value
  FROM rab r
  JOIN materials m ON m.id = r.material_id
  JOIN projects p ON p.id = r.project_id
  LEFT JOIN used u ON u.project_id = r.project_id AND u.material_id = r.material_id;

-- ---------- 4) Menu ----------
INSERT INTO m_form_name (group_id, seq, title, php_url, js_url, remark, department_id, main_table, tna, active)
SELECT group_id, seq, 'SPK', 'spk.php', 'spk.js', 'Surat Perintah Kerja: izin issue material proyek', department_id, 'spk', tna, true
  FROM m_form_name WHERE id = 145 AND NOT EXISTS (SELECT 1 FROM m_form_name WHERE php_url = 'spk.php');
INSERT INTO m_form_name (group_id, seq, title, php_url, js_url, remark, department_id, main_table, tna, active)
SELECT group_id, seq, 'PEMAKAIAN MATERIAL VS PROGRES', 'project.usage.php', 'project.usage.js',
       'Perbandingan pemakaian material dengan progres fisik proyek', department_id, 'material_out_header', tna, true
  FROM m_form_name WHERE id = 145 AND NOT EXISTS (SELECT 1 FROM m_form_name WHERE php_url = 'project.usage.php');

INSERT INTO m_user_access_menu (user_id, form_id, access_level)
SELECT a.user_id, n.id, a.access_level
  FROM m_user_access_menu a
  JOIN m_form_name n ON n.php_url IN ('spk.php', 'project.usage.php')
 WHERE a.form_id = 145
   AND NOT EXISTS (SELECT 1 FROM m_user_access_menu x WHERE x.user_id = a.user_id AND x.form_id = n.id);

COMMIT;
-- Setelah menjalankan, login ulang agar menu baru muncul.
