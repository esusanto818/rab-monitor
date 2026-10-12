-- No. Surat Jalan otomatis (SJ-YYMM-NNNNN) memakai kolom yang sudah ada: material_out_header.delivery_note_no
-- Tidak ada kolom baru. Index unik ini hanya mengamankan nomor berformat SJ- dari duplikat.

-- 1) CEK DULU: harus 0 baris (bila ada, rapikan nomor manual lama yang kembar sebelum membuat index)
SELECT delivery_note_no, COUNT(*) FROM material_out_header
 WHERE delivery_note_no LIKE 'SJ-%' GROUP BY delivery_note_no HAVING COUNT(*) > 1;

-- 2) Index unik (hanya untuk nomor berawalan SJ-; nomor manual lama format lain tidak terpengaruh)
CREATE UNIQUE INDEX IF NOT EXISTS ux_mat_out_hdr_sj_no
    ON material_out_header (delivery_note_no) WHERE delivery_note_no LIKE 'SJ-%';
