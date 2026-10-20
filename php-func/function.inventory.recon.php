<?php
/**
 * function.inventory.recon.php  [NEW 2026-10-18]
 * ------------------------------------------------------------------
 * Logika rekonsiliasi persediaan: nilai BUKU (saldo akun persediaan di buku besar) vs nilai STOK
 * (ledger stock_movements, IN - OUT bernilai FIFO), sampai tanggal tertentu.
 * Dipakai bersama oleh php-crud/inventory.reconCRD.php (layar) dan pdf/inventoryrecon.php (cetak).
 *
 * Pemeriksaan dokumen: setiap dokumen stok (reference_type + reference_id di stock_movements) dicocokkan dengan jurnalnya:
 *   - MATERIAL_OUT, issue_type PROJECT          -> jurnal 'material_out'     (reference_id = id issue)
 *   - MATERIAL_OUT, issue_type SUPPLIER_RETURN  -> jurnal 'supplier_return'  (reference_id = id retur supplier)
 *   - jenis lain                                -> jurnal dengan reference_type sama (huruf kecil) bila nilai enum-nya ada
 *   Status dokumen: OK | TIDAK ADA JURNAL | NILAI BEDA | N/A (jenis dokumen tidak punya jenis jurnal)
 *
 * Selisih (buku - stok) dijelaskan oleh dokumen bermasalah:
 *   dokumen tanpa jurnal  : buku kurang  nilai_stok_dokumen      -> selisih += -nilai
 *   dokumen nilai beda    : buku beda sign x (jurnal - |nilai|)  -> selisih += sign x (jurnal - |nilai|)
 *   Sisa yang tidak terjelaskan biasanya jurnal manual / penyesuaian langsung di akun persediaan.
 */

const IR_TOL = 1.00;      // toleransi pembulatan nilai dokumen vs jurnal (Rp)

/** Akun persediaan default = akun kredit rule ISSUE_PROJECT di fiscal_config (Persediaan Bahan Baku). */
function ir_default_account($db): int {
    $st = $db->query("SELECT credit_account_id FROM public.fiscal_config WHERE rule_code = 'ISSUE_PROJECT' AND is_active = true ORDER BY id DESC LIMIT 1");
    return (int)$st->fetchColumn();
}

function ir_accounts($db): array {
    // [AKUN] Hanya akun persediaan utama (akun kredit rule ISSUE_PROJECT, mis. 1500). Akun aset lain (WIP, Barang Jadi, Bank, dst.)
    //        tidak memakai ledger stok sebagai pembanding, jadi tidak ditawarkan agar tidak salah pilih.
    //        Bila rule belum diatur, tampilkan akun yang namanya memuat 'persediaan'.
    $def = ir_default_account($db);
    if ($def > 0) {
        $st = $db->prepare("SELECT id, account_code, account_name FROM public.chart_of_accounts WHERE id = :id");
        $st->execute([':id' => $def]);
    } else {
        $st = $db->query("SELECT id, account_code, account_name FROM public.chart_of_accounts
                           WHERE account_name ILIKE '%persediaan%' AND is_active = true ORDER BY account_code");
    }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => (int)$r['id'], 'label' => $r['account_code'] . ' - ' . $r['account_name'], 'is_default' => ((int)$r['id'] === $def || $def <= 0)];
    }
    return $out;
}

/**
 * Hitung rekonsiliasi. Mengembalikan ['summary'=>[], 'items'=>[], 'warehouses'=>[], 'docs'=>[]].
 * Melempar Exception bila tanggal / akun tidak valid.
 */
