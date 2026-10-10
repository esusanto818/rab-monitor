<?php
## File: ./views/dashboard.php
----------------------------------------

/*
    Document        : views/dashboard.php
    Description     : Dashboard Monitoring Material Out & Budget RAB - Theme Matched to Data Flow Diagram
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

// 1. FETCH MENU
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

// 3. FETCH MONITORING DATA
$kpiData = ['budget_rab' => 0, 'material_out' => 0, 'sisa_budget' => 0, 'persentase' => 0];
$statusCounts = ['ON_TRACK' => 0, 'WARNING' => 0, 'OVERBUDGET' => 0];
$chartLabels = []; 
$chartBudget = []; 
$chartOut = []; 
$tableItems = [];

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
            $chartLabels[] = $row['item_name'];
            $chartBudget[] = (float)$row['budget_qty'];
            $chartOut[]    = (float)$row['out_qty'];
            $kpiData['budget_rab']   += (float)$row['budget_qty'];
            $kpiData['material_out'] += (float)$row['out_qty'];

            if ((float)$row['budget_qty'] > 0 || (float)$row['out_qty'] > 0) {
                if ($row['status'] === 'OVERBUDGET') {
                    $statusCounts['OVERBUDGET']++;
                } elseif ($row['status'] === 'WARNING') {
                    $statusCounts['WARNING']++;
                } else {
                    $statusCounts['ON_TRACK']++;
                }
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

// VALIDASI HASDATA
$hasData = ($kpiData['budget_rab'] > 0 || $kpiData['material_out'] > 0);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>[XYZ] Monitoring Material Out & Budget RAB</title>

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

    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* CSS Reset & Scrollbar Override dengan Skema Warna Diagram (Gbr 1) */
        html, body {
            height: 100% !important;
            max-height: none !important;
            overflow-x: hidden !important;
            overflow-y: auto !important;
            background-color: #06131d !important;
        }
        .field-button .x-btn-mc {
            padding: 8px !important;
        }
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
        .border-slate-800 {
            border-color: #1a3a52 !important;
        }

        /* Gradien KPI disesuaikan dengan skema Diagram */
        .backColorCyan {
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.25), rgba(6, 182, 212, 0.1));
            border: 1px solid rgba(56, 189, 248, 0.4);
        }
        .backColorGreen {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.25), rgba(52, 211, 153, 0.1));
            border: 1px solid rgba(52, 211, 153, 0.4);
        }
        .backColorBlueDark {
            background: linear-gradient(135deg, rgba(30, 58, 138, 0.4), rgba(15, 23, 42, 0.4));
            border: 1px solid rgba(59, 130, 246, 0.4);
        }
        .backColorPurple {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.25), rgba(168, 85, 247, 0.1));
            border: 1px solid rgba(192, 132, 252, 0.4);
        }
    </style>
