<?php
/*
    Document        : views/dashboard.php
    Description     : Integrated Monitoring Dashboard (Material, Budget & Financial Control)
*/

if (!isset($_SESSION)) { 
    session_start(); 
}

require_once __DIR__ . '/../php-class/crudClass.php';

// Security Check
if (!isset($_SESSION["user_id"]) || is_null($_SESSION["user_id"])) {
    header('Location: ../index.php');
    exit;
}

$db = new crud();
$user_id = $_SESSION["user_id"] ?? 0;

function toRoman($num) {
    $romans = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII', 'XIII', 'XIV', 'XV'];
    $num = intval($num);
    return isset($romans[$num]) ? $romans[$num] : $num;
}

// 1. FETCH MENU UTAMA DINAMIS
$mainMenus = [];
try {
    $hsql = "
      SELECT id, name, seq
      FROM m_form_group
      WHERE active = true AND substring(seq,3,2)='00'
        AND substring(seq,1,2) IN (
          SELECT DISTINCT substring(g.seq,1,2) seqn
          FROM m_form_group g
          LEFT JOIN m_form_name f ON g.id = f.group_id
          LEFT JOIN m_user_access_menu u ON u.form_id = f.id
          WHERE g.active = true AND u.user_id = :user_id
          GROUP BY substring(g.seq,1,2)
      )
      ORDER BY seq::int
    ";
    $stmt = $db->prepare($hsql);
    $stmt->execute(['user_id' => $user_id]);
    $mainMenus = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $mainMenus = [];
}

// 2. FETCH PROYEK
$projectsList = [];
try {
    $pStmt = $db->prepare("SELECT id, name AS project_name FROM projects ORDER BY name ASC");
    $pStmt->execute();
    $projectsList = $pStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $projectsList = [];
}

$selected_project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
if ($selected_project_id === 0 && !empty($projectsList)) {
    $selected_project_id = $projectsList[0]['id'];
}

// 3. FETCH MONITORING MATERIAL & BUDGET
$kpiData = ['budget_rab' => 0, 'material_out' => 0, 'sisa_budget' => 0, 'persentase' => 0];
$tableItems = [];
$penyerapanStatusCount = ['On Track' => 0, 'Warning' => 0, 'Overbudget' => 0];

if ($selected_project_id > 0) {
    try {
        $sql = "
            SELECT 
                material_code AS item_code,
                material_name AS item_name,
                unit AS unit_measurement,
                budget_qty,
                total_qty_out AS out_qty,
                remaining_qty,
                usage_pct,
                status
            FROM v_material_budget_monitoring
            WHERE project_id = :prj_id
            ORDER BY material_name ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(['prj_id' => $selected_project_id]);
        $tableItems = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($tableItems as $row) {
            $kpiData['budget_rab']   += (float)$row['budget_qty'];
            $kpiData['material_out'] += (float)$row['out_qty'];

            $st = strtoupper($row['status']);
            if ($st === 'OVERBUDGET') {
                $penyerapanStatusCount['Overbudget']++;
            } elseif ($st === 'WARNING') {
                $penyerapanStatusCount['Warning']++;
            } else {
                $penyerapanStatusCount['On Track']++;
            }
        }

        $kpiData['sisa_budget'] = $kpiData['budget_rab'] - $kpiData['material_out'];
        if ($kpiData['budget_rab'] > 0) {
            $kpiData['persentase'] = round(($kpiData['material_out'] / $kpiData['budget_rab']) * 100, 2);
        }
    } catch (PDOException $e) {
        $tableItems = [];
    }
}

// Persentase Status Donut Chart
$totalStatusItems = array_sum($penyerapanStatusCount);
$pctOnTrack   = $totalStatusItems > 0 ? round(($penyerapanStatusCount['On Track'] / $totalStatusItems) * 100) : 0;
$pctWarning   = $totalStatusItems > 0 ? round(($penyerapanStatusCount['Warning'] / $totalStatusItems) * 100) : 0;
$pctOverbudget = $totalStatusItems > 0 ? round(($penyerapanStatusCount['Overbudget'] / $totalStatusItems) * 100) : 0;

