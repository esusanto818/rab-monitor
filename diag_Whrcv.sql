-- Cek langsung: header WHR ini benar-benar tidak punya baris detail, atau
-- cuma tidak tampil di layar?
SELECT h.id, h.ref_no, h.status,
       (SELECT COUNT(*) FROM wh_receiving_dtl d WHERE d.wh_receiving_hdr_id = h.id) AS jumlah_baris_detail,
       (SELECT COALESCE(SUM(qty_received*unit_cost),0) FROM wh_receiving_dtl d WHERE d.wh_receiving_hdr_id = h.id) AS total_nilai
FROM wh_receiving_hdr h
WHERE h.ref_no IN ('WHR-2609-00001','WHR-2609-00002');

-- Kalau jumlah_baris_detail = 0 di kedua baris, datanya memang tidak pernah
-- tersimpan (bukan bug tampilan) -- lanjut cek stock_movements & journal_entries
-- terkait, untuk lihat separuh mana yang sempat kesimpan:
SELECT 'stock_movements' AS sumber, count(*) FROM stock_movements
 WHERE reference_type='wh_receiving' AND reference_id IN (SELECT id FROM wh_receiving_hdr WHERE ref_no IN ('WHR-2609-00001','WHR-2609-00002'))
UNION ALL
SELECT 'journal_entries', count(*) FROM journal_entries
 WHERE reference_type='wh_receiving' AND reference_id IN (SELECT id FROM wh_receiving_hdr WHERE ref_no IN ('WHR-2609-00001','WHR-2609-00002'));