</head>
<body class="bg-[#06131d] text-cyan-100 font-body antialiased min-h-screen p-4 md:p-6 relative">

    <!-- NAVBAR WITH BRAND LOGO -->
    <nav class="relative bg-[#091a29]/90 border border-[#1d4c6d] rounded-xl shadow-2xl mb-6 backdrop-blur-md">
        <div class="flex items-center justify-between h-14 px-4">
            
            <div class="flex items-center gap-6">
                <!-- LOGO/BRAND -->
                <a href="#" class="flex items-center gap-3 group">
                    <?php if (file_exists(__DIR__ . '/../assets/logo_c.png')): ?>
                        <img src="../assets/logo_c.png" alt="Logo" class="h-8 w-auto object-contain">
                    <?php else: ?>
                        <div class="w-9 h-9 rounded-lg bg-gradient-to-tr from-cyan-500 to-sky-400 p-0.5 shadow-lg shadow-cyan-500/30 group-hover:shadow-cyan-500/50 transition-all">
                            <div class="w-full h-full bg-[#06131d] rounded-[7px] flex items-center justify-center">
                                <span class="font-header text-cyan-400 font-black text-xs tracking-tighter">XYZ</span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <span class="font-header text-sm font-bold tracking-wider text-white hidden sm:inline-block">
                        [XYZ]<span class="text-cyan-400">SYS</span>
                    </span>
                </a>

                <!-- NAV MENU -->
                <ul class="flex items-center space-x-1 sm:space-x-3 lg:space-x-5 font-header text-xs lg:text-xs tracking-wider font-semibold border-l border-[#1a3a52] pl-4 sm:pl-6">
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
                        <div class="dropdown-menu absolute left-0 right-0 top-full mt-1 w-full bg-[#091a29]/95 backdrop-blur-md border border-[#1d4c6d] rounded-xl shadow-2xl p-5 text-cyan-100 z-50">
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
                                    <h3 class="font-tech text-cyan-400 font-bold text-sm tracking-widest uppercase border-b border-[#1d4c6d] pb-1">
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

            <!-- LOGOUT -->
            <a href="../logout.php" class="h-8 bg-rose-600/80 hover:bg-rose-600 text-white font-header text-xs tracking-widest px-3.5 rounded-lg flex items-center justify-center transition-colors shadow-md border border-rose-500/40">
                <span>Logout</span>
            </a>
        </div>
    </nav>

    <!-- HEADER & PROJECT SELECTOR -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4 border-b border-[#1a3a52] pb-4">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-wide">Monitoring Material & Budget RAB</h1>
            <p class="text-sm text-cyan-300/70 mt-1">Pengawasan Efisiensi Penyerapan Material Proyek secara Real-time</p>
        </div>
        
        <form method="GET" action="" class="flex items-center gap-3">
            <label for="project_id" class="text-sm text-cyan-200 font-medium">Pilih Proyek:</label>
            <select name="project_id" id="project_id" onchange="this.form.submit()" class="bg-[#0b2234] text-cyan-100 border border-[#1d4c6d] text-sm rounded-lg p-2.5 focus:outline-none focus:border-cyan-400 shadow-md min-w-[250px]">
                <?php foreach ($projectsList as $prj): ?>
                    <option value="<?= $prj['id']; ?>" <?= $prj['id'] == $selected_project_id ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($prj['project_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- KPIS -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="backColorCyan p-4 rounded-xl shadow-lg">
            <span class="text-xs text-cyan-300/80 uppercase font-semibold">Total Budget RAB</span>
            <div class="text-2xl font-bold text-cyan-300 mt-1"><?= number_format($kpiData['budget_rab'], 2, ',', '.'); ?></div>
        </div>
        <div class="backColorGreen p-4 rounded-xl shadow-lg">
            <span class="text-xs text-emerald-300/80 uppercase font-semibold">Material Out</span>
            <div class="text-2xl font-bold text-emerald-400 mt-1"><?= number_format($kpiData['material_out'], 2, ',', '.'); ?></div>
        </div>
        <div class="backColorBlueDark p-4 rounded-xl shadow-lg">
            <span class="text-xs text-sky-300/80 uppercase font-semibold">Sisa Budget</span>
            <div class="text-2xl font-bold <?= $kpiData['sisa_budget'] < 0 ? 'text-rose-400' : 'text-sky-300'; ?> mt-1">
                <?= number_format($kpiData['sisa_budget'], 2, ',', '.'); ?>
            </div>
        </div>
        <div class="backColorPurple p-4 rounded-xl shadow-lg">
            <span class="text-xs text-purple-300/80 uppercase font-semibold">Persentase Penyerapan</span>
            <div class="text-2xl font-bold text-purple-300 mt-1"><?= $kpiData['persentase']; ?>%</div>
        </div>
    </div>

    <!-- CHARTS -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="lg:col-span-2 bg-[#091a29]/80 border border-[#1a3a52] p-5 rounded-xl shadow-lg flex flex-col">
            <h3 class="text-sm font-semibold text-cyan-200 mb-4">Perbandingan Budget vs Material Out</h3>
            <div class="h-64 relative flex-1 flex items-center justify-center" id="barContainer">
                <?php if ($hasData): ?>
                    <canvas id="barChart"></canvas>
                <?php else: ?>
                    <div class="text-cyan-500 text-sm font-medium">Tidak ada data transaksi material untuk proyek ini.</div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="bg-[#091a29]/80 border border-[#1a3a52] p-5 rounded-xl shadow-lg flex flex-col justify-center items-center">
            <h3 class="text-sm font-semibold text-cyan-200 mb-2 self-start">STATUS PENYERAPAN</h3>
            <div class="w-full h-80 relative flex items-center justify-center">
                <canvas id="doughnutChart"></canvas>
            </div>
        </div>
    </div>

    <!-- RINCIAN TABLE -->
    <div class="bg-[#091a29]/80 border border-[#1a3a52] rounded-xl shadow-lg overflow-hidden mb-12">
        <div class="p-4 border-b border-[#1a3a52]">
            <h3 class="text-sm font-semibold text-cyan-100">Rincian Penggunaan Material Out Proyek</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-cyan-100">
                <thead class="bg-[#0b2234] text-cyan-300 text-xs uppercase tracking-wider">
                    <tr>
                        <th class="p-3.5">Nama / Kode Material</th>
                        <th class="p-3.5 text-right">Budget RAB</th>
                        <th class="p-3.5 text-right">Material Out</th>
                        <th class="p-3.5 text-right">Sisa Budget</th>
                        <th class="p-3.5 text-center">Persentase (%)</th>
                        <th class="p-3.5 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#1a3a52]">
                    <?php if (empty($tableItems) || !$hasData): ?>
                        <tr>
                            <td colspan="6" class="p-8 text-center text-cyan-500 italic">
                                Belum ada data material RAB atau transaksi material out untuk proyek ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tableItems as $item): 
                            $statusBg = 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40';
                            if ($item['status'] === 'OVERBUDGET') {
                                $statusBg = 'bg-rose-500/20 text-rose-300 border-rose-500/40';
                            } elseif ($item['status'] === 'WARNING') {
                                $statusBg = 'bg-purple-500/20 text-purple-300 border-purple-500/40';
                            }
                        ?>
                            <tr class="hover:bg-[#0d2a42]/50 transition-colors">
                                <td class="p-3.5">
                                    <div class="font-medium text-white"><?= htmlspecialchars($item['item_name']); ?></div>
                                    <div class="text-xs text-cyan-400/60"><?= htmlspecialchars($item['item_code']); ?></div>
                                </td>
                                <td class="p-3.5 text-right font-mono text-cyan-200"><?= number_format($item['budget_qty'], 2, ',', '.'); ?> <?= $item['unit_measurement']; ?></td>
                                <td class="p-3.5 text-right font-mono text-emerald-400"><?= number_format($item['out_qty'], 2, ',', '.'); ?> <?= $item['unit_measurement']; ?></td>
                                <td class="p-3.5 text-right font-mono <?= $item['remaining_qty'] < 0 ? 'text-rose-400' : 'text-cyan-200'; ?>"><?= number_format($item['remaining_qty'], 2, ',', '.'); ?></td>
                                <td class="p-3.5 text-center font-bold text-cyan-300"><?= $item['usage_pct']; ?>%</td>
                                <td class="p-3.5 text-center">
                                    <span class="px-2.5 py-1 text-xs font-semibold rounded-full border <?= $statusBg; ?>"><?= $item['status']; ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- JS CHARTS -->
    <script>
        const hasData = <?= $hasData ? 'true' : 'false'; ?>;
        const labels = <?= json_encode($chartLabels); ?>;
        const budgetData = <?= json_encode($chartBudget); ?>;
        const outData = <?= json_encode($chartOut); ?>;
        const statusCounts = <?= json_encode($statusCounts); ?>;

        if (hasData) {
            // 1. RENDER BAR CHART (Sesuai Skema Diagram)
            const barCtx = document.getElementById('barChart').getContext('2d');
            new Chart(barCtx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Budget RAB', data: budgetData, backgroundColor: '#22d3ee' },
                        { label: 'Material Out', data: outData, backgroundColor: '#34d399' }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { ticks: { color: '#7dd3fc' }, grid: { color: '#163852' } },
                        y: { ticks: { color: '#7dd3fc' }, grid: { color: '#163852' } }
                    },
                    plugins: { legend: { labels: { color: '#bae6fd' } } }
                }
            });

            // 2. PLUGIN LABELS TEKS MELINGKAR PRESISI DI LUAR LINGKARAN & CENTER TEXT
            const outerCalloutPlugin = {
                id: 'outerCalloutPlugin',
                afterDraw(chart) {
                    const { ctx, data } = chart;
                    const meta = chart.getDatasetMeta(0);
                    if (!meta || !meta.data || meta.data.length === 0) return;

                    const totalItems = data.datasets[0].data.reduce((a, b) => a + b, 0);

                    ctx.save();

                    // --- A. TEKS DI TENGAH LINGKARAN ---
                    const centerX = meta.data[0].x;
                    const centerY = meta.data[0].y;

                    ctx.font = '500 11px Inter, sans-serif';
                    ctx.fillStyle = '#7dd3fc';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText('OVERVIEW', centerX, centerY - 12);

                    ctx.font = 'bold 22px Inter, sans-serif';
                    ctx.fillStyle = '#FFFFFF';
                    ctx.fillText('100%', centerX, centerY + 12);

                    if (totalItems === 0) {
                        ctx.restore();
                        return;
                    }

                    // --- B. TEKS MELINGKAR DI LUAR LINGKARAN & PERSENTASE DI DALAM ---
                    meta.data.forEach((element, index) => {
                        const val = data.datasets[0].data[index];
                        if (val <= 0) return;

                        const pct = Math.round((val / totalItems) * 100);
                        const labelName = data.labels[index];

                        const startAngle = element.startAngle;
                        const endAngle = element.endAngle;
                        const midAngle = startAngle + (endAngle - startAngle) / 2;

                        const outerRadius = element.outerRadius;
                        const innerRadius = element.innerRadius;

                        // 1. Teks Persentase % di Dalam Slice
                        const pctRadius = innerRadius + (outerRadius - innerRadius) / 2;
                        const pctX = centerX + Math.cos(midAngle) * pctRadius;
                        const pctY = centerY + Math.sin(midAngle) * pctRadius;

                        ctx.font = 'bold 11px Inter, sans-serif';
                        ctx.fillStyle = '#FFFFFF';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        ctx.fillText(`${pct}%`, pctX, pctY);

                        // 2. Label Teks Melingkar Presisi di Luar Sektor
                        const textRadius = outerRadius + 22;
                        const textX = centerX + Math.cos(midAngle) * textRadius;
                        const textY = centerY + Math.sin(midAngle) * textRadius;

                        ctx.save();
                        ctx.translate(textX, textY);

                        let normalizedAngle = midAngle % (2 * Math.PI);
                        if (normalizedAngle < 0) normalizedAngle += 2 * Math.PI;

                        let rotation = midAngle + Math.PI / 2;

                        if (normalizedAngle > 0 && normalizedAngle < Math.PI) {
                            rotation -= Math.PI;
                        }

                        ctx.rotate(rotation);
                        ctx.font = '600 12px Inter, sans-serif';
                        ctx.fillStyle = '#e0f2fe';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        ctx.fillText(labelName, 0, 0);

                        ctx.restore();
                    });

                    ctx.restore();
                }
            };

            // 3. RENDER DOUGHNUT CHART
            const doughnutCtx = document.getElementById('doughnutChart').getContext('2d');
            new Chart(doughnutCtx, {
                type: 'doughnut',
                data: {
                    labels: ['On Track', 'Warning', 'Overbudget'],
                    datasets: [{
                        data: [statusCounts.ON_TRACK, statusCounts.WARNING, statusCounts.OVERBUDGET],
                        backgroundColor: [
                            '#0ea5e9',  // Cyan/Teal (On Track)
                            '#a855f7',  // Purple/Magenta (Warning)
                            '#f43f5e'   // Rose/Pink Magenta (Overbudget)
                        ],
                        borderColor: '#06131d',
                        borderWidth: 3,
                        cutout: '58%'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        padding: 48
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return ` ${context.label}: ${context.raw} Item`;
                                }
                            }
                        }
                    }
                },
                plugins: [outerCalloutPlugin]
            });
        } else {
            // RENDER DONUT KOSONG JIKA TIDAK ADA DATA
            const doughnutCtx = document.getElementById('doughnutChart').getContext('2d');
            new Chart(doughnutCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Tidak Ada Data'],
                    datasets: [{
                        data: [1],
                        backgroundColor: ['#163852'],
                        borderWidth: 0,
                        cutout: '70%'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: { enabled: false },
                        legend: { display: false }
                    }
                }
            });
        }
    </script>
</body>
</html>
