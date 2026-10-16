-- 2026-10-11b.return_dispatch_flow.sql
-- Alur Retur Supplier: Diajukan -> Diperintahkan -> Disiapkan -> Dikirim -> Diganti | Kredit
--   Perintah issue (pending) dibuat Pembelian; gudang menandai Siap Kirim (prepared) lalu Konfirmasi Dikirim (posted).
--   Stok keluar FIFO + jurnal baru dibuat saat Konfirmasi Dikirim.
-- Jalankan statement satu per satu (psql). Bagian 2 mencetak NOTICE nama constraint yang di-drop: catat.

-- 1. Kolom konfirmasi di dokumen issue W/H
ALTER TABLE material_out_header
    ADD COLUMN IF NOT EXISTS prepared_at       timestamp,
    ADD COLUMN IF NOT EXISTS prepared_by       integer,
    ADD COLUMN IF NOT EXISTS delivery_date     date,
    ADD COLUMN IF NOT EXISTS delivery_note_no  varchar(50),
    ADD COLUMN IF NOT EXISTS delivery_receiver varchar(100),
    ADD COLUMN IF NOT EXISTS delivery_proof    text;

-- 2. Lepas CHECK constraint lama pada kolom status (bila ada), lalu pasang yang baru.
DO $$
DECLARE r record;
BEGIN
    FOR r IN
        SELECT conrelid::regclass AS t, conname, pg_get_constraintdef(oid) AS def
          FROM pg_constraint
         WHERE contype = 'c'
           AND conrelid IN ('material_out_header'::regclass, 'supplier_return_hdr'::regclass)
           AND pg_get_constraintdef(oid) ILIKE '%status%'
    LOOP
        RAISE NOTICE 'DROP % : % -> %', r.t, r.conname, r.def;
        EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I', r.t, r.conname);
    END LOOP;
END $$;

ALTER TABLE material_out_header
    ADD CONSTRAINT chk_material_out_status
    CHECK (status IN ('pending','prepared','posted','reversed','cancelled'));

ALTER TABLE supplier_return_hdr
    ADD CONSTRAINT chk_supplier_return_status
    CHECK (status IN ('Diajukan','Diperintahkan','Disiapkan','Dikirim','Diganti','Kredit','Dibatalkan'));

-- 3. Verifikasi
SELECT column_name FROM information_schema.columns
 WHERE table_name = 'material_out_header'
   AND column_name IN ('prepared_at','prepared_by','delivery_date','delivery_note_no','delivery_receiver','delivery_proof');
SELECT conrelid::regclass AS tabel, conname, pg_get_constraintdef(oid)
  FROM pg_constraint
 WHERE contype = 'c' AND conname IN ('chk_material_out_status','chk_supplier_return_status');
