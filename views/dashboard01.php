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

// 1. FETCH MENU (DYNAMIC ACCESS MENU)
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

// 3. FETCH MONITORING DATA (MATERIAL OUT & BUDGET - DINAMIS DB)
$kpiData = ['budget_rab' => 0, 'material_out' => 0, 'sisa_budget' => 0, 'persentase' => 0];
$tableItems = [];
$penyerapanStatusCount = ['On Track' => 0, 'Warning' => 0];

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

            if ($row['status'] === 'WARNING' || $row['status'] === 'OVERBUDGET') {
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

// 4. FETCH RINGKASAN FINANSIAL & RATE TERBARU DARI DB INTERNAL
$financialSummary = [
    'total_pendapatan'  => 0,
    'growth_pendapatan' => 0,
    'total_beban'       => 0,
    'total_transaksi'   => 0,
    'laba_bersih'       => 0,
    'rate_val'          => 'Rp 0',
    'rate_updated'      => '-',
    'rate_status'       => 'Gagal terhubung ke server.',
    'rate_status_cls'   => 'text-rose-400'
];

try {
    // Total Pendapatan Bulan Ini & Growth
    $stmtIncome = $db->prepare("
        SELECT 
            COALESCE(SUM(amount), 0) AS total_inc,
            COALESCE(SUM(CASE WHEN created_at >= date_trunc('month', CURRENT_DATE - INTERVAL '1 month') 
                             AND created_at < date_trunc('month', CURRENT_DATE) THEN amount ELSE 0 END), 0) AS prev_inc
        FROM financial_incomes 
        WHERE project_id = :prj_id 
          AND created_at >= date_trunc('month', CURRENT_DATE)
    ");
    $stmtIncome->execute(['prj_id' => $selected_project_id]);
    $incData = $stmtIncome->fetch(PDO::FETCH_ASSOC);
    if ($incData) {
        $financialSummary['total_pendapatan'] = (float)$incData['total_inc'];
        if ((float)$incData['prev_inc'] > 0) {
            $financialSummary['growth_pendapatan'] = round((($incData['total_inc'] - $incData['prev_inc']) / $incData['prev_inc']) * 100, 1);
        }
    }

    // Total Beban & Jumlah Transaksi
    $stmtExpense = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_exp, COUNT(id) AS cnt_trans 
        FROM financial_expenses 
        WHERE project_id = :prj_id 
          AND created_at >= date_trunc('month', CURRENT_DATE)
    ");
    $stmtExpense->execute(['prj_id' => $selected_project_id]);
    $expData = $stmtExpense->fetch(PDO::FETCH_ASSOC);
    if ($expData) {
        $financialSummary['total_beban'] = (float)$expData['total_exp'];
        $financialSummary['total_transaksi'] = (int)$expData['cnt_trans'];
    }

    // Laba Bersih
    $financialSummary['laba_bersih'] = $financialSummary['total_pendapatan'] - $financialSummary['total_beban'];

    // Rate Terbaru dari DB Internal Server
    $stmtRate = $db->prepare("
        SELECT rate_val, updated_at 
        FROM m_currency_rate 
        WHERE active = true 
        ORDER BY updated_at DESC LIMIT 1
    ");
    $stmtRate->execute();
    $rateData = $stmtRate->fetch(PDO::FETCH_ASSOC);

    if ($rateData && isset($rateData['rate_val'])) {
        $financialSummary['rate_val']        = "Rp " . number_format($rateData['rate_val'], 0, ',', '.');
        $financialSummary['rate_updated']    = date('d/m/Y H:i', strtotime($rateData['updated_at']));
        $financialSummary['rate_status']     = "● Internal DB Server Connected";
        $financialSummary['rate_status_cls'] = "text-emerald-400";
    } else {
        $financialSummary['rate_status']     = "Data rate tidak ditemukan di database.";
        $financialSummary['rate_status_cls'] = "text-amber-400";
    }

} catch (Exception $e) {
    $financialSummary['rate_status']     = "Gagal terhubung ke server database.";
    $financialSummary['rate_status_cls'] = "text-rose-400";
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

        <!-- FINANCIAL AGING ANALYSIS (DI DALAMNYA TERDAPAT 4 CARDS GAMBAR 1 DINAMIS) -->
        <div class="card-bg rounded-lg p-4 shadow-lg space-y-4">
            <div class="flex justify-between items-center border-b border-[#143550] pb-3">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-coins text-purple-400 text-xs"></i>
                    <h2 class="text-xs font-bold text-cyan-400 font-header uppercase tracking-wider">FINANCIAL AGING ANALYSIS</h2>
                    <span class="text-[10px] text-slate-400 font-tech ml-2">Monitoring Umur Piutang (AR) & Hutang (AP) berdasarkan Jatuh Tempo</span>
                </div>
                <span class="bg-rose-950 text-rose-400 border border-rose-800 text-[10px] font-bold px-2.5 py-0.5 rounded-full font-tech">
                    ● Overdue Alert: Rp 30.000.000
                </span>
            </div>

            <!-- 4 CARDS GAMBAR 1 (SEMUA DATA DINAMIS DB INTERNAL SERVER) -->
            <div class="space-y-1">
                <p class="text-[11px] font-tech text-slate-400">Monitoring performa finansial dan transaksi terkini</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    
                    <!-- Card 1: TOTAL PENDAPATAN (DINAMIS DB) -->
                    <div class="inner-card-bg border border-[#00c3ff30] rounded-xl p-4 flex flex-col justify-between shadow-lg">
                        <div class="flex justify-between items-center text-xs font-tech">
                            <span class="font-header text-cyan-400 font-bold tracking-wider">TOTAL PENDAPATAN</span>
                            <span class="text-cyan-300/70">Bulan Ini</span>
                        </div>
                        <div class="my-3">
                            <h2 class="text-2xl md:text-3xl font-black font-tech text-white">
                                Rp <?= number_format($financialSummary['total_pendapatan'], 0, ',', '.'); ?>
                            </h2>
                        </div>
                        <p class="text-[11px] font-tech text-slate-400">
                            <?= ($financialSummary['growth_pendapatan'] >= 0 ? '+' : '') . $financialSummary['growth_pendapatan']; ?>% dari bulan lalu
                        </p>
                    </div>

                    <!-- Card 2: TOTAL BEBAN (DINAMIS DB) -->
                    <div class="inner-card-bg border border-[#a855f730] rounded-xl p-4 flex flex-col justify-between shadow-lg">
                        <div class="flex justify-between items-center text-xs font-tech">
                            <span class="font-header text-purple-400 font-bold tracking-wider">TOTAL BEBAN</span>
                            <span class="text-purple-300/70">Bulan Ini</span>
                        </div>
                        <div class="my-3">
                            <h2 class="text-2xl md:text-3xl font-black font-tech text-white">
                                Rp <?= number_format($financialSummary['total_beban'], 0, ',', '.'); ?>
                            </h2>
                        </div>
                        <p class="text-[11px] font-tech text-slate-400">
                            <?= $financialSummary['total_transaksi']; ?> transaksi terproses
                        </p>
                    </div>

                    <!-- Card 3: LABA BERSIH (DINAMIS DB) -->
                    <div class="inner-card-bg border border-[#00ff8830] rounded-xl p-4 flex flex-col justify-between shadow-lg">
                        <div class="flex justify-between items-center text-xs font-tech">
                            <span class="font-header text-emerald-400 font-bold tracking-wider">LABA BERSIH</span>
                            <span class="text-emerald-300/70">Estimasi</span>
                        </div>
                        <div class="my-3">
                            <h2 class="text-2xl md:text-3xl font-black font-tech <?= $financialSummary['laba_bersih'] < 0 ? 'text-rose-400' : 'text-emerald-400'; ?>">
                                Rp <?= number_format($financialSummary['laba_bersih'], 0, ',', '.'); ?>
                            </h2>
                        </div>
                        <p class="text-[11px] font-tech text-slate-400">Margin operasional terhitung</p>
                    </div>

                    <!-- Card 4: RATE TERBARU (DINAMIS DB INTERNAL) -->
                    <div class="inner-card-bg border border-[#00c3ff30] rounded-xl p-4 flex flex-col justify-between shadow-lg">
                        <div class="flex justify-between items-center text-xs font-tech">
                            <span class="font-header text-cyan-400 font-bold tracking-wider">RATE TERBARU</span>
                            <span class="text-slate-400">Updated: <?= $financialSummary['rate_updated']; ?></span>
                        </div>
                        <div class="my-3">
                            <h2 class="text-xl md:text-2xl font-black font-tech text-white">
                                <?= $financialSummary['rate_val']; ?>
                            </h2>
                        </div>
                        <p class="text-[11px] font-tech <?= $financialSummary['rate_status_cls']; ?>">
                            <?= $financialSummary['rate_status']; ?>
                        </p>
                    </div>

                </div>
            </div>

            <!-- AR / AP Aging Summary -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 font-tech pt-2">
                <div class="inner-card-bg p-3 rounded">
                    <p class="text-[10px] font-bold text-cyan-400 uppercase">TOTAL PIUTANG (AR)</p>
                    <h3 class="text-xl font-bold text-cyan-400 mt-1">Rp 775.000.000</h3>
                    <div class="flex justify-between text-[10px] text-slate-400 mt-2">
                        <span>Lancar (&lt;30 hr): 58%</span>
                        <span>Jatuh Tempo: 16%</span>
                    </div>
                </div>

                <div class="inner-card-bg p-3 rounded">
                    <p class="text-[10px] font-bold text-purple-400 uppercase">TOTAL HUTANG (AP)</p>
                    <h3 class="text-xl font-bold text-purple-300 mt-1">Rp 500.000.000</h3>
                    <div class="flex justify-between text-[10px] text-slate-400 mt-2">
                        <span>Lancar (&lt;30 hr): 64%</span>
                        <span>Overdue &gt;90 hr: 0%</span>
                    </div>
                </div>

                <div class="inner-card-bg p-3 rounded">
                    <p class="text-[10px] font-bold text-amber-400 uppercase">PIUTANG BERISIKO (61 - 90 HARI)</p>
                    <h3 class="text-xl font-bold text-amber-400 mt-1">Rp 85.000.000</h3>
                    <p class="text-[10px] text-amber-300/80 mt-2">Perlu Follow-up Tagihan</p>
                </div>

                <div class="inner-card-bg p-3 rounded">
                    <p class="text-[10px] font-bold text-rose-400 uppercase">MACET / CRITICAL (&gt; 90 HARI)</p>
                    <h3 class="text-xl font-bold text-rose-400 mt-1">Rp 30.000.000</h3>
                    <p class="text-[10px] text-rose-300/80 mt-2">Tindakan Khusus / SP</p>
                </div>
            </div>

            <!-- Aging Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs font-tech border-collapse">
                    <thead class="text-slate-400 uppercase text-[10px] border-b border-[#143550] bg-[#05101a]">
                        <tr>
                            <th class="p-3">KATEGORI FINANCIAL</th>
                            <th class="p-3 text-right">&lt;30 HARI<br><span class="text-slate-500 font-normal">LANCAR</span></th>
                            <th class="p-3 text-right">31 - 60 HARI<br><span class="text-slate-500 font-normal">PERHATIAN</span></th>
                            <th class="p-3 text-right">61 - 90 HARI<br><span class="text-slate-500 font-normal">TERHAMBAT</span></th>
                            <th class="p-3 text-right">&gt; 90 HARI<br><span class="text-slate-500 font-normal">KRITIS</span></th>
                            <th class="p-3 text-right">TOTAL PORTFOLIO</th>
                            <th class="p-3 text-center">STATUS / RISIKO</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#143550]/40 text-slate-200">
                        <tr>
                            <td class="p-3">
                                <div class="font-bold text-cyan-400">● Account Receivable (AR)</div>
                                <div class="text-[10px] text-slate-400">Piutang Termin Customer & Owner Proyek</div>
                            </td>
                            <td class="p-3 text-right font-mono text-emerald-400">Rp 450.000.000<br><span class="text-[10px] text-slate-500">58.1%</span></td>
                            <td class="p-3 text-right font-mono text-cyan-400">Rp 210.000.000<br><span class="text-[10px] text-slate-500">27.1%</span></td>
                            <td class="p-3 text-right font-mono text-amber-400">Rp 85.000.000<br><span class="text-[10px] text-slate-500">11.0%</span></td>
                            <td class="p-3 text-right font-mono text-rose-400">Rp 30.000.000<br><span class="text-[10px] text-slate-500">3.9%</span></td>
                            <td class="p-3 text-right font-mono font-bold text-white">Rp 775.000.000</td>
                            <td class="p-3 text-center">
                                <span class="bg-amber-500/20 text-amber-400 border border-amber-500/40 text-[9px] px-2 py-0.5 rounded font-bold">⚠ WASPADA</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="p-3">
                                <div class="font-bold text-purple-400">● Account Payable (AP)</div>
                                <div class="text-[10px] text-slate-400">Hutang Usaha Subkon & Vendor Material</div>
                            </td>
                            <td class="p-3 text-right font-mono text-emerald-400">Rp 320.000.000<br><span class="text-[10px] text-slate-500">64.0%</span></td>
                            <td class="p-3 text-right font-mono text-cyan-400">Rp 140.000.000<br><span class="text-[10px] text-slate-500">28.0%</span></td>
                            <td class="p-3 text-right font-mono text-amber-400">Rp 40.000.000<br><span class="text-[10px] text-slate-500">8.0%</span></td>
                            <td class="p-3 text-right font-mono text-slate-400">Rp 0<br><span class="text-[10px] text-slate-500">0.0%</span></td>
                            <td class="p-3 text-right font-mono font-bold text-white">Rp 500.000.000</td>
                            <td class="p-3 text-center">
                                <span class="bg-emerald-500/20 text-emerald-400 border border-emerald-500/40 text-[9px] px-2 py-0.5 rounded font-bold">✓ SEHAT</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- RINCIAN PENGGUNAAN MATERIAL OUT (DINAMIS DB) -->
        <!-- ========================================== -->
        <div class="card-bg rounded-lg shadow-lg overflow-hidden">
            <div class="p-3 border-b border-[#143550] flex justify-between items-center">
                <h3 class="text-xs font-bold text-cyan-400 font-header uppercase tracking-wider">RINCIAN PENGGUNAAN MATERIAL OUT PROYEK</h3>
                <span class="text-[10px] font-tech text-slate-400">Total Item: <?= count($tableItems); ?></span>
            </div>
            
            <div class="overflow-x-auto">
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

    </div>

</body>
</html>