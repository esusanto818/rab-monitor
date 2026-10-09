-- ============================================================================
-- 2026-10-01.settings.sql  --  Pengaturan sistem: profil perusahaan & keuangan
-- Jalankan SEKALI. Aman diulang (IF NOT EXISTS / ON CONFLICT DO NOTHING).
--
--   company_profile  : satu baris (id = 1). Nama, alamat, kontak, logo, data untuk kop dokumen.
--   finance_settings : satu baris (id = 1). Mata uang dasar (pembukuan) & mata uang laporan keuangan final.
--
-- Transaksi (RAB, PO, dst) boleh multi currency; LAPORAN KEUANGAN FINAL mengacu ke finance_settings.
-- ============================================================================
BEGIN;

-- Pastikan IDR ada di master mata uang
INSERT INTO m_currency (init, name)
SELECT 'IDR', 'Rupiah Indonesia'
 WHERE NOT EXISTS (SELECT 1 FROM m_currency WHERE UPPER(TRIM(init)) = 'IDR');

CREATE TABLE IF NOT EXISTS company_profile (
    id                        smallint     PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    company_name              varchar(150) NOT NULL DEFAULT '',
    legal_name                varchar(200),
    address                   text,
    city                      varchar(100),
    province                  varchar(100),
    postal_code               varchar(20),
    country                   varchar(100) DEFAULT 'Indonesia',
    phone                     varchar(50),
    email                     varchar(150),
    website                   varchar(150),
    tax_id                    varchar(50),                 -- NPWP
    -- Logo disimpan di database (ikut ter-backup). Hanya PNG/JPEG/WEBP, maks 1 MB (divalidasi aplikasi).
    logo_document             bytea,                       -- kop surat PO / invoice / dokumen cetak
    logo_document_mime        varchar(50),
    logo_document_updated_at  timestamp,
    logo_app                  bytea,                       -- header aplikasi / login
    logo_app_mime             varchar(50),
    logo_app_updated_at       timestamp,
    -- Pelengkap dokumen
    doc_footer                text,
    doc_signer_name           varchar(150),
    doc_signer_title          varchar(100),
    updated_at                timestamp    NOT NULL DEFAULT now(),
    updated_by                integer
);

CREATE TABLE IF NOT EXISTS finance_settings (
    id                       smallint    PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    base_currency_id         bigint      NOT NULL REFERENCES m_currency(id),   -- mata uang pembukuan (jurnal, FIFO, stok)
    report_currency_id       bigint      NOT NULL REFERENCES m_currency(id),   -- mata uang laporan keuangan final
    default_rate_type        varchar(10) NOT NULL DEFAULT 'KMK'
                             CHECK (default_rate_type IN ('KMK', 'BI', 'MANUAL')),
    rate_basis_pl            varchar(12) NOT NULL DEFAULT 'AVERAGE'
                             CHECK (rate_basis_pl IN ('TRANSACTION', 'AVERAGE', 'PERIOD_END')),
    fiscal_year_start_month  smallint    NOT NULL DEFAULT 1 CHECK (fiscal_year_start_month BETWEEN 1 AND 12),
    amount_decimals          smallint    NOT NULL DEFAULT 2 CHECK (amount_decimals BETWEEN 0 AND 4),
    updated_at               timestamp   NOT NULL DEFAULT now(),
    updated_by               integer
);

-- Baris awal
INSERT INTO company_profile (id) VALUES (1) ON CONFLICT (id) DO NOTHING;

INSERT INTO finance_settings (id, base_currency_id, report_currency_id)
SELECT 1, c.id, c.id
  FROM m_currency c
 WHERE UPPER(TRIM(c.init)) = 'IDR'
 ORDER BY c.id
 LIMIT 1
ON CONFLICT (id) DO NOTHING;

COMMIT;

-- Registrasi menu (sesuaikan group_id / seq; biasanya di grup V. UTILITY), lalu beri akses user:
-- INSERT INTO m_form_name (group_id, seq, title, php_url, main_table, active)
-- VALUES (<group_id_utility>, '<seq>', 'PENGATURAN SISTEM', 'settings.php', 'company_profile', true);
-- INSERT INTO m_user_access_menu (user_id, form_id, access_level)
-- SELECT <user_id>, id, 4 FROM m_form_name WHERE title = 'PENGATURAN SISTEM';

-- Verifikasi
SELECT id, company_name FROM company_profile;
SELECT f.id, b.init AS base, r.init AS report FROM finance_settings f
  JOIN m_currency b ON b.id = f.base_currency_id JOIN m_currency r ON r.id = f.report_currency_id;
