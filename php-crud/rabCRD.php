<?php
/**
 * rabCRD.php
 * ============================================================
 * Backend Controller untuk RAB Material per Proyek (rab.js).
 *
 * PERBAIKAN dari versi sebelumnya:
 * - [FIX FATAL] $db tidak pernah diinisialisasi ($db = new crud() hilang) --
 *   penyebab "Call to a member function prepare() on null".
 * - [FIX SKEMA] Tabel sumber SUDAH TIDAK ADA: m_materials, rab_materials,
 *   v_material_budget_monitoring (dihapus saat konsolidasi ke materials/
 *   budget_items). Ditulis ulang memakai materials + budget_items, dengan
 *   status ON TRACK/WARNING/OVERBUDGET dihitung inline (menggantikan view
 *   yang sudah tidak ada) dari budget_items + material_out_items.
 * - Kontrak JSON (nama field: budget_qty, unit_price, tolerance_pct, dst)
 *   DIPERTAHANKAN PERSIS -- rab.js tidak perlu diubah sama sekali.
 * - budget_items.item_name (NOT NULL) diisi otomatis dari materials.material_name.
 * - [MULTI CURRENCY] Satu mata uang anggaran per proyek (projects.budget_currency_id) + kurs ke
 *   mata uang dasar. Harga satuan RAB = mata uang anggaran. Nilai pemakaian aktual (material out,
 *   dalam mata uang dasar) dikonversi ke mata uang anggaran memakai kurs anggaran.
 *   Mata uang tidak bisa diganti bila proyek sudah punya PO (non-batal) atau pemakaian material.
 *   Butuh sql/2026-10-01.rab_currency.sql.
 * - Butuh migration_rab_budget_items.sql (unique index project_id+material_id
 *   utk category='Material') supaya ON CONFLICT di save_rab berfungsi.
 * ============================================================
 */

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';
require_once __DIR__ . '/../php-func/fx.func.php';

session_start();
header('Content-Type: application/json');

$db = new crud(); // [FIX] sebelumnya tidak ada -- inilah penyebab fatal error

function sendJsonResponse($success, $data = [], $message = '', $extra = []) {
    $response = array_merge(['success' => $success, 'message' => $message], $extra);
    if ($success) {
        if (is_array($data) && isset($data['data'])) {
            $response['data'] = $data['data'];
            if (isset($data['totalCount'])) $response['totalCount'] = $data['totalCount'];
        } else {
            $response['data'] = $data;
        }
    }
    echo json_encode($response);
    exit;
}

