-- ============================================================================
-- Migration: Remediasi Audit Skema (view rusak, status tanpa CHECK, FK hilang)
-- ----------------------------------------------------------------------------
-- Semua ALTER TABLE ... ADD CONSTRAINT di file ini memakai NOT VALID: PostgreSQL
-- akan membuat constraint-nya tapi TIDAK memindai baris yang sudah ada, jadi
-- aman dijalankan tanpa risiko gagal karena data lama yang mungkin melanggar.
-- Constraint tetap berlaku untuk INSERT/UPDATE baru mulai saat ini.
-- Setelah Anda cek data lama (lihat query verifikasi di bagian bawah tiap
-- constraint) dan yakin bersih, jalankan VALIDATE CONSTRAINT (bagian paling
-- bawah file) untuk mengunci penuh.
--
--   psql -h <host> -U <user> -d <db> -v ON_ERROR_STOP=1 -f migration_schema_remediation.sql
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1. view_grn_invoice_status: filter status = 'confirmed' TIDAK PERNAH
--    match, karena grn_hdr.status cuma boleh 'open'/'partial'/'closed'
--    (grn_hdr_status_check). Akibatnya invoiced_amount SELALU 0, dan
--    purchase_returnCRD.php > DeterminePostingBasis() SELALU menganggap GRN
--    belum diinvoice -- retur atas GRN yang sudah diinvoice salah mengurangi
--    GRN Clearing, bukan Hutang Usaha.
-- ----------------------------------------------------------------------------
-- Definisi di bawah disalin PERSIS dari pg_dump skema Anda -- hanya baris
-- WHERE paling akhir ("g.status = 'confirmed'") yang dibuang. Subquery
-- "pih.status <> 'cancelled'" dibiarkan apa adanya: ini perbandingan
-- <> (tidak sama dengan), jadi tetap aman/tidak pernah salah mengecualikan
-- baris walau tidak ada status 'cancelled' di purchase_invoice_hdr.
CREATE OR REPLACE VIEW public.view_grn_invoice_status AS
 SELECT g.id AS grn_hdr_id,
    g.ref_no AS grn_ref_no,
    g.grn_date,
    g.po_id,
    g.supplier_id,
    COALESCE(gt.total_amount, (0)::numeric) AS grn_total,
    COALESCE(it.invoiced_amount, (0)::numeric) AS invoiced_amount,
    (COALESCE(gt.total_amount, (0)::numeric) - COALESCE(it.invoiced_amount, (0)::numeric)) AS outstanding_amount
   FROM ((public.grn_hdr g
     LEFT JOIN ( SELECT grn_dtl.grn_hdr_id,
            sum((grn_dtl.qty_received * grn_dtl.unit_cost)) AS total_amount
           FROM public.grn_dtl
          GROUP BY grn_dtl.grn_hdr_id) gt ON ((gt.grn_hdr_id = g.id)))
     LEFT JOIN ( SELECT pid.grn_hdr_id,
            sum(pid.matched_amount) AS invoiced_amount
           FROM (public.purchase_invoice_dtl pid
             JOIN public.purchase_invoice_hdr pih ON ((pih.id = pid.invoice_hdr_id)))
          WHERE ((pih.status)::text <> 'cancelled'::text)
          GROUP BY pid.grn_hdr_id) it ON ((it.grn_hdr_id = g.id)));
-- [FIX] baris "WHERE ((g.status)::text = 'confirmed'::text);" di akhir
-- definisi asli DIHAPUS -- itulah satu-satunya perubahan di bagian ini.


-- ----------------------------------------------------------------------------
-- 2. purchase_invoice_hdr.status & material_out_header.status: TIDAK PUNYA
--    CHECK constraint sama sekali, beda dari tabel dokumen lain (grn_hdr,
--    purchase_orders, wh_receiving_hdr, material_receipt_header, projects)
--    yang semuanya dibatasi. Disamakan ke pola wh_receiving_hdr karena
--    kedua tabel ini juga punya kolom reversed_at/reversed_by (kecuali
--    purchase_invoice_hdr, yang saat ini hanya pernah diisi 'posted' oleh
--    kode berjalan -- 'reversed' ditambahkan sebagai nilai cadangan yang
--    konsisten dengan konvensi tabel lain, bukan karena sudah dipakai).
-- ----------------------------------------------------------------------------
ALTER TABLE public.purchase_invoice_hdr
    ADD CONSTRAINT purchase_invoice_hdr_status_check
    CHECK (((status)::text = ANY ((ARRAY['posted','reversed'])::text[]))) NOT VALID;

ALTER TABLE public.material_out_header
    ADD CONSTRAINT material_out_header_status_check
    CHECK (((status)::text = ANY ((ARRAY['posted','reversed'])::text[]))) NOT VALID;


-- ----------------------------------------------------------------------------
-- 3. FK yang hilang -- HANYA yang levelnya percaya diri tinggi (target tabel
--    jelas & relevan dengan modul yang sudah dibangun). Kolom *_id lain yang
--    dangling tapi targetnya ambigu (mis. department_section.warehouse_id,
--    item_group.type_id, m_city.province_id -- provinsi tidak ada tabelnya
--    sama sekali, m_project_budget.project_id -- kemungkinan besar bagian
--    sistem project lama m_projects, bukan projects aktif) SENGAJA TIDAK
--    disentuh di sini -- lihat ringkasan di jawaban chat.
-- ----------------------------------------------------------------------------

-- 3a. grade_id -> item_grade(id): dipakai GRN, WH Rcv, dan stock_movements
--     (langsung memengaruhi PostStockMovement() & GetStockBalance()).
ALTER TABLE public.grn_dtl
    ADD CONSTRAINT grn_dtl_grade_id_fkey
    FOREIGN KEY (grade_id) REFERENCES public.item_grade(id) NOT VALID;