// 4. FETCH FINANCIAL SUMMARY & RATE USD TERBARU (JOIN HEADER & DETAIL)
$financialSummary = [
    'total_pendapatan'  => 0,
    'total_beban'       => 0,
    'total_transaksi'   => 0,
    'laba_bersih'       => 0,
    'rate_val'          => 'Rp 0',
    'rate_periode'      => '',
    'rate_status'       => 'Menghubungkan...',
    'rate_status_cls'   => 'text-rose-400'
];

try {
    // 4a. Total Pendapatan
    $stmtIncome = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_inc 
        FROM financial_incomes 
        WHERE UPPER(status) = 'PAID'
          " . ($selected_project_id > 0 ? "AND (project_id = :prj_id OR project_id IS NULL)" : "") . "
    ");
    $paramsInc = $selected_project_id > 0 ? ['prj_id' => $selected_project_id] : [];
    $stmtIncome->execute($paramsInc);
    $incData = $stmtIncome->fetch(PDO::FETCH_ASSOC);
    if ($incData) {
        $financialSummary['total_pendapatan'] = (float)$incData['total_inc'];
    }

    // 4b. Total Beban
    $stmtExpense = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_exp, COUNT(id) AS cnt_exp 
        FROM financial_expenses 
        WHERE UPPER(status) = 'PAID'
          " . ($selected_project_id > 0 ? "AND (project_id = :prj_id OR project_id IS NULL)" : "") . "
    ");
    $paramsExp = $selected_project_id > 0 ? ['prj_id' => $selected_project_id] : [];
    $stmtExpense->execute($paramsExp);
    $expData = $stmtExpense->fetch(PDO::FETCH_ASSOC);
    if ($expData) {
        $financialSummary['total_beban'] = (float)$expData['total_exp'];
        $financialSummary['total_transaksi'] = (int)$expData['cnt_exp'];
    }

    // 4c. Laba Bersih
    $financialSummary['laba_bersih'] = $financialSummary['total_pendapatan'] - $financialSummary['total_beban'];

    // 4d. Kurs USD Terbaru dari JOIN tax_exchange_rate_hdr & tax_exchange_rate_dtl
    $stmtRate = $db->prepare("
        SELECT 
            d.rate AS rate_value,
            h.start_date,
            h.end_date,
            h.kmk_no AS kmk_number
        FROM tax_exchange_rate_dtl d
        INNER JOIN tax_exchange_rate_hdr h ON d.hdr_id = h.id
        WHERE UPPER(d.currency_code) = 'USD'
        ORDER BY h.start_date DESC, h.id DESC
        LIMIT 1
    ");
    $stmtRate->execute();
    $rateData = $stmtRate->fetch(PDO::FETCH_ASSOC);

    if ($rateData && isset($rateData['rate_value']) && floatval($rateData['rate_value']) > 0) {
        $rateValue = floatval($rateData['rate_value']);
        
        $startDate = !empty($rateData['start_date']) ? date('d/m/Y', strtotime($rateData['start_date'])) : '';
        $endDate   = !empty($rateData['end_date'])   ? date('d/m/Y', strtotime($rateData['end_date']))   : '';

        if (!empty($startDate) && !empty($endDate)) {
            $financialSummary['rate_periode'] = "({$startDate} - {$endDate})";
        } elseif (!empty($startDate)) {
            $financialSummary['rate_periode'] = "({$startDate})";
        }

        $financialSummary['rate_val']        = "Rp " . number_format($rateValue, 2, ',', '.');
        $financialSummary['rate_status']     = "● USD Rate Connected";
        $financialSummary['rate_status_cls'] = "text-emerald-400";
    } else {
        $financialSummary['rate_val']        = "Rp 0";
        $financialSummary['rate_status']     = "Data Kurs USD KMK belum diinput.";
        $financialSummary['rate_status_cls'] = "text-amber-400";
    }

} catch (Exception $e) {
    $financialSummary['rate_val']        = "Rp 0";
    $financialSummary['rate_status']     = "Gagal terhubung ke server database.";
    $financialSummary['rate_status_cls'] = "text-rose-400";
}

// 5. FETCH TREN KEUANGAN BULANAN (PERFORMA KEUANGAN JAN - DES)
$monthlyLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
$monthlyIncomeData  = array_fill(0, 12, 0);
$monthlyExpenseData = array_fill(0, 12, 0);

try {
    $sqlInc = "
        SELECT EXTRACT(MONTH FROM created_at) AS mth, SUM(amount) AS total 
        FROM financial_incomes 
        WHERE UPPER(status) = 'PAID' AND EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM CURRENT_DATE)
        GROUP BY EXTRACT(MONTH FROM created_at)
    ";
    foreach ($db->query($sqlInc) as $row) {
        $idx = (int)$row['mth'] - 1;
        if ($idx >= 0 && $idx < 12) $monthlyIncomeData[$idx] = (float)$row['total'];
    }

    $sqlExp = "
        SELECT EXTRACT(MONTH FROM created_at) AS mth, SUM(amount) AS total 
        FROM financial_expenses 
        WHERE UPPER(status) = 'PAID' AND EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM CURRENT_DATE)
        GROUP BY EXTRACT(MONTH FROM created_at)
    ";
    foreach ($db->query($sqlExp) as $row) {
        $idx = (int)$row['mth'] - 1;
        if ($idx >= 0 && $idx < 12) $monthlyExpenseData[$idx] = (float)$row['total'];
    }
} catch (Exception $e) {
    // Apabila query error, data tetap nol
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>[XYZ] Monitoring Material, Budget & Keuangan</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;900&family=Rajdhani:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        tech: ['Rajdhani', 'sans-serif'],
                        header: ['Orbitron', 'sans-serif'],
                        body: ['Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        html, body {
            background-color: #040c16 !important;
            color: #e2e8f0;
            min-height: 100vh;
        }
        
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .nav-item:hover .dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        .dropdown-menu {
            opacity: 0;
            visibility: hidden;
            transform: translateY(-6px);
            transition: all 0.2s ease-in-out;
        }

        .card-bg {
            background-color: #081624;
            border: 1px solid #143550;
        }
        
        .inner-card-bg {
            background-color: #05101a;
            border: 1px solid #102a40;
        }

        .metric-blue {
            background: linear-gradient(180deg, rgba(8, 26, 43, 0.9) 0%, rgba(5, 18, 30, 0.9) 100%);
            border: 1px solid #00c3ff40;
        }
        .metric-green {
            background: linear-gradient(180deg, rgba(8, 32, 28, 0.9) 0%, rgba(5, 22, 18, 0.9) 100%);
            border: 1px solid #00ff8840;
        }
        .metric-red {
            background: linear-gradient(180deg, rgba(38, 12, 22, 0.9) 0%, rgba(25, 8, 14, 0.9) 100%);
            border: 1px solid #ff005540;
        }
        .metric-purple {
            background: linear-gradient(180deg, rgba(28, 12, 38, 0.9) 0%, rgba(18, 8, 25, 0.9) 100%);
            border: 1px solid #a855f740;
        }
    </style>
</head>
<body class="font-body p-4 md:p-6 space-y-5">

    <div class="max-w-[1600px] mx-auto space-y-5">

        <!-- DYNAMIC NAVBAR MENU -->
        <nav class="relative bg-[#081624] border border-[#143550] rounded-xl shadow-2xl mb-6 backdrop-blur-md">
            <div class="flex items-center justify-between h-14 px-4">
                <div class="flex items-center gap-6">
                    <a href="#" class="flex items-center gap-3 group">
                        <?php if (file_exists(__DIR__ . '/../assets/logo_c.png')): ?>
                            <img src="../assets/logo_c.png" alt="Logo" class="h-8 w-auto object-contain">
                        <?php else: ?>
                            <div class="w-9 h-9 rounded-lg bg-gradient-to-tr from-cyan-500 to-sky-400 p-0.5 shadow-lg shadow-cyan-500/30 group-hover:shadow-cyan-500/50 transition-all">
                                <div class="w-full h-full bg-[#040c16] rounded-[7px] flex items-center justify-center">
                                    <span class="font-header text-cyan-400 font-black text-xs tracking-tighter">XYZ</span>
                                </div>
                            </div>
                        <?php endif; ?>
                        <span class="font-header text-sm font-bold tracking-wider text-white hidden sm:inline-block">
                            [XYZ]<span class="text-cyan-400">SYS</span>
                        </span>
                    </a>

                    <ul class="flex items-center space-x-1 sm:space-x-3 lg:space-x-5 font-header text-xs lg:text-xs tracking-wider font-semibold border-l border-[#143550] pl-4 sm:pl-6">
                        <?php 
                        $i = 0;
                        foreach ($mainMenus as $main): 
                            $i++;
                            $romanNum = toRoman($i);
                            $mainSeq = substr($main["seq"], 0, 2);

                            $csql = "
                                SELECT id, name, seq
                                FROM m_form_group
                                WHERE active = true AND substring(seq,3,2)!='00' AND substring(seq,1,2) = :sub_seq
                                  AND id IN (
                                    SELECT DISTINCT g.id
                                    FROM m_form_group g
                                    LEFT JOIN m_form_name f ON g.id = f.group_id
                                    LEFT JOIN m_user_access_menu u ON u.form_id = f.id
                                    WHERE g.active = true AND f.active = true AND u.user_id = :user_id
                                )
                                ORDER BY seq::int
                            ";
                            $cStmt = $db->prepare($csql);
                            $cStmt->execute(['sub_seq' => $mainSeq, 'user_id' => $user_id]);
                            $childGroups = $cStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                            $hasChildren = count($childGroups) > 0;
                        ?>

                        <li class="nav-item group">
                            <a href="#" class="py-4 px-2 text-cyan-200 hover:text-yellow-300 group-hover:text-yellow-300 flex items-center gap-1 transition-all">
                                <?= $romanNum . '. ' . strtoupper(htmlspecialchars($main['name'])); ?>
                            </a>

                            <?php if ($hasChildren): ?>
                            <div class="dropdown-menu absolute left-0 right-0 top-full mt-1 w-full bg-[#081624]/95 backdrop-blur-md border border-[#143550] rounded-xl shadow-2xl p-5 text-cyan-100 z-50">
                                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-6">
                                    <?php foreach ($childGroups as $child): 
                                        $fsql = "
                                            SELECT f.id, f.title, f.php_url
                                            FROM m_form_name f
                                            LEFT JOIN m_user_access_menu u ON u.form_id = f.id
                                            WHERE f.active = true AND group_id = :group_id AND u.id IS NOT NULL AND u.user_id = :user_id
                                            ORDER BY seq::int
                                        ";
                                        $fStmt = $db->prepare($fsql);
                                        $fStmt->execute(['group_id' => $child["id"], 'user_id' => $user_id]);
                                        $formItems = $fStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                    ?>
                                    <div class="space-y-2">
                                        <h3 class="font-tech text-cyan-400 font-bold text-sm tracking-widest uppercase border-b border-[#143550] pb-1">
                                            <?= htmlspecialchars($child['name']); ?>
                                        </h3>
                                        <ul class="space-y-1 font-tech text-sm text-cyan-200">
                                            <?php foreach ($formItems as $item): ?>
                                                <li>
                                                    <a href="router?id=<?= $item['id']; ?>" target="_blank" rel="noopener noreferrer" class="hover:text-yellow-400 hover:translate-x-1 inline-block transition-all py-0.5">
                                                        <?= htmlspecialchars($item['title']); ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <a href="../logout.php" class="h-8 bg-rose-600/80 hover:bg-rose-600 text-white font-header text-xs tracking-widest px-3.5 rounded-lg flex items-center justify-center transition-colors shadow-md border border-rose-500/40">
                    <span>Logout</span>
                </a>
            </div>
        </nav>

        <!-- HEADER & FILTER PROYEK -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h1 class="text-xl md:text-2xl font-bold text-cyan-400 font-header tracking-wide">Monitoring Material, Budget & Keuangan</h1>
                <p class="text-xs text-slate-400 font-tech">Pengawasan Efisiensi Penyerapan Material Proyek & Financial Control Real-time</p>
            </div>
            
            <form method="GET" action="" class="flex items-center gap-2">
                <label for="project_id" class="text-xs font-tech text-slate-300">Pilih Proyek:</label>
                <select name="project_id" id="project_id" onchange="this.form.submit()" class="bg-[#081624] border border-[#143550] text-cyan-300 text-xs rounded px-3 py-1.5 font-tech outline-none">
                    <?php if (empty($projectsList)): ?>
                        <option value="0">Tidak Ada Proyek</option>
                    <?php else: ?>
                        <?php foreach ($projectsList as $prj): ?>
                            <option value="<?= $prj['id']; ?>" <?= $prj['id'] == $selected_project_id ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($prj['project_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </form>
        </div>

        <!-- TOP METRIC CARDS -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="metric-blue p-4 rounded-lg shadow-lg">
                <p class="text-[11px] font-bold text-cyan-400 uppercase tracking-wider font-tech">TOTAL BUDGET RAB</p>
                <h3 class="text-2xl lg:text-3xl font-extrabold text-white mt-1 font-tech">
                    <?= number_format($kpiData['budget_rab'], 2, ',', '.'); ?>
                </h3>
            </div>
            <div class="metric-green p-4 rounded-lg shadow-lg">
                <p class="text-[11px] font-bold text-emerald-400 uppercase tracking-wider font-tech">MATERIAL OUT</p>
                <h3 class="text-2xl lg:text-3xl font-extrabold text-emerald-400 mt-1 font-tech">
                    <?= number_format($kpiData['material_out'], 2, ',', '.'); ?>
                </h3>
            </div>
            <div class="metric-red p-4 rounded-lg shadow-lg">
                <p class="text-[11px] font-bold text-rose-400 uppercase tracking-wider font-tech">SISA BUDGET</p>
                <h3 class="text-2xl lg:text-3xl font-extrabold <?= $kpiData['sisa_budget'] < 0 ? 'text-rose-400' : 'text-cyan-300'; ?> mt-1 font-tech">
                    <?= number_format($kpiData['sisa_budget'], 2, ',', '.'); ?>
                </h3>
            </div>
            <div class="metric-purple p-4 rounded-lg shadow-lg">
                <p class="text-[11px] font-bold text-purple-300 uppercase tracking-wider font-tech">PERSENTASE PENYERAPAN</p>
                <h3 class="text-2xl lg:text-3xl font-extrabold text-purple-200 mt-1 font-tech">
                    <?= $kpiData['persentase']; ?>%
                </h3>
            </div>
        </div>

        <!-- FINANCIAL AGING ANALYSIS CONTAINER -->
        <div class="card-bg rounded-lg p-4 shadow-lg space-y-4">
            <div class="flex flex-col sm:flex-row justify-between sm:items-center border-b border-[#143550] pb-3 gap-2">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-coins text-purple-400 text-xs"></i>
                    <h2 class="text-xs font-bold text-cyan-400 font-header uppercase tracking-wider">FINANCIAL AGING ANALYSIS</h2>
                    <span class="text-[10px] text-slate-400 font-tech hidden md:inline ml-2">Monitoring Umur Piutang (AR) & Hutang (AP) berdasarkan Jatuh Tempo</span>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                
                <!-- KOLOM 1: CHART PERFORMA KEUANGAN BULANAN -->
                <div class="lg:col-span-1 inner-card-bg rounded-xl p-4 border border-[#143550] flex flex-col justify-between h-full">
                    <div>
                        <div class="flex justify-between items-center border-b border-[#143550]/60 pb-2 mb-3">
                            <div>
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block font-tech">FINANCIAL ANALYSIS</span>
                                <h3 class="text-xs font-bold text-cyan-400 uppercase tracking-wider font-header">PERFORMA KEUANGAN</h3>
                            </div>
                        </div>
                        <p class="text-[11px] font-tech text-slate-400 mb-3">Pendapatan vs Pengeluaran Realtime (Jan - Des)</p>
                    </div>
                    <div class="relative h-64 md:h-72 lg:h-80 w-full my-auto">
                        <canvas id="chartFinancialPerformance"></canvas>
                    </div>
                </div>

                <!-- KOLOM 2 & 3: METRIK KEUANGAN & RATE USD TERBARU -->
                <div class="lg:col-span-2 space-y-4 flex flex-col justify-between">
                    
                    <div class="space-y-1">
                        <p class="text-[11px] font-tech text-slate-400">Monitoring performa finansial dan transaksi terkini</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            
                            <div class="inner-card-bg border border-[#00c3ff30] rounded-xl p-3 flex flex-col justify-between shadow-lg">
                                <div class="flex justify-between items-center text-[11px] font-tech">
                                    <span class="font-header text-cyan-400 font-bold tracking-wider">TOTAL PENDAPATAN</span>
                                </div>
                                <div class="my-2">
                                    <h2 class="text-lg md:text-xl font-black font-tech text-white">
                                        Rp <?= number_format($financialSummary['total_pendapatan'], 0, ',', '.'); ?>
                                    </h2>
                                </div>
                                <p class="text-[10px] font-tech text-slate-400">Database Akumulasi</p>
                            </div>

                            <div class="inner-card-bg border border-[#a855f730] rounded-xl p-3 flex flex-col justify-between shadow-lg">
                                <div class="flex justify-between items-center text-[11px] font-tech">
                                    <span class="font-header text-purple-400 font-bold tracking-wider">TOTAL BEBAN</span>
                                </div>
                                <div class="my-2">
                                    <h2 class="text-lg md:text-xl font-black font-tech text-white">
                                        Rp <?= number_format($financialSummary['total_beban'], 0, ',', '.'); ?>
                                    </h2>
                                </div>
                                <p class="text-[10px] font-tech text-slate-400">
                                    <?= $financialSummary['total_transaksi']; ?> transaksi
                                </p>
                            </div>

                            <div class="inner-card-bg border border-[#00ff8830] rounded-xl p-3 flex flex-col justify-between shadow-lg">
                                <div class="flex justify-between items-center text-[11px] font-tech">
                                    <span class="font-header text-emerald-400 font-bold tracking-wider">LABA BERSIH</span>
                                </div>
                                <div class="my-2">
                                    <h2 class="text-lg md:text-xl font-black font-tech <?= $financialSummary['laba_bersih'] < 0 ? 'text-rose-400' : 'text-emerald-400'; ?>">
                                        Rp <?= number_format($financialSummary['laba_bersih'], 0, ',', '.'); ?>
                                    </h2>
                                </div>
                                <p class="text-[10px] font-tech text-slate-400">Margin operasional</p>
                            </div>

                            <!-- CARD RATE TERBARU DENGAN TEKS PERIODE -->
                            <div class="inner-card-bg border border-[#00c3ff30] rounded-xl p-3 flex flex-col justify-between shadow-lg">
                                <div class="flex justify-between items-center text-[11px] font-tech">
                                    <span class="font-header text-cyan-400 font-bold tracking-wider">
                                        RATE TERBARU <span class="text-[10px] text-slate-300 font-normal lowercase font-tech"><?= htmlspecialchars($financialSummary['rate_periode']); ?></span>
                                    </span>
                                </div>
                                <div class="my-2">
                                    <h2 class="text-lg md:text-xl font-black font-tech text-white">
                                        <?= $financialSummary['rate_val']; ?>
                                    </h2>
                                </div>
                                <p class="text-[10px] font-tech <?= $financialSummary['rate_status_cls']; ?> truncate" title="<?= htmlspecialchars($financialSummary['rate_status']); ?>">
                                    <?= $financialSummary['rate_status']; ?>
                                </p>
                            </div>

                        </div>
                    </div>

                    <!-- TABEL MATERIAL OUT & BUDGET MONITORING -->
                    <div class="overflow-x-auto inner-card-bg rounded-lg border border-[#143550]">
                        <table class="w-full text-left text-xs font-tech border-collapse">
                            <thead class="text-slate-400 uppercase text-[10px] border-b border-[#143550] bg-[#081624]">
                                <tr>
                                    <th class="p-2.5">NAMA / KODE MATERIAL</th>
                                    <th class="p-2.5 text-right">BUDGET RAB</th>
                                    <th class="p-2.5 text-right">MATERIAL OUT</th>
                                    <th class="p-2.5 text-right">SISA BUDGET</th>
                                    <th class="p-2.5 text-center">USAGE %</th>
                                    <th class="p-2.5 text-center">STATUS</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#143550]/40 text-slate-200">
                                <?php if (empty($tableItems)): ?>
                                    <tr>
                                        <td colspan="6" class="p-4 text-center text-slate-500 italic">
                                            Tidak ada data material untuk proyek ini.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach (array_slice($tableItems, 0, 5) as $item): 
                                        $statusBg = 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40';
                                        if (strtoupper($item['status']) === 'OVERBUDGET') {
                                            $statusBg = 'bg-rose-500/20 text-rose-300 border-rose-500/40';
                                        } elseif (strtoupper($item['status']) === 'WARNING') {
                                            $statusBg = 'bg-amber-500/20 text-amber-300 border-amber-500/40';
                                        }
                                    ?>
                                        <tr>
                                            <td class="p-2.5">
                                                <div class="font-bold text-white"><?= htmlspecialchars($item['item_name']); ?></div>
                                                <div class="text-[10px] text-slate-400"><?= htmlspecialchars($item['item_code']); ?></div>
                                            </td>
                                            <td class="p-2.5 text-right font-mono text-slate-300">
                                                <?= number_format((float)$item['budget_qty'], 2, ',', '.'); ?>
                                            </td>
                                            <td class="p-2.5 text-right font-mono text-emerald-400 font-semibold">
                                                <?= number_format((float)$item['out_qty'], 2, ',', '.'); ?>
                                            </td>
                                            <td class="p-2.5 text-right font-mono font-semibold <?= (float)$item['remaining_qty'] < 0 ? 'text-rose-400' : 'text-cyan-300'; ?>">
                                                <?= number_format((float)$item['remaining_qty'], 2, ',', '.'); ?>
                                            </td>
                                            <td class="p-2.5 text-center font-bold text-slate-200">
                                                <?= number_format((float)$item['usage_pct'], 1, ',', '.'); ?>%
                                            </td>
                                            <td class="p-2.5 text-center">
                                                <span class="px-2 py-0.5 text-[9px] font-bold rounded border <?= $statusBg; ?>">
                                                    <?= htmlspecialchars($item['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>

            </div>
        </div>

        <!-- TABEL RINCIAN LENGKAP & DONUT CHART CENTERED -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-stretch">
            
            <!-- TABEL RINCIAN PENGGUNAAN MATERIAL OUT PROYEK -->
            <div class="lg:col-span-2 card-bg rounded-lg shadow-lg overflow-hidden flex flex-col justify-between">
                <div class="p-3 border-b border-[#143550] flex justify-between items-center">
                    <h3 class="text-xs font-bold text-cyan-400 font-header uppercase tracking-wider">RINCIAN PENGGUNAAN MATERIAL OUT PROYEK</h3>
                    <span class="text-[10px] font-tech text-slate-400">Total Item: <?= count($tableItems); ?></span>
                </div>
                
                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-left text-xs font-tech">
                        <thead class="bg-[#05101a] text-slate-400 uppercase text-[10px] border-b border-[#143550]">
                            <tr>
                                <th class="p-3">NAMA / KODE MATERIAL</th>
                                <th class="p-3 text-right">BUDGET RAB</th>
                                <th class="p-3 text-right">MATERIAL OUT</th>
                                <th class="p-3 text-right">SISA BUDGET</th>
                                <th class="p-3 text-center">PERSENTASE (%)</th>
                                <th class="p-3 text-center">STATUS</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#143550]/40 text-slate-200">
                            <?php if (empty($tableItems)): ?>
                                <tr>
                                    <td colspan="6" class="p-8 text-center text-slate-500 italic">
                                        Belum ada data material RAB atau transaksi material out untuk proyek ini.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tableItems as $item): 
                                    $statusBg = 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40';
                                    if (strtoupper($item['status']) === 'OVERBUDGET') {
                                        $statusBg = 'bg-rose-500/20 text-rose-300 border-rose-500/40';
                                    } elseif (strtoupper($item['status']) === 'WARNING') {
                                        $statusBg = 'bg-amber-500/20 text-amber-300 border-amber-500/40';
                                    }
                                ?>
                                    <tr class="hover:bg-[#05101a]/50 transition-colors">
                                        <td class="p-3">
                                            <div class="font-bold text-white"><?= htmlspecialchars($item['item_name']); ?></div>
                                            <div class="text-[10px] text-slate-400"><?= htmlspecialchars($item['item_code']); ?></div>
                                        </td>
                                        <td class="p-3 text-right font-mono text-slate-300">
                                            <?= number_format((float)$item['budget_qty'], 2, ',', '.'); ?> <?= htmlspecialchars($item['unit_measurement']); ?>
                                        </td>
                                        <td class="p-3 text-right font-mono text-emerald-400 font-semibold">
                                            <?= number_format((float)$item['out_qty'], 2, ',', '.'); ?> <?= htmlspecialchars($item['unit_measurement']); ?>
                                        </td>
                                        <td class="p-3 text-right font-mono font-semibold <?= (float)$item['remaining_qty'] < 0 ? 'text-rose-400' : 'text-cyan-300'; ?>">
                                            <?= number_format((float)$item['remaining_qty'], 2, ',', '.'); ?>
                                        </td>
                                        <td class="p-3 text-center font-bold text-slate-200">
                                            <?= number_format((float)$item['usage_pct'], 2, ',', '.'); ?>%
                                        </td>
                                        <td class="p-3 text-center">
                                            <span class="px-2 py-0.5 text-[9px] font-bold rounded border <?= $statusBg; ?>">
                                                <?= htmlspecialchars($item['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- DONUT CHART STATUS PENYERAPAN (PERBAIKAN: CENTER-ALIGNED IN CONTAINER) -->
            <div class="lg:col-span-1 card-bg rounded-lg p-5 shadow-lg flex flex-col h-full">
                <!-- Header Card -->
                <div class="border-b border-[#143550] pb-2 mb-2">
                    <h3 class="text-xs font-bold text-cyan-400 font-header uppercase tracking-wider">STATUS PENYERAPAN</h3>
                </div>
                
                <!-- Wrapper Canvas & Overlay Text Terpusat di Tengah Kartu -->
                <div class="flex-1 flex items-center justify-center relative w-full my-auto min-h-[280px]">
                    <canvas id="chartStatusPenyerapan"></canvas>
                    
                    <!-- Center Overlay Text Presisi -->
                    <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none text-center">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest font-tech">OVERVIEW</span>
                        <span class="text-2xl font-black text-white font-tech leading-none mt-0.5">100%</span>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <!-- JAVASCRIPT & CHART.JS CONFIGURATION -->
    <script>
        // Registrasi Plugin DataLabels Chart.js
        Chart.register(ChartDataLabels);

        // 1. Donut Chart Status Penyerapan (Centered & Balanced Layout)
        const ctxStatus = document.getElementById('chartStatusPenyerapan').getContext('2d');
        new Chart(ctxStatus, {
            type: 'doughnut',
            data: {
                labels: ['On Track', 'Warning', 'Overbudget'],
                datasets: [{
                    data: [
                        <?= $pctOnTrack; ?>, 
                        <?= $pctWarning; ?>, 
                        <?= $pctOverbudget; ?>
                    ],
                    backgroundColor: [
                        '#0ed00079', // emerald green (On Track)
                        '#a855f7', // Purple Neon (Warning)
                        '#ff0055'  // Red Neon (Overbudget)
                    ],
                    borderWidth: 0,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                rotation: -90,
                layout: {
                    padding: 10
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    datalabels: {
                        color: '#ffffff',
                        font: {
                            family: 'Rajdhani',
                            size: 11,
                            weight: 'bold'
                        },
                        formatter: function(value, context) {
                            if (value <= 0) return '';
                            let label = context.chart.data.labels[context.dataIndex];
                            return value + '%\n' + label;
                        },
                        textAlign: 'center',
                        anchor: 'center',
                        align: 'center'
                    }
                }
            }
        });

        // 2. Line Chart Performa Keuangan Bulanan (Jan - Des)
        const ctxFin = document.getElementById('chartFinancialPerformance').getContext('2d');
        new Chart(ctxFin, {
            type: 'line',
            data: {
                labels: <?= json_encode($monthlyLabels); ?>,
                datasets: [
                    {
                        label: 'Pendapatan',
                        data: <?= json_encode($monthlyIncomeData); ?>,
                        borderColor: '#38bdf8',
                        backgroundColor: 'rgba(56, 189, 248, 0.15)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointBackgroundColor: '#38bdf8'
                    },
                    {
                        label: 'Pengeluaran',
                        data: <?= json_encode($monthlyExpenseData); ?>,
                        borderColor: '#a855f7',
                        backgroundColor: 'rgba(168, 85, 247, 0.15)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointBackgroundColor: '#a855f7'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { 
                        display: true, 
                        position: 'top', 
                        labels: { color: '#94a3b8', font: { family: 'Rajdhani', size: 11 } } 
                    },
                    datalabels: {
                        display: false
                    }
                },
                scales: {
                    x: { 
                        ticks: { color: '#94a3b8', font: { family: 'Rajdhani', size: 10 } }, 
                        grid: { color: '#143550' } 
                    },
                    y: { 
                        ticks: { 
                            color: '#94a3b8', 
                            font: { family: 'Rajdhani', size: 10 },
                            callback: function(value) {
                                if (value >= 1000000) {
                                    return 'Rp ' + (value / 1000000).toLocaleString('id-ID') + 'M';
                                }
                                return 'Rp ' + value.toLocaleString('id-ID');
                            }
                        }, 
                        grid: { color: '#143550' } 
                    }
                }
            }
        });
    </script>
</body>
</html>