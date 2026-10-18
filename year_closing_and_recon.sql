-- ============================================================
-- 2026-10-18.year_closing_and_recon.sql
-- 1) Tutup Buku Tahunan (jurnal penutup): tabel year_closing + menu
-- 2) Rekonsiliasi Persediaan: menu
-- Enum journal_entries.reference_type sudah punya nilai 'closing_entry' (tidak perlu ditambah).
-- Jalankan: psql -h localhost -U postgres -d proyekGMN -f year_closing_and_recon.sql   (aman diulang)
-- ============================================================
BEGIN;

CREATE TABLE IF NOT EXISTS year_closing (
    id                  serial PRIMARY KEY,
    fiscal_year         integer       NOT NULL UNIQUE,
    closing_date        date          NOT NULL,          -- 31 Desember tahun buku
    journal_id          bigint,
    journal_no          varchar(40),
    revenue_total       numeric(18,2) NOT NULL DEFAULT 0,
    expense_total       numeric(18,2) NOT NULL DEFAULT 0,
    net_income          numeric(18,2) NOT NULL DEFAULT 0,
    retained_account_id integer       NOT NULL,          -- akun ekuitas penampung laba (default 3200 Laba Ditahan)
    locked_periods      text,                            -- id periode yang dikunci oleh proses ini (dipakai saat buka kembali)
    remark              text,
    created_by          integer,
    created_at          timestamp     NOT NULL DEFAULT now()
);

-- Menu: salin grup/departemen dari form Pembayaran Termin (id 145) + hak akses user yang sama
INSERT INTO m_form_name (group_id, seq, title, php_url, js_url, remark, department_id, main_table, tna, active)
SELECT group_id, seq, 'TUTUP BUKU TAHUNAN', 'year.closing.php', 'year.closing.js',
       'Jurnal penutup tahunan: pendapatan & beban ke Laba Ditahan', department_id, 'year_closing', tna, true
  FROM m_form_name WHERE id = 145
   AND NOT EXISTS (SELECT 1 FROM m_form_name WHERE php_url = 'year.closing.php');

INSERT INTO m_form_name (group_id, seq, title, php_url, js_url, remark, department_id, main_table, tna, active)
SELECT group_id, seq, 'REKONSILIASI PERSEDIAAN', 'inventory.recon.php', 'inventory.recon.js',
       'Nilai buku (akun persediaan) vs nilai stok (stock_movements)', department_id, 'stock_movements', tna, true
  FROM m_form_name WHERE id = 145
   AND NOT EXISTS (SELECT 1 FROM m_form_name WHERE php_url = 'inventory.recon.php');

INSERT INTO m_user_access_menu (user_id, form_id, access_level)
SELECT a.user_id, n.id, a.access_level
  FROM m_user_access_menu a
  JOIN m_form_name n ON n.php_url IN ('year.closing.php', 'inventory.recon.php')
 WHERE a.form_id = 145
   AND NOT EXISTS (SELECT 1 FROM m_user_access_menu x WHERE x.user_id = a.user_id AND x.form_id = n.id);

COMMIT;

-- Cek:  SELECT id, title, php_url FROM m_form_name WHERE php_url IN ('year.closing.php','inventory.recon.php');
-- Setelah menjalankan SQL ini, login ulang agar menu baru muncul.
