<?php
/**
 * fx.func.php
 * ============================================================
 * Fungsi bantu mata uang & kurs, dipakai bersama oleh RAB, PO, Planning, dashboard, dst.
 *
 *   fx_base_currency($db)                 -> ['id' => int|null, 'code' => 'IDR'|..., 'configured' => bool]
 *   fx_default_rate($db, $currencyId, $date)
 *                                         -> ['rate' => float|null, 'source' => 'BASE'|'periode'|'terakhir'|'none',
 *                                             'kmk_no', 'start_date', 'end_date', 'base_code']
 *
 * Mata uang dasar = Pengaturan Sistem (finance_settings). Belum diatur -> IDR.
 * Kurs default = kurs pajak KMK (tax_exchange_rate_*) pada tanggal transaksi; tanggal belum
 * tercakup -> kurs terakhir; tidak ada data -> rate null (isi manual).
 * Nilai kurs = mata uang dasar per 1 unit mata uang (rate_per_unit; bila kosong rate / unit_multiplier).
 * KMK berbasis IDR, jadi kurs otomatis hanya tersedia bila mata uang dasar = IDR.
 * ============================================================
 */

function fx_base_currency($db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $res = ['id' => null, 'code' => null, 'configured' => false];
    try {
        if ($db->query("SELECT to_regclass('public.finance_settings')")->fetchColumn()) {
            $r = $db->query("SELECT c.id, UPPER(TRIM(c.init)) AS code
                               FROM public.finance_settings f
                               JOIN public.m_currency c ON c.id = f.base_currency_id
                              WHERE f.id = 1")->fetch(PDO::FETCH_ASSOC);
            if ($r) return $cache = ['id' => (int)$r['id'], 'code' => $r['code'], 'configured' => true];
        }
        $r = $db->query("SELECT id FROM public.m_currency WHERE UPPER(TRIM(init)) = 'IDR' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($r) $res = ['id' => (int)$r['id'], 'code' => 'IDR', 'configured' => false];
    } catch (Throwable $e) {
    }
    return $cache = $res;
}

function fx_default_rate($db, int $currencyId, string $date): array {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) $date = date('Y-m-d');

    $base = fx_base_currency($db);
    $out  = ['rate' => null, 'source' => 'none', 'kmk_no' => null, 'start_date' => null, 'end_date' => null,
             'base_code' => $base['code']];

    if ($base['id'] !== null && $currencyId === $base['id']) {
        return ['rate' => 1.0, 'source' => 'BASE'] + $out;
    }
    if ($base['code'] !== null && $base['code'] !== 'IDR') {
        return $out;                                   // KMK = IDR per unit -> tidak bisa dipakai
    }

    $st = $db->prepare("SELECT UPPER(TRIM(init)) FROM public.m_currency WHERE id = :id");
    $st->execute([':id' => $currencyId]);
    $code = $st->fetchColumn();
    if (!$code) return $out;

    $sql = "SELECT COALESCE(d.rate_per_unit, d.rate / NULLIF(d.unit_multiplier, 0)) AS rate,
                   h.kmk_no, h.start_date, h.end_date
              FROM public.tax_exchange_rate_dtl d
              JOIN public.tax_exchange_rate_hdr h ON h.id = d.hdr_id
             WHERE (d.currency_id = :cid OR UPPER(d.currency_code) = :code)";

    $st = $db->prepare($sql . " AND CAST(:d AS date) BETWEEN h.start_date AND h.end_date
                                 ORDER BY h.start_date DESC, h.id DESC LIMIT 1");
    $st->execute([':cid' => $currencyId, ':code' => $code, ':d' => $date]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $source = 'periode';

    if (!$r || $r['rate'] === null) {
        $st = $db->prepare($sql . " ORDER BY h.end_date DESC, h.id DESC LIMIT 1");
        $st->execute([':cid' => $currencyId, ':code' => $code]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $source = 'terakhir';
    }
    if (!$r || $r['rate'] === null) return $out;

    return [
        'rate'       => round((float)$r['rate'], 4),
        'source'     => $source,
        'kmk_no'     => $r['kmk_no'],
        'start_date' => date('d/m/Y', strtotime($r['start_date'])),
        'end_date'   => date('d/m/Y', strtotime($r['end_date'])),
        'base_code'  => $base['code'],
    ];
}