/** Alasan mata uang anggaran proyek tidak boleh diganti (null = masih boleh). */
function rab_currency_lock_reason($db, int $projectId): ?string {
    $st = $db->prepare("SELECT 1 FROM public.po_items poi
                          JOIN public.purchase_orders po ON po.id = poi.po_id
                         WHERE po.project_id = :p AND po.status <> 'Dibatalkan' LIMIT 1");
    $st->execute([':p' => $projectId]);
    if ($st->fetchColumn()) return 'Proyek ini sudah memiliki PO.';

    $st = $db->prepare("SELECT 1 FROM public.material_out_header WHERE project_id = :p AND status = 'posted' LIMIT 1");
    $st->execute([':p' => $projectId]);
    if ($st->fetchColumn()) return 'Proyek ini sudah memiliki pemakaian material (issue).';
    return null;
}

// Pecahan status RAB dipakai di dua tempat (list_projects & load_rab) --
// didefinisikan sekali di sini supaya konsisten.
// - OVERBUDGET : qty terpakai melebihi qty_plan + toleransi
// - WARNING    : qty terpakai melebihi qty_plan, tapi masih dalam toleransi
// - ON TRACK   : sisanya
const RAB_USAGE_CTE = "
    WITH usage AS (
        SELECT moh.project_id, moi.material_id,
               SUM(moi.qty_out) AS qty_out,
               SUM(moi.qty_out * moi.unit_cost) AS actual_cost
          FROM material_out_items moi
          JOIN material_out_header moh ON moh.id = moi.material_out_id AND moh.status = 'posted'
         GROUP BY moh.project_id, moi.material_id
    )
";

$task = $_REQUEST['task'] ?? '';

try {
    switch ($task) {

        /* ------------------------------------------------------------------
         * 1. LIST_PROJECTS
         * ------------------------------------------------------------------ */
        case 'list_projects':
            $start = (int)($_REQUEST['start'] ?? 0);
            $limit = (int)($_REQUEST['limit'] ?? 250);
            $query = trim($_REQUEST['query'] ?? '');

            $whereClauses = [];
            $params = [];
            if ($query !== '') {
                $whereClauses[] = "(p.name ILIKE :query OR c.name ILIKE :query)";
                $params[':query'] = '%' . $query . '%';
            }
            $searchWhere = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

            $stmtCount = $db->prepare("SELECT COUNT(*) FROM public.projects p LEFT JOIN public.clients c ON c.id = p.client_id {$searchWhere}");
            $stmtCount->execute($params);
            $totalCount = (int)$stmtCount->fetchColumn();

            $sql = RAB_USAGE_CTE . "
                SELECT p.id, p.name, c.name AS client_name, p.status,
                       COALESCE(UPPER(TRIM(cur.init)), 'IDR') AS currency_code,
                       COALESCE(NULLIF(p.budget_exch_rate, 0), 1) AS budget_exch_rate,
                       COALESCE(agg.material_count, 0) AS material_count,
                       COALESCE(agg.total_budget_cost, 0) AS total_budget_cost,
                       COALESCE(agg.total_budget_cost, 0) * COALESCE(NULLIF(p.budget_exch_rate, 0), 1) AS total_budget_base,
                       COALESCE(agg.total_actual_cost, 0) / COALESCE(NULLIF(p.budget_exch_rate, 0), 1) AS total_actual_cost,
                       CASE COALESCE(agg.status_rank, 0)
                           WHEN 3 THEN 'OVERBUDGET'
                           WHEN 2 THEN 'WARNING'
                           WHEN 1 THEN 'ON TRACK'
                           ELSE 'NO BUDGET'
                       END AS rab_status
                  FROM public.projects p
                  LEFT JOIN public.clients c ON c.id = p.client_id
                  LEFT JOIN public.m_currency cur ON cur.id = p.budget_currency_id
                  LEFT JOIN (
                      SELECT bi.project_id,
                             COUNT(*) FILTER (WHERE COALESCE(bi.qty_plan,0) > 0) AS material_count,
                             SUM(bi.qty_plan * bi.unit_price_plan) AS total_budget_cost,
                             SUM(COALESCE(u.actual_cost, 0)) AS total_actual_cost,
                             MAX(
                                 CASE
                                     WHEN COALESCE(u.qty_out,0) > bi.qty_plan * (1 + COALESCE(bi.tolerance_pct,0)/100) THEN 3
                                     WHEN COALESCE(u.qty_out,0) > bi.qty_plan THEN 2
                                     ELSE 1
                                 END
                             ) AS status_rank
                        FROM public.budget_items bi
                        LEFT JOIN usage u ON u.project_id = bi.project_id AND u.material_id = bi.material_id
                       WHERE bi.category = 'Material'
                       GROUP BY bi.project_id
                  ) agg ON agg.project_id = p.id
                  {$searchWhere}
                  ORDER BY p.id DESC
                  LIMIT :limit OFFSET :start";

            $stmt = $db->prepare($sql);
            foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':start', $start, PDO::PARAM_INT);
            $stmt->execute();
            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($projects as &$p) {
                $p['id'] = (int)$p['id'];
                $p['material_count'] = (int)$p['material_count'];
                $p['total_budget_cost'] = (float)$p['total_budget_cost'];
                $p['total_budget_base'] = (float)$p['total_budget_base'];
                $p['total_actual_cost'] = (float)$p['total_actual_cost'];
                $p['budget_exch_rate'] = (float)$p['budget_exch_rate'];
            }
            sendJsonResponse(true, ['data' => $projects, 'totalCount' => $totalCount]);
            break;

        /* ------------------------------------------------------------------
         * 1b. LIST_CURRENCIES + 1c. GET_EXCHANGE_RATE (kurs default KMK pada tanggal tertentu)
         * ------------------------------------------------------------------ */
        case 'list_currencies':
            $rows = $db->query("SELECT id, init, name FROM public.m_currency ORDER BY init ASC")->fetchAll(PDO::FETCH_ASSOC);
            $baseCur = fx_base_currency($db);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['is_base'] = $baseCur['id'] !== null && $r['id'] === $baseCur['id'];
            }
            sendJsonResponse(true, $rows);
            break;

        case 'get_exchange_rate':
            $cid = (int)($_REQUEST['currency_id'] ?? 0);
            if ($cid <= 0) sendJsonResponse(false, [], 'Mata uang tidak valid.');
            sendJsonResponse(true, fx_default_rate($db, $cid, (string)($_REQUEST['date'] ?? '')));
            break;

        /* ------------------------------------------------------------------
         * 2. LIST_MATERIALS
         * ------------------------------------------------------------------ */
        case 'list_materials':
            $stmt = $db->query("SELECT id, material_code, material_name, unit, COALESCE(estimated_price,0) AS estimated_price
                                   FROM public.materials WHERE active IS TRUE ORDER BY material_name ASC");
            $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($materials as &$m) {
                $m['id'] = (int)$m['id'];
                $m['estimated_price'] = (float)$m['estimated_price'];
            }
            sendJsonResponse(true, $materials);
            break;

        /* ------------------------------------------------------------------
         * 3. LOAD_RAB
         * ------------------------------------------------------------------ */
        case 'load_rab':
            $projectId = (int)($_REQUEST['project_id'] ?? 0);
            if ($projectId <= 0) sendJsonResponse(false, [], 'ID Proyek tidak valid.');

            $stmtP = $db->prepare("SELECT p.name, p.budget_currency_id, COALESCE(NULLIF(p.budget_exch_rate, 0), 1) AS budget_exch_rate,
                                          p.budget_rate_date, UPPER(TRIM(cur.init)) AS currency_code
                                     FROM public.projects p
                                     LEFT JOIN public.m_currency cur ON cur.id = p.budget_currency_id
                                    WHERE p.id = :id");
            $stmtP->execute([':id' => $projectId]);
            $proj = $stmtP->fetch(PDO::FETCH_ASSOC);
            if (!$proj) sendJsonResponse(false, [], 'Proyek tidak ditemukan.');
            $projectName = $proj['name'];

            $baseCur = fx_base_currency($db);
            $curId   = $proj['budget_currency_id'] !== null ? (int)$proj['budget_currency_id'] : (int)$baseCur['id'];
            $currencyInfo = [
                'id'            => $curId,
                'code'          => $proj['currency_code'] ?: ($baseCur['code'] ?: 'IDR'),
                'is_base'       => $baseCur['id'] !== null && $curId === $baseCur['id'],
                'base_code'     => $baseCur['code'] ?: 'IDR',
                'exch_rate'     => (float)$proj['budget_exch_rate'],
                'rate_date'     => $proj['budget_rate_date'],
                'locked_reason' => rab_currency_lock_reason($db, $projectId),
            ];

            $sql = RAB_USAGE_CTE . "
                SELECT bi.id, bi.project_id, bi.material_id,
                       m.material_code, m.material_name, m.unit,
                       COALESCE(bi.qty_plan, 0) AS budget_qty,
                       COALESCE(bi.unit_price_plan, 0) AS unit_price,
                       COALESCE(bi.tolerance_pct, 0) AS tolerance_pct,
                       COALESCE(u.qty_out, 0) AS total_qty_out,
                       (COALESCE(bi.qty_plan,0) - COALESCE(u.qty_out, 0)) AS remaining_qty,
                       CASE WHEN COALESCE(bi.qty_plan,0) > 0
                            THEN (COALESCE(u.qty_out, 0) / bi.qty_plan) * 100
                            ELSE 0 END AS usage_pct,
                       CASE
                           WHEN COALESCE(u.qty_out,0) > COALESCE(bi.qty_plan,0) * (1 + COALESCE(bi.tolerance_pct,0)/100) THEN 'OVERBUDGET'
                           WHEN COALESCE(u.qty_out,0) > COALESCE(bi.qty_plan,0) THEN 'WARNING'
                           ELSE 'ON TRACK'
                       END AS status
                  FROM public.budget_items bi
                  JOIN public.materials m ON m.id = bi.material_id
                  LEFT JOIN usage u ON u.project_id = bi.project_id AND u.material_id = bi.material_id
                 WHERE bi.project_id = :project_id AND bi.category = 'Material'
                 ORDER BY m.material_name ASC";

            $stmt = $db->prepare($sql);
            $stmt->execute([':project_id' => $projectId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as &$item) {
                $item['id'] = (int)$item['id'];
                $item['material_id'] = (int)$item['material_id'];
                $item['budget_qty'] = (float)$item['budget_qty'];
                $item['unit_price'] = (float)$item['unit_price'];
                $item['tolerance_pct'] = (float)$item['tolerance_pct'];
                $item['total_qty_out'] = (float)$item['total_qty_out'];
                $item['remaining_qty'] = (float)$item['remaining_qty'];
                $item['usage_pct'] = (float)$item['usage_pct'];
            }
            sendJsonResponse(true, ['name' => $projectName, 'items' => $items, 'currency' => $currencyInfo]);
            break;

        /* ------------------------------------------------------------------
         * 4. SAVE_RAB
         * ------------------------------------------------------------------ */
        case 'save_rab':
            $projectId = (int)($_POST['project_id'] ?? 0);
            $items = json_decode($_POST['items'] ?? '[]', true);

            if ($projectId <= 0) sendJsonResponse(false, [], 'ID Proyek tidak valid.');
            if (!is_array($items)) sendJsonResponse(false, [], 'Format data item RAB tidak valid.');

            // --- Mata uang anggaran + kurs (klien lama yang tidak mengirim currency_id -> pertahankan yang tersimpan)
            $stmtCur = $db->prepare("SELECT budget_currency_id FROM public.projects WHERE id = :id");
            $stmtCur->execute([':id' => $projectId]);
            $storedCur = $stmtCur->fetchColumn();
            if ($storedCur === false) sendJsonResponse(false, [], 'Proyek tidak ditemukan.');
            $storedCur = $storedCur !== null ? (int)$storedCur : null;

            $baseCur    = fx_base_currency($db);
            $currencyId = (int)($_POST['currency_id'] ?? 0);
            if ($currencyId <= 0) $currencyId = $storedCur ?: (int)$baseCur['id'];
            if ($currencyId <= 0) sendJsonResponse(false, [], 'Mata uang anggaran belum dapat ditentukan. Jalankan migrasi mata uang.');

            $stmtChk = $db->prepare("SELECT 1 FROM public.m_currency WHERE id = :id");
            $stmtChk->execute([':id' => $currencyId]);
            if (!$stmtChk->fetchColumn()) sendJsonResponse(false, [], 'Mata uang anggaran tidak ditemukan.');

            $exchRate = (float)($_POST['exch_rate'] ?? 1);
            if ($baseCur['id'] !== null && $currencyId === $baseCur['id']) $exchRate = 1.0;      // mata uang dasar selalu berkurs 1
            if ($exchRate <= 0) sendJsonResponse(false, [], 'Kurs harus lebih besar dari 0.');

            $rateDate = (string)($_POST['rate_date'] ?? '');
            $rd = DateTime::createFromFormat('Y-m-d', $rateDate);
            if (!$rd || $rd->format('Y-m-d') !== $rateDate) $rateDate = date('Y-m-d');

            if ($storedCur !== null && $currencyId !== $storedCur) {
                $lock = rab_currency_lock_reason($db, $projectId);
                if ($lock !== null) sendJsonResponse(false, [], 'Mata uang anggaran tidak dapat diubah. ' . $lock);
            }

            $db->beginTransaction();
            try {
                $stmtProj = $db->prepare("UPDATE public.projects
                                             SET budget_currency_id = :c, budget_exch_rate = :r, budget_rate_date = CAST(:d AS date)
                                           WHERE id = :id");
                $stmtProj->execute([':c' => $currencyId, ':r' => $exchRate, ':d' => $rateDate, ':id' => $projectId]);

                $activeMaterialIds = [];

                // item_name (NOT NULL di budget_items) diambil dari materials.material_name.
                $stmtUpsert = $db->prepare("
                    INSERT INTO public.budget_items
                        (project_id, material_id, category, item_name, unit, qty_plan, unit_price_plan, tolerance_pct, created_at)
                    SELECT :project_id, :material_id, 'Material', m.material_name, m.unit,
                           :budget_qty, :unit_price, :tolerance_pct, NOW()
                      FROM public.materials m WHERE m.id = :material_id
                    ON CONFLICT (project_id, material_id) WHERE category = 'Material' AND material_id IS NOT NULL
                    DO UPDATE SET
                        qty_plan = EXCLUDED.qty_plan,
                        unit_price_plan = EXCLUDED.unit_price_plan,
                        tolerance_pct = EXCLUDED.tolerance_pct,
                        item_name = EXCLUDED.item_name,
                        unit = EXCLUDED.unit
                ");

                foreach ($items as $item) {
                    $materialId = (int)($item['material_id'] ?? 0);
                    $budgetQty  = (float)($item['budget_qty'] ?? 0);
                    $unitPrice  = (float)($item['unit_price'] ?? 0);
                    $tolerance  = (float)($item['tolerance_pct'] ?? 0);

                    if ($materialId > 0 && $budgetQty > 0) {
                        $activeMaterialIds[] = $materialId;
                        $ok = $stmtUpsert->execute([
                            ':project_id' => $projectId, ':material_id' => $materialId,
                            ':budget_qty' => $budgetQty, ':unit_price' => $unitPrice, ':tolerance_pct' => $tolerance
                        ]);
                        if (!$ok || $stmtUpsert->rowCount() === 0) {
                            throw new Exception("Material (id {$materialId}) tidak ditemukan di master materials.");
                        }
                    }
                }

                // Hapus baris RAB Material proyek ini yang tidak lagi ada di grid.
                // Catatan: po_items.budget_item_id -> ON DELETE SET NULL, jadi PO
                // yang sudah pernah dikaitkan ke baris RAB ini tidak ikut terhapus,
                // cuma kaitannya ke baris RAB tersebut hilang.
                if ($activeMaterialIds) {
                    $ph = [];
                    $params = [':project_id' => $projectId];
                    foreach (array_values($activeMaterialIds) as $i => $mid) {
                        $ph[] = ":m{$i}"; $params[":m{$i}"] = $mid;
                    }
                    $stmtDel = $db->prepare("DELETE FROM public.budget_items
                                               WHERE project_id = :project_id AND category = 'Material'
                                                 AND (material_id IS NULL OR material_id NOT IN (" . implode(',', $ph) . "))");
                    $stmtDel->execute($params);
                } else {
                    $stmtDel = $db->prepare("DELETE FROM public.budget_items WHERE project_id = :project_id AND category = 'Material'");
                    $stmtDel->execute([':project_id' => $projectId]);
                }

                $db->commit();
                sendJsonResponse(true, [], 'RAB material berhasil disimpan.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                sendJsonResponse(false, [], 'Gagal menyimpan RAB: ' . $e->getMessage());
            }
            break;

        default:
            sendJsonResponse(false, [], 'Aksi/Task tidak dikenal.');
            break;
    }
} catch (PDOException $e) {
    sendJsonResponse(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    sendJsonResponse(false, [], 'System Error: ' . $e->getMessage());
}