ALTER TABLE public.wh_receiving_dtl
    ADD CONSTRAINT wh_receiving_dtl_grade_id_fkey
    FOREIGN KEY (grade_id) REFERENCES public.item_grade(id) NOT VALID;

ALTER TABLE public.stock_movements
    ADD CONSTRAINT stock_movements_grade_id_fkey
    FOREIGN KEY (grade_id) REFERENCES public.item_grade(id) NOT VALID;

-- 3b. m_bin_location.warehouse_id -> warehouses(id): dipakai langsung di
--     whrcvCRD.php (list_bin_locations, wh_receiving_dtl.bin_location_id).
ALTER TABLE public.m_bin_location
    ADD CONSTRAINT m_bin_location_warehouse_id_fkey
    FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id) NOT VALID;

-- 3c. m_address / m_contact_no / m_contact_person: masing-masing polimorfik
--     (customer_id ATAU supplier_id ATAU agent_id diisi salah satu, sisanya
--     NULL) -- FK nullable aman ditambahkan tanpa mengubah data.
ALTER TABLE public.m_address
    ADD CONSTRAINT m_address_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.m_customer(id) NOT VALID,
    ADD CONSTRAINT m_address_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.m_suppliers(id) NOT VALID;

ALTER TABLE public.m_contact_no
    ADD CONSTRAINT m_contact_no_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.m_customer(id) NOT VALID,
    ADD CONSTRAINT m_contact_no_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.m_suppliers(id) NOT VALID;

ALTER TABLE public.m_contact_person
    ADD CONSTRAINT m_contact_person_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.m_customer(id) NOT VALID,
    ADD CONSTRAINT m_contact_person_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.m_suppliers(id) NOT VALID;


-- ----------------------------------------------------------------------------
-- 4. BUG AKTIF, BUKAN SEKADAR RISIKO: if_modified_func() (trigger audit log
--    di m_suppliers, m_customer, item_grade, item_group, items, m_address,
--    m_contact_no, m_contact_person, m_city, m_country, m_currency,
--    m_district, m_region, m_village, m_agent, m_color, m_unit,
--    customer_npwp) memanggil current_setting('app.username') TANPA nilai
--    default. function.all.php PUNYA baris untuk mengisi app.username, tapi
--    dua barisnya dikomentari (// SET app.username=...). Akibatnya SETIAP
--    INSERT/UPDATE/DELETE ke tabel-tabel di atas gagal dengan:
--      ERROR: unrecognized configuration parameter "app.username"
--    Sudah saya buktikan reproduce persis di database uji dari dump Anda.
--
--    PERBAIKAN WAJIB DI KODE PHP (bukan cuma di database):
--    Buka function.all.php baris ~17-18, hapus tanda komentar "//":
--        $sqlSetUsername = "SET app.username='" . pg_escape_string($conn, $_SESSION["username"] ?? '') . "'";
--        $outputSetUsername = pg_query($conn, $sqlSetUsername) or die('Connection failed: ' . pg_last_error($conn));
--
--    Baris di bawah ini adalah JARING PENGAMAN di level database: memberi
--    nilai default '' supaya current_setting() tidak pernah gagal walau ada
--    jalur kode lain (cron, migration, query manual) yang belum sempat
--    memanggil SET app.username -- TIDAK menggantikan perbaikan PHP di atas,
--    karena tanpa perbaikan PHP, app_user_name di log akan selalu kosong.
-- ----------------------------------------------------------------------------
ALTER DATABASE proyekGMN SET app.username = '';


-- ============================================================================
-- VALIDASI (jalankan terpisah, SETELAH Anda cek data lama tidak melanggar)
-- ----------------------------------------------------------------------------
-- Cek dulu apakah ada baris yang akan gagal, misalnya:
--   SELECT id, grade_id FROM grn_dtl
--    WHERE grade_id IS NOT NULL
--      AND grade_id NOT IN (SELECT id FROM item_grade);
-- Kalau hasilnya kosong untuk semua constraint di bawah, baru jalankan blok ini:
--
-- ALTER TABLE public.purchase_invoice_hdr VALIDATE CONSTRAINT purchase_invoice_hdr_status_check;
-- ALTER TABLE public.material_out_header  VALIDATE CONSTRAINT material_out_header_status_check;
-- ALTER TABLE public.grn_dtl              VALIDATE CONSTRAINT grn_dtl_grade_id_fkey;
-- ALTER TABLE public.wh_receiving_dtl     VALIDATE CONSTRAINT wh_receiving_dtl_grade_id_fkey;
-- ALTER TABLE public.stock_movements      VALIDATE CONSTRAINT stock_movements_grade_id_fkey;
-- ALTER TABLE public.m_bin_location       VALIDATE CONSTRAINT m_bin_location_warehouse_id_fkey;
-- ALTER TABLE public.m_address            VALIDATE CONSTRAINT m_address_customer_id_fkey;
-- ALTER TABLE public.m_address            VALIDATE CONSTRAINT m_address_supplier_id_fkey;
-- ALTER TABLE public.m_contact_no         VALIDATE CONSTRAINT m_contact_no_customer_id_fkey;
-- ALTER TABLE public.m_contact_no         VALIDATE CONSTRAINT m_contact_no_supplier_id_fkey;
-- ALTER TABLE public.m_contact_person     VALIDATE CONSTRAINT m_contact_person_customer_id_fkey;
-- ALTER TABLE public.m_contact_person     VALIDATE CONSTRAINT m_contact_person_supplier_id_fkey;
