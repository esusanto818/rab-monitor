-- ============================================================
-- 2026-10-16.project_wip_release.sql
-- Pelepasan WIP -> HPP per proyek (per % progress atau saat proyek selesai).
-- Jalankan dengan psql (bukan dari tool yang membungkus transaksi), karena langkah 1 memakai \gexec
-- dan ALTER TYPE ... ADD VALUE tidak boleh di dalam transaksi pada PostgreSQL 9.6.
--   psql -h localhost -U postgres -d proyekGMN -f project_wip_release.sql
-- Aman dijalankan ulang.
-- ============================================================

-- 1) tambah nilai enum journal_entries.reference_type = 'wip_release' (nama tipe enum dicari otomatis)
SELECT format('ALTER TYPE %s ADD VALUE IF NOT EXISTS ''wip_release''', atttypid::regtype)
  FROM pg_attribute
 WHERE attrelid = 'journal_entries'::regclass AND attname = 'reference_type'
\gexec

BEGIN;

-- 2) tabel pelepasan WIP
CREATE TABLE IF NOT EXISTS project_wip_release (
    id              serial PRIMARY KEY,
    ref_no          varchar(50)   NOT NULL UNIQUE,
    release_date    date          NOT NULL,
    project_id      integer       NOT NULL REFERENCES projects(id),
    mode            varchar(10)   NOT NULL DEFAULT 'progress' CHECK (mode IN ('progress', 'complete')),
    progress_pct    numeric(5,2)  NOT NULL,
    cumulative_cost numeric(18,2) NOT NULL,   -- total WIP (jurnal issue) proyek saat pelepasan
    released_before numeric(18,2) NOT NULL,   -- total yang sudah dilepas sebelum pelepasan ini
    amount          numeric(18,2) NOT NULL,   -- nilai dilepas sekarang = cumulative x pct - released_before
    remark          text,
    created_by      integer,
    created_at      timestamp     NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_project_wip_release_project ON project_wip_release (project_id);

-- 3) aturan posting: Dr Beban Pokok (akun debit rule COGS) / Cr WIP (akun debit rule ISSUE_PROJECT)
INSERT INTO fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT 'WIP_RELEASE', 'Pelepasan WIP ke Beban Pokok Proyek (Dr HPP / Cr WIP)',
       (SELECT debit_account_id FROM fiscal_config WHERE rule_code = 'COGS'),
       (SELECT debit_account_id FROM fiscal_config WHERE rule_code = 'ISSUE_PROJECT'),
       true
WHERE NOT EXISTS (SELECT 1 FROM fiscal_config WHERE rule_code = 'WIP_RELEASE');

-- 4) menu: salin grup/departemen dari form Pembayaran Termin (id 145) + hak akses user yang sama
INSERT INTO m_form_name (group_id, seq, title, php_url, js_url, remark, department_id, main_table, tna, active)
SELECT group_id, seq, 'PELEPASAN WIP', 'project.wiprelease.php', 'project.wiprelease.js',
       'Pelepasan WIP ke HPP per progress / proyek selesai', department_id, 'project_wip_release', tna, true
  FROM m_form_name
 WHERE id = 145
   AND NOT EXISTS (SELECT 1 FROM m_form_name WHERE php_url = 'project.wiprelease.php');

INSERT INTO m_user_access_menu (user_id, form_id, access_level)
SELECT a.user_id, n.id, a.access_level
  FROM m_user_access_menu a
  JOIN m_form_name n ON n.php_url = 'project.wiprelease.php'
 WHERE a.form_id = 145
   AND NOT EXISTS (SELECT 1 FROM m_user_access_menu x WHERE x.user_id = a.user_id AND x.form_id = n.id);

COMMIT;

-- Cek hasil:
--   SELECT rule_code, debit_account_id, credit_account_id FROM fiscal_config WHERE rule_code IN ('WIP_RELEASE','ISSUE_PROJECT','COGS');
--   SELECT id, title, php_url FROM m_form_name WHERE php_url = 'project.wiprelease.php';
-- JANGAN jalankan SQL opsional "ubah ISSUE_PROJECT ke 5100" dari project_termin_revenue_account.sql bila memakai WIP.