function ir_reconcile($db, string $asOf, int $accountId): array {
    $d = DateTime::createFromFormat('Y-m-d', $asOf);
    if (!$d || $d->format('Y-m-d') !== $asOf) throw new Exception('Tanggal tidak valid.');
    if ($accountId <= 0) $accountId = ir_default_account($db);
    $st = $db->prepare("SELECT account_code, account_name FROM public.chart_of_accounts WHERE id = :id");
    $st->execute([':id' => $accountId]);
    $acc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$acc) throw new Exception('Akun persediaan tidak ditemukan. Atur rule ISSUE_PROJECT di fiscal_config atau pilih akun.');

    // --- nilai buku: saldo debit - kredit akun persediaan (jurnal posted/reversed) s/d tanggal
    $st = $db->prepare("
        SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
          FROM public.journal_lines jl
          JOIN public.journal_entries je ON je.id = jl.journal_id
               AND je.status::text IN ('posted', 'reversed') AND je.journal_date <= CAST(:d AS date)
         WHERE jl.account_id = :a");
    $st->execute([':d' => $asOf, ':a' => $accountId]);
    $book = round((float)$st->fetchColumn(), 2);

    // --- nilai stok: IN - OUT (nilai FIFO per movement)
    $VAL = "CASE WHEN sm.movement_type = 'IN' THEN sm.qty * sm.unit_cost ELSE -sm.qty * sm.unit_cost END";
    $QTY = "CASE WHEN sm.movement_type = 'IN' THEN sm.qty ELSE -sm.qty END";

    $st = $db->prepare("SELECT COALESCE(SUM({$VAL}), 0) FROM public.stock_movements sm WHERE sm.movement_date <= CAST(:d AS date)");
    $st->execute([':d' => $asOf]);
    $stock = round((float)$st->fetchColumn(), 2);

    // --- per item
    $st = $db->prepare("
        SELECT m.id, m.material_code, m.material_name, m.unit, SUM({$QTY}) AS qty, SUM({$VAL}) AS val
          FROM public.stock_movements sm
          JOIN public.materials m ON m.id = sm.item_id
         WHERE sm.movement_date <= CAST(:d AS date)
         GROUP BY m.id, m.material_code, m.material_name, m.unit
        HAVING ABS(SUM({$QTY})) > 0.00005 OR ABS(SUM({$VAL})) > 0.004
         ORDER BY m.material_name");
    $st->execute([':d' => $asOf]);
    $items = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $q = (float)$r['qty']; $v = (float)$r['val'];
        $items[] = ['material_code' => $r['material_code'], 'material_name' => $r['material_name'], 'unit' => $r['unit'],
                    'qty' => round($q, 4), 'value' => round($v, 2), 'avg_cost' => (abs($q) > 0.00005) ? round($v / $q, 2) : 0.0];
    }

    // --- per gudang (jumlah item bersaldo + nilai)
    $st = $db->prepare("
        SELECT w.id AS wid, w.name AS wname, sm.item_id, SUM({$QTY}) AS qty, SUM({$VAL}) AS val
          FROM public.stock_movements sm
          JOIN public.warehouses w ON w.id = sm.warehouse_id
         WHERE sm.movement_date <= CAST(:d AS date)
         GROUP BY w.id, w.name, sm.item_id");
    $st->execute([':d' => $asOf]);
    $wh = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = (int)$r['wid'];
        if (!isset($wh[$k])) $wh[$k] = ['warehouse_name' => $r['wname'], 'item_count' => 0, 'value' => 0.0];
        if (abs((float)$r['qty']) > 0.00005) $wh[$k]['item_count']++;
        $wh[$k]['value'] += (float)$r['val'];
    }
    $warehouses = [];
    foreach ($wh as $w) { $warehouses[] = ['warehouse_name' => $w['warehouse_name'], 'item_count' => $w['item_count'], 'value' => round($w['value'], 2)]; }
    usort($warehouses, function ($a, $b) { return strcmp($a['warehouse_name'], $b['warehouse_name']); });

    // --- pemeriksaan dokumen vs jurnal
    $labels = $db->query("SELECT e.enumlabel FROM pg_enum e WHERE e.enumtypid =
                           (SELECT atttypid FROM pg_attribute WHERE attrelid = 'public.journal_entries'::regclass AND attname = 'reference_type')")
                 ->fetchAll(PDO::FETCH_COLUMN);
    $labels = array_map('strtolower', $labels);

    $issueType = [];
    foreach ($db->query("SELECT id, issue_type FROM public.material_out_header")->fetchAll(PDO::FETCH_ASSOC) as $r) { $issueType[(int)$r['id']] = $r['issue_type']; }
    $returByIssue = []; $returRef = [];
    foreach ($db->query("SELECT id, ref_no, material_out_id FROM public.supplier_return_hdr WHERE material_out_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $returByIssue[(int)$r['material_out_id']] = (int)$r['id'];
        $returRef[(int)$r['id']] = $r['ref_no'];
    }
    // [PERBAIKAN] aturan posting untuk jurnal yang kurang: jenis jurnal -> rule fiscal_config
    $fixRule = ['wh_receiving' => 'GRN_RCV', 'material_out' => 'ISSUE_PROJECT', 'supplier_return' => 'RETURN_SUPPLIER'];

    // jurnal asli (bukan 'Reverse of ...'), nilai = jumlah debit
    $st = $db->prepare("
        SELECT je.reference_type::text AS rt, je.reference_id AS rid, SUM(jl.debit) AS amt
          FROM public.journal_entries je
          JOIN public.journal_lines jl ON jl.journal_id = je.id
         WHERE je.status::text = 'posted' AND je.description NOT LIKE 'Reverse of %'
           AND je.journal_date <= CAST(:d AS date)
         GROUP BY je.reference_type::text, je.reference_id");
    $st->execute([':d' => $asOf]);
    $jmap = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $jmap[$r['rt'] . '|' . $r['rid']] = (float)$r['amt']; }

    $st = $db->prepare("
        SELECT sm.reference_type, sm.reference_id, MAX(sm.reference_no) AS reference_no, MIN(sm.movement_date) AS doc_date,
               SUM({$VAL}) AS val
          FROM public.stock_movements sm
         WHERE sm.movement_date <= CAST(:d AS date)
         GROUP BY sm.reference_type, sm.reference_id
         ORDER BY MIN(sm.movement_date), sm.reference_id");
    $st->execute([':d' => $asOf]);

    $docs = []; $explained = 0.0; $problemCount = 0;
    $fixes = []; $fixValue = 0.0;     // [PERBAIKAN] dokumen yang jurnalnya bisa dibuat otomatis
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $val = round((float)$r['val'], 2);
        $rtype = strtolower((string)$r['reference_type']);
        $rid = (int)$r['reference_id'];
        $jt = null; $jid = $rid; $status = 'N/A'; $jamt = null;

        if ($rtype === 'material_out') {
            $it = isset($issueType[$rid]) ? $issueType[$rid] : null;
            if ($it === 'SUPPLIER_RETURN') {
                $jt = 'supplier_return';
                if (isset($returByIssue[$rid])) { $jid = $returByIssue[$rid]; } else { $jt = null; }
            } else {
                $jt = 'material_out';
            }
        } elseif (in_array($rtype, $labels, true)) {
            $jt = $rtype;
        }

        if ($jt !== null) {
            $key = $jt . '|' . $jid;
            if (!isset($jmap[$key])) {
                $status = 'TIDAK ADA JURNAL';
                $explained += -$val;
                // dapat diperbaiki otomatis bila jenisnya dikenal dan arah nilainya sesuai (penerimaan = masuk, issue/retur = keluar)
                if (isset($fixRule[$jt]) && (($jt === 'wh_receiving' && $val > 0) || ($jt !== 'wh_receiving' && $val < 0))) {
                    $refNo = (string)$r['reference_no'];
                    $desc = ($jt === 'wh_receiving') ? ('Penerimaan fisik gudang - ' . $refNo)
                          : (($jt === 'material_out') ? ('Issue material ' . $refNo) : ('Retur supplier ' . (isset($returRef[$jid]) ? $returRef[$jid] : $refNo)));
                    $fixes[] = ['rule' => $fixRule[$jt], 'ref_type' => $jt, 'ref_id' => $jid, 'date' => $r['doc_date'],
                                'amount' => abs($val), 'desc' => $desc, 'label' => $refNo];
                    $fixValue += abs($val);
                }
            } else {
                $jamt = round($jmap[$key], 2);
                if (abs($jamt - abs($val)) <= IR_TOL) {
                    $status = 'OK';
                } else {
                    $status = 'NILAI BEDA';
                    $sign = ($val >= 0) ? 1 : -1;
                    $explained += $sign * ($jamt - abs($val));
                }
            }
        }
        if ($status === 'TIDAK ADA JURNAL' || $status === 'NILAI BEDA') $problemCount++;
        $docs[] = ['reference_type' => $r['reference_type'], 'reference_no' => $r['reference_no'], 'doc_date' => $r['doc_date'],
                   'value' => $val, 'journal_amount' => $jamt, 'status' => $status];
    }

    $diff = round($book - $stock, 2);
    $explained = round($explained, 2);
    $summary = [
        'as_of' => $asOf, 'account_id' => $accountId, 'account_label' => $acc['account_code'] . ' - ' . $acc['account_name'],
        'book_balance' => $book, 'stock_value' => $stock, 'diff' => $diff,
        'explained' => $explained, 'unexplained' => round($diff - $explained, 2),
        'docs_total' => count($docs), 'docs_problem' => $problemCount,
        'fixable_count' => count($fixes), 'fixable_value' => round($fixValue, 2),
        'status' => (abs($diff) <= IR_TOL) ? 'SESUAI' : 'SELISIH',
    ];
    return ['summary' => $summary, 'items' => $items, 'warehouses' => $warehouses, 'docs' => $docs, 'fixes' => $fixes];
}

/**
 * [PERBAIKAN] Posting otomatis jurnal yang kurang (hanya dokumen berstatus TIDAK ADA JURNAL yang jenisnya dikenal):
 *   penerimaan fisik gudang -> rule GRN_RCV | issue proyek -> ISSUE_PROJECT | retur supplier -> RETURN_SUPPLIER
 * Nilai = nilai stok dokumen, tanggal = tanggal dokumen. Satu transaksi: gagal satu (mis. periode ditutup) = batal semua.
 * Idempotent: dokumen yang sudah berjurnal tidak muncul lagi. Tidak menyentuh stok. NILAI BEDA dan selisih lain tidak diubah
 * (butuh pemeriksaan manual agar kesalahan tidak tertutup).
 */
function ir_fix_missing($db, string $asOf, int $accountId): array {
    $def = ir_default_account($db);
    if ($accountId <= 0) $accountId = $def;
    if ($def <= 0 || $accountId !== $def) {
        throw new Exception('Perbaikan otomatis hanya untuk akun persediaan utama (akun kredit rule ISSUE_PROJECT di fiscal_config).');
    }
    $r = ir_reconcile($db, $asOf, $accountId);
    if (!count($r['fixes'])) return ['posted' => [], 'count' => 0];

    $posted = [];
    $db->beginTransaction();
    try {
        foreach ($r['fixes'] as $f) {
            $jr = AutoPostJournal($db, [
                'rule_code' => $f['rule'], 'reference_type' => $f['ref_type'], 'reference_id' => (int)$f['ref_id'],
                'journal_date' => $f['date'], 'amount' => $f['amount'], 'description' => $f['desc'],
            ]);
            if (!$jr['success']) throw new Exception($f['label'] . ': ' . $jr['message']);
            $posted[] = $f['label'];
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $e;
    }
    return ['posted' => $posted, 'count' => count($posted)];
}