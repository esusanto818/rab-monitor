<?php
/**
 * settings.func.php
 * ============================================================
 * Fungsi bantu untuk membaca Pengaturan Sistem dari modul lain
 * (cetak PO / invoice / laporan keuangan, dashboard, dst).
 *
 * Pemakaian:
 *     require_once __DIR__ . '/../php-func/settings.func.php';
 *     $co   = get_company_profile($db);            // nama, alamat, phone, email, footer, penandatangan ...
 *     $fin  = get_finance_settings($db);           // mata uang dasar & laporan, jenis kurs, desimal ...
 *     $logo = company_logo_data_uri($db, 'document');   // untuk <img src="..."> / TCPDF / Dompdf
 *
 * Semua fungsi memakai $db = new crud() (PDO) dan di-cache per request.
 * Aman dipanggil sebelum migrasi dijalankan (mengembalikan nilai kosong, tidak error).
 * ============================================================
 */

/** Profil perusahaan (tanpa data biner logo). Selalu mengembalikan array dengan kunci lengkap. */
function get_company_profile($db): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $empty = [
        'company_name' => '', 'legal_name' => '', 'address' => '', 'city' => '', 'province' => '',
        'postal_code' => '', 'country' => '', 'phone' => '', 'email' => '', 'website' => '', 'tax_id' => '',
        'doc_footer' => '', 'doc_signer_name' => '', 'doc_signer_title' => '',
        'has_logo_document' => false, 'has_logo_app' => false,
    ];
    try {
        if (!$db->query("SELECT to_regclass('public.company_profile')")->fetchColumn()) {
            return $cache = $empty;
        }
        $row = $db->query("
            SELECT company_name, legal_name, address, city, province, postal_code, country, phone, email,
                   website, tax_id, doc_footer, doc_signer_name, doc_signer_title,
                   (logo_document IS NOT NULL) AS has_logo_document, (logo_app IS NOT NULL) AS has_logo_app
              FROM public.company_profile WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $cache = $empty;
        foreach ($row as $k => $v) {
            $row[$k] = ($k === 'has_logo_document' || $k === 'has_logo_app')
                ? ($v === true || $v === 't' || $v === '1')
                : (string)($v ?? '');
        }
        return $cache = $row;
    } catch (Throwable $e) {
        return $cache = $empty;
    }
}

/**
 * Pengaturan keuangan. Mengembalikan null bila belum diatur.
 * Kunci: base_currency_id, base_currency (init), report_currency_id, report_currency (init),
 *        default_rate_type, rate_basis_pl, fiscal_year_start_month, amount_decimals.
 */
function get_finance_settings($db): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    try {
        if (!$db->query("SELECT to_regclass('public.finance_settings')")->fetchColumn()) {
            return $cache = null;
        }
        $row = $db->query("
            SELECT f.base_currency_id, UPPER(TRIM(b.init)) AS base_currency,
                   f.report_currency_id, UPPER(TRIM(r.init)) AS report_currency,
                   f.default_rate_type, f.rate_basis_pl, f.fiscal_year_start_month, f.amount_decimals
              FROM public.finance_settings f
              JOIN public.m_currency b ON b.id = f.base_currency_id
              JOIN public.m_currency r ON r.id = f.report_currency_id
             WHERE f.id = 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $cache = null;
        foreach (['base_currency_id', 'report_currency_id', 'fiscal_year_start_month', 'amount_decimals'] as $k) {
            $row[$k] = (int)$row[$k];
        }
        return $cache = $row;
    } catch (Throwable $e) {
        return $cache = null;
    }
}

/** Id mata uang dasar (pembukuan), atau null bila belum diatur. */
function get_base_currency_id($db): ?int {
    $f = get_finance_settings($db);
    return $f ? $f['base_currency_id'] : null;
}

/** Id mata uang laporan keuangan final, atau null bila belum diatur. */
function get_report_currency_id($db): ?int {
    $f = get_finance_settings($db);
    return $f ? $f['report_currency_id'] : null;
}

/**
 * Logo sebagai data URI base64 (siap dipakai di HTML cetak / PDF tanpa request tambahan).
 * $type: 'document' (kop dokumen) atau 'app' (header aplikasi). String kosong bila belum ada logo.
 */
function company_logo_data_uri($db, string $type = 'document'): string {
    static $cache = [];
    $cols = ['document' => 'logo_document', 'app' => 'logo_app'];
    if (!isset($cols[$type])) return '';
    if (isset($cache[$type])) return $cache[$type];

    try {
        if (!$db->query("SELECT to_regclass('public.company_profile')")->fetchColumn()) return $cache[$type] = '';
        $col = $cols[$type];
        $st = $db->prepare("SELECT {$col}, {$col}_mime FROM public.company_profile WHERE id = 1");
        $st->execute();
        $st->bindColumn(1, $img, PDO::PARAM_LOB);
        $st->bindColumn(2, $mime);
        if (!$st->fetch(PDO::FETCH_BOUND) || $img === null) return $cache[$type] = '';
        $bin = is_resource($img) ? stream_get_contents($img) : $img;
        if (is_string($bin) && strncmp($bin, '\\x', 2) === 0) $bin = hex2bin(substr($bin, 2));
        if (!$bin || !in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) return $cache[$type] = '';
        return $cache[$type] = 'data:' . $mime . ';base64,' . base64_encode($bin);
    } catch (Throwable $e) {
        return $cache[$type] = '';
    }
}

/** Format angka sesuai jumlah desimal pengaturan keuangan (default 2). */
function format_amount($value, $db = null): string {
    $dec = 2;
    if ($db) {
        $f = get_finance_settings($db);
        if ($f) $dec = $f['amount_decimals'];
    }
    return number_format((float)$value, $dec, ',', '.');
}