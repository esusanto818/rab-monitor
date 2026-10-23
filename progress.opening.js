-- SALDO AWAL progress (opsional): proyek yang progress-nya diketik manual sebelum modul verifikasi ada
-- dicatat sebagai satu baris 'Diverifikasi' bertanda legacy, supaya riwayat klaim tidak kosong dan klaim berikutnya
-- (harus lebih besar dari angka ini) punya acuan yang jelas.
-- LIHAT DULU proyek mana yang terpengaruh:
SELECT id, name, status, progress FROM projects WHERE COALESCE(progress, 0) > 0 ORDER BY id;

INSERT INTO project_progress_log
       (project_id, report_date, percent_claimed, prev_percent, reported_by, note, evidence_ref, status, verified_at, verify_note)
SELECT p.id, CURRENT_DATE, p.progress, 0, NULL,
       'Saldo awal: progress diinput manual sebelum modul verifikasi', 'LEGACY', 'Diverifikasi', NOW(),
       'Belum diverifikasi lapangan - tinjau ulang bila angka tidak sesuai'
  FROM projects p
 WHERE COALESCE(p.progress, 0) > 0
   AND NOT EXISTS (SELECT 1 FROM project_progress_log l WHERE l.project_id = p.id);

-- BILA ANGKA LEGACY TIDAK DIPERCAYA (mis. 92% padahal biaya baru 17%), turunkan ke angka yang benar lewat SQL ini
-- (satu-satunya cara menurunkan progress; klaim di aplikasi hanya bisa menaikkan):
--   UPDATE projects SET progress = <angka_benar> WHERE id = <id_proyek>;
