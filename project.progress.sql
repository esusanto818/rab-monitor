-- MODUL PROGRESS PROYEK (level proyek): log klaim progress + verifikasi
-- Progress proyek (projects.progress) hanya berubah lewat verifikasi klaim di project.progressCRD.php
CREATE TABLE IF NOT EXISTS project_progress_log (
    id              serial PRIMARY KEY,
    project_id      integer      NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    report_date     date         NOT NULL,
    percent_claimed smallint     NOT NULL CHECK (percent_claimed BETWEEN 0 AND 100),
    prev_percent    smallint     NOT NULL DEFAULT 0,          -- progress terverifikasi saat klaim dibuat
    reported_by     integer,                                  -- m_user_system.id (session user_id)
    note            text,
    evidence_ref    varchar(255),                             -- no. foto / BAP / link bukti
    status          varchar(20)  NOT NULL DEFAULT 'Diajukan'
                    CHECK (status IN ('Diajukan', 'Diverifikasi', 'Ditolak', 'Dibatalkan')),
    verified_by     integer,
    verified_at     timestamp,
    verify_note     text,
    snap_budget_base numeric(18,2),                           -- jejak audit saat diverifikasi (mata uang dasar)
    snap_cost_base   numeric(18,2),
    snap_cpi         numeric(8,4),
    created_at      timestamp    NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_ppl_project ON project_progress_log (project_id, status, report_date);
-- hanya satu klaim yang boleh menunggu verifikasi per proyek
CREATE UNIQUE INDEX IF NOT EXISTS ux_ppl_one_pending ON project_progress_log (project_id) WHERE status = 'Diajukan';

-- ------------------------------------------------------------------
-- DAFTARKAN MENU (sesuaikan dengan pola menu yang sudah ada)
-- 1) Lihat baris menu PO sebagai contoh:
--      SELECT * FROM m_form_name WHERE php_url ILIKE '%po%' OR title ILIKE '%purchase order%';
-- 2) Buat baris baru dengan nilai yang sama polanya, mis.:
--      INSERT INTO m_form_name (group_id, seq, title, php_url, js_url, remark, department_id, main_table, tna, active)
--      VALUES (<group_id seperti PO>, '<urutan>', 'Progress Proyek', '<pola seperti PO: ...project.progress.php>',
--              '<pola seperti PO: ...project.progress.js>', 'Klaim & verifikasi progress proyek', NULL, 'project_progress_log', false, true);
-- 3) Beri hak akses (m_user_access_menu: user_id, form_id, access_level). Level 2 = lapor progress, level 3 = verifikasi.
-- ------------------------------------------------------------------
