-- ============================================================
-- 2026-10-08.grn_vehicle_driver.sql
-- GRN: tambah Plat No. Kendaraan & Nama Driver (untuk Tanda Terima GRN)
-- Aman dijalankan berulang (IF NOT EXISTS).
-- ============================================================
BEGIN;

ALTER TABLE public.grn_hdr
    ADD COLUMN IF NOT EXISTS vehicle_plate_no character varying(20),
    ADD COLUMN IF NOT EXISTS driver_name      character varying(100);

COMMENT ON COLUMN public.grn_hdr.vehicle_plate_no IS 'Plat nomor kendaraan pengirim barang (huruf besar, tanpa pemisah ganda)';
COMMENT ON COLUMN public.grn_hdr.driver_name      IS 'Nama driver / pengirim barang';

COMMIT;
