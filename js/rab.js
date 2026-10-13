/*
  Document      : rab.js
  Description   : Form Pengelolaan RAB Material Proyek
                   Multi currency: satu mata uang anggaran per proyek (mis. USD untuk proyek berdana luar negeri)
                   + kurs ke mata uang dasar. Harga satuan RAB dalam mata uang anggaran; PO boleh IDR atau USD
                   dan dibandingkan terhadap RAB setelah dikonversi.
  [NEW 2026-10-12] PLAFON = Nilai Kontrak (projects.contract_value). Layar RAB menampilkan Nilai Kontrak, Budget, Cadangan (kontrak - budget),
                   total RAB, cadangan terpakai & sisa. Aturan (divalidasi ulang server di rabCRD.php):
                   - total RAB tidak boleh melebihi Nilai Kontrak;
                   - proyek 'Belum Mulai': total RAB <= Budget;
                   - proyek berjalan: boleh melewati Budget -> memakai cadangan; baris baru / dinaikkan wajib beralasan (ditandai TAMBAHAN).
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "RAB <i>Material</i>";

    var dataRecord = null;
    var rabCur = { id: null, code: 'IDR', baseCode: 'IDR', isBase: true, rate: 1, locked: null };
    var rateManual = false, rateReq = 0;
    var hdrForm = null, fCur, fRate, fRateDate, fRateInfo;
    var plafon = null;      // [NEW] ringkasan plafon proyek yang sedang diedit (dari load_rab.plafon)

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }

    function statusColor(v) {
        if (v === 'OVERBUDGET') return '#c0392b !important';
        if (v === 'WARNING') return '#e6a817 !important';
        if (v === 'NO BUDGET') return '#888888 !important';
        return '#27ae60 !important';
    }

    // ------------------------------------------------------------
    // 0. Container Panel Detail Material (tampil di bawah grid utama)
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');
    var rabDtlDiv = document.createElement('div');
    rabDtlDiv.id = 'rabDtlContainer';
    rabDtlDiv.className = 'detail-panel-container';
    rabDtlDiv.style.display = 'none';

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(rabDtlDiv, gridContainer.nextSibling);
    }

    // Fungsi menghitung tinggi dinamis scrollbar detail panel
    function fitRabDtl() {
        var sc = document.getElementById('rabDtlScroll');
        if (!sc) return;
        var top = sc.getBoundingClientRect().top;
        var footer = 40;
        sc.style.maxHeight = Math.max(160, window.innerHeight - top - footer) + 'px';
    }
    window.addEventListener('resize', fitRabDtl);

    function loadRabDetailPanel(projectId, projectName) {
        Ext.Ajax.request({
            url: '../php-crud/rabCRD.php',
            method: 'POST',
            params: { task: 'load_rab', project_id: projectId },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    rabDtlDiv.innerHTML = '<div style="color:red; padding:10px;">Gagal memuat detail RAB.</div>';
                    rabDtlDiv.style.display = 'block';
                    return;
                }
                renderRabDetailPanel(res.data.items || [], projectName, res.data.currency, res.data.plafon);      // [CHANGED] + plafon
            },
            failure: function () {
                rabDtlDiv.innerHTML = '<div style="color:red; padding:10px;">Gagal terhubung ke server.</div>';
                rabDtlDiv.style.display = 'block';
            }
        });
    }

    function renderRabDetailPanel(rows, projectName, cur, pf) {
        var fmtQty = function (v) { return Ext.util.Format.number(v || 0, '0,000.0000'); };
        var fmtAmt = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };
        var fmtPct = function (v) { return Ext.util.Format.number(v || 0, '0.00') + '%'; };

        var code = (cur && cur.code) || 'IDR';
        var curLine = '<div style="padding:0 2px 10px 2px;">Mata uang anggaran: <b>' + Ext.util.Format.htmlEncode(code) + '</b>' +
                      (cur && !cur.is_base ? ' &nbsp;|&nbsp; Kurs: <b>' + fmtAmt(cur.exch_rate) + '</b> ' +
                          Ext.util.Format.htmlEncode(cur.base_code || 'IDR') + ' per 1 ' + Ext.util.Format.htmlEncode(code) : '') + '</div>';
        // [NEW] ringkasan plafon: Nilai Kontrak, Budget, Cadangan, total RAB, cadangan terpakai & sisa (mata uang dasar)
        var plafonLine = '';
        if (pf) {
            var base = (cur && cur.base_code) || 'IDR';
            var noContract = !(pf.contract_value > 0);
            var low = pf.reserve_total > 0 && pf.reserve_remaining < pf.reserve_total * 0.1;
            var remColor = noContract ? '#ff6b5e' : (pf.reserve_remaining <= 0 ? '#ff6b5e' : (low ? '#e6a817' : '#7fe0a0'));
            plafonLine = '<div style="padding:6px 10px; margin:0 2px 10px 2px; background:#1f2d3a; border-radius:4px; line-height:1.7;">' +
                (noContract
                    ? '<span style="color:#ff6b5e;">Nilai Kontrak (plafon) belum diisi di Master Proyek - RAB tidak dapat disimpan.</span>'
                    : 'Nilai Kontrak (plafon): <b>' + base + ' ' + fmtAmt(pf.contract_value) + '</b> &nbsp;|&nbsp; Budget (setara): <b>' + fmtAmt(pf.budget_base) + '</b>' +
                      ' &nbsp;|&nbsp; Cadangan: <b>' + fmtAmt(pf.reserve_total) + '</b>') +
                '<br>Total RAB (setara): <b>' + fmtAmt(pf.rab_total_base) + '</b> &nbsp;|&nbsp; Cadangan terpakai: <b>' + fmtAmt(pf.reserve_used) + '</b>' +
                ' &nbsp;|&nbsp; <span style="color:' + remColor + ';">Sisa cadangan: <b>' + fmtAmt(pf.reserve_remaining) + '</b></span>' +
                ' &nbsp;|&nbsp; Status proyek: <b>' + Ext.util.Format.htmlEncode(pf.project_status || '-') + '</b></div>';
        }
        var html = '<h3 class="detail-title">Daftar Material RAB: ' +
                   Ext.util.Format.htmlEncode(projectName || '') + '</h3>' +
                   '<hr class="detail-hr" />' + curLine + plafonLine +
                   '<div id="rabDtlScroll" style="overflow-y: auto; overflow-x: auto;">' +
                   '<table id="rabTable" class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead>' +
                   '<tr class="header-row">' +
                   '<th align="left">Material</th>' +
                   '<th align="right">Budget Qty</th>' +
                   '<th align="right">Harga Satuan (' + Ext.util.Format.htmlEncode(code) + ')</th>' +
                   '<th align="right">Total Budget (' + Ext.util.Format.htmlEncode(code) + ')</th>' +
                   '<th align="right">Qty Terpakai</th>' +
                   '<th align="right">% Terpakai</th>' +
                   '<th align="center">Status</th>' +
                   '</tr>' +
                   '</thead>' +
                   '<tbody>';

        if (!rows || rows.length === 0) {
            html += '<tr><td colspan="7" style="padding:15px; text-align:center; color:#557982;">Belum ada baris RAB material untuk proyek ini. Klik Edit untuk menambahkan.</td></tr>';
        } else {
            var grandBudget = 0;
            Ext.each(rows, function (r) {
                var totalBudget = (parseFloat(r.budget_qty) || 0) * (parseFloat(r.unit_price) || 0);
                grandBudget += totalBudget;
                html += '<tr class="data-row">' +
                        '<td align="left">' + Ext.util.Format.htmlEncode(r.material_name || '-') + ' <span style="color:#668894;">(' + Ext.util.Format.htmlEncode(r.material_code || '') + ')</span>' +
                        (r.is_addendum ? ' <span style="color:#e6a817;font-weight:bold;" title="' + Ext.util.Format.htmlEncode(r.addendum_reason || '') + '">[TAMBAHAN]</span>' : '') + '</td>' +      // [NEW]
                        '<td align="right">' + fmtQty(r.budget_qty) + ' ' + Ext.util.Format.htmlEncode(r.unit || '') + '</td>' +
                        '<td align="right">' + fmtAmt(r.unit_price) + '</td>' +
                        '<td align="right" class="subtotal-val">' + fmtAmt(totalBudget) + '</td>' +
                        '<td align="right">' + fmtQty(r.total_qty_out) + '</td>' +
                        '<td align="right">' + fmtPct(r.usage_pct) + '</td>' +
                        '<td align="center" style="font-weight:bold; color:' + statusColor(r.status) + ';">' + Ext.util.Format.htmlEncode(r.status || '-') + '</td>' +
                        '</tr>';
            });
            html += '<tr class="data-row"><td colspan="3" align="right"><b>Total Budget RAB (' + Ext.util.Format.htmlEncode(code) + ')</b></td>' +
                    '<td align="right" class="subtotal-val"><b>' + fmtAmt(grandBudget) + '</b></td>' +
                    '<td colspan="3" style="color:#668894;">' +
                    (cur && !cur.is_base ? '&asymp; ' + Ext.util.Format.htmlEncode(cur.base_code || 'IDR') + ' ' + fmtAmt(grandBudget * (parseFloat(cur.exch_rate) || 1)) : '') +
                    '</td></tr>';
        }
        html += '</tbody></table></div>';

        rabDtlDiv.innerHTML = html;
        rabDtlDiv.style.display = 'block';
        fitRabDtl();
    }

    // ------------------------------------------------------------
    // 1. Fields & Stores
    // ------------------------------------------------------------
    var projectField = [
        { name: 'id' }, { name: 'name' }, { name: 'client_name' }, { name: 'status' },
        { name: 'material_count', type: 'int' },
        { name: 'total_budget_cost', type: 'float' },
        { name: 'total_budget_base', type: 'float' },
        { name: 'total_actual_cost', type: 'float' },
        { name: 'currency_code' }, { name: 'budget_exch_rate', type: 'float' },
        { name: 'contract_value', type: 'float' }, { name: 'budget_base', type: 'float' },      // [NEW] plafon
        { name: 'rab_status' }
    ];

    var materialField = [
        { name: 'id' }, { name: 'material_code' }, { name: 'material_name' }, { name: 'unit' },
        { name: 'estimated_price', type: 'float' }
    ];

    var rabItemField = [
        { name: 'id' }, { name: 'material_id' }, { name: 'material_code' }, { name: 'material_name' },
        { name: 'unit' }, { name: 'budget_qty', type: 'float' }, { name: 'unit_price', type: 'float' },
        { name: 'tolerance_pct', type: 'float' },
        { name: 'total_qty_out', type: 'float' }, { name: 'remaining_qty', type: 'float' },
        { name: 'usage_pct', type: 'float' }, { name: 'status' },
        { name: 'orig_qty', type: 'float' }, { name: 'orig_price', type: 'float' },      // [NEW] nilai tersimpan (pembanding baris naik / baru)
        { name: 'is_addendum' }, { name: 'addendum_reason' }
    ];

    var materialDs = new Ext.data.Store({
        url: '../php-crud/rabCRD.php',
        baseParams: { task: 'list_materials' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, materialField)
    });
    materialDs.load();

    var currencyDs = new Ext.data.Store({
        url: '../php-crud/rabCRD.php',
        baseParams: { task: 'list_currencies' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'init' }, { name: 'name' }, { name: 'is_base' }])
    });
    currencyDs.load();

    var rabItemDs = new Ext.data.Store({
        reader: new Ext.data.JsonReader({ root: 'data' }, rabItemField)
    });

    mainDs = new Ext.data.Store({
        url: '../php-crud/rabCRD.php',
        idProperty: 'id',
        baseParams: { task: 'list_projects' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, projectField)
    });
    mainDs.load();

    // ------------------------------------------------------------
    // 2. Main Grid Panel (Daftar Proyek)
    // ------------------------------------------------------------
    var GridDtlHeight = (maxGridHeight(document.getElementById('divMainGrid')));
    var mainHeight = Math.round(GridDtlHeight - (GridDtlHeight * 0.5));
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: mainHeight,
        columns: [
            { header: 'Nama Proyek', dataIndex: 'name', width: 220, renderer: CellWrap },
            { header: 'Klien', dataIndex: 'client_name', width: 170, renderer: function (v) { return CellWrap(v || '-'); } },
            { header: 'Status Proyek', dataIndex: 'status', width: 110, align: 'center' },
            { header: 'Mata Uang', dataIndex: 'currency_code', width: 80, align: 'center' },
            { header: 'Jml Material', dataIndex: 'material_count', width: 100, align: 'center' },
            {
                header: 'Total Budget', dataIndex: 'total_budget_cost', width: 140, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            {
                header: 'Total Aktual (setara)', dataIndex: 'total_actual_cost', width: 140, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            {   // [NEW] plafon
                header: 'Nilai Kontrak', dataIndex: 'contract_value', width: 130, align: 'right',
                renderer: function (v) { return v > 0 ? Ext.util.Format.number(v, '0,000.00') : '<span style="color:#ff6b5e;">belum diisi</span>'; }
            },
            {   // [NEW] sisa cadangan = kontrak - max(budget, RAB), dalam mata uang dasar
                header: 'Sisa Cadangan', dataIndex: 'contract_value', width: 130, align: 'right',
                renderer: function (v, meta, rec) {
                    if (!(v > 0)) return '-';
                    var rem = v - Math.max(rec.get('budget_base') || 0, rec.get('total_budget_base') || 0);
                    return '<span style="color:' + (rem <= 0 ? '#ff6b5e' : '#7fe0a0') + ';">' + Ext.util.Format.number(Math.max(rem, 0), '0,000.00') + '</span>';
                }
            },
            {
                header: 'Status RAB', dataIndex: 'rab_status', width: 110, align: 'center',
                renderer: function (v) {
                    return '<span style="color:' + statusColor(v) + ';font-weight:bold;">' + Ext.util.Format.htmlEncode(v || '-') + '</span>';
                }
            }
        ],
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) {
                    dataRecord = rec.data;
                    mainBtnEnable('#editData');
                    loadRabDetailPanel(dataRecord.id, dataRecord.name);
                }
            }
        }
    });
    editDataDisable();
    mainBtnDisable('#addData');
    mainBtnDisable('#deleteData');

    // ------------------------------------------------------------
    // 3. Editor Grid (Modal Edit RAB)
    // ------------------------------------------------------------
    var itemGrid = new stdGridEditor({
        store: rabItemDs,
        renderTo: 'editorFormGrid',
        viewConfig: { forceFit: true, scrollOffset: 0 },
        height: 300,
        columns: [
            {
                header: 'Material', dataIndex: 'material_id', width: 200,
                editor: new myComboField({
                    store: materialDs, displayField: 'material_name', valueField: 'id',
                    mode: 'local', triggerAction: 'all', forceSelection: true,
                    listeners: {
                        select: function (combo, record) {
                            var activeRow = itemGrid.getSelectionModel().getSelected();
                            if (activeRow) {
                                activeRow.set('material_code', record.get('material_code'));
                                activeRow.set('material_name', record.get('material_name'));
                                activeRow.set('unit', record.get('unit'));
                                if (!activeRow.get('unit_price')) {
                                    activeRow.set('unit_price', record.get('estimated_price'));
                                }
                                updateRabTotalLabel();
                            }
                        }
                    }
                }),
                renderer: function (v, meta, record) {
                    var rec = materialDs.getById(v);
                    if (rec) return Ext.util.Format.htmlEncode(rec.get('material_name'));
                    if (record && record.get('material_name')) {
                        return '<span style="color:#e6a817;">' + Ext.util.Format.htmlEncode(record.get('material_name')) + '</span>';
                    }
                    return '<i>-- pilih material --</i>';
                }
            },
            { header: 'Satuan', dataIndex: 'unit', width: 60, align: 'center' },
            {
                header: 'Budget Qty', dataIndex: 'budget_qty', width: 90, align: 'right',
                editor: new myNumberField({ decimalPrecision: 4 }),
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
            },
            {
                header: 'Harga Satuan (IDR)', dataIndex: 'unit_price', width: 110, align: 'right',
                editor: new myNumberField({ decimalPrecision: 2 }),
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            {
                header: 'Toleransi (%)', dataIndex: 'tolerance_pct', width: 80, align: 'right',
                editor: new myNumberField({ decimalPrecision: 2 })
            },
            {
                header: 'Total Budget (IDR)', dataIndex: 'budget_qty', width: 120, align: 'right',
                renderer: function (v, meta, record) {
                    var total = (parseFloat(record.get('budget_qty')) || 0) * (parseFloat(record.get('unit_price')) || 0);
                    return '<b>' + Ext.util.Format.number(total, '0,000.00') + '</b>';
                }
            },
            {
                header: 'Qty Terpakai', dataIndex: 'total_qty_out', width: 90, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
            },
            {
                header: '% Terpakai', dataIndex: 'usage_pct', width: 80, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0.00') + '%'; }
            },
            {
                header: 'Status', dataIndex: 'status', width: 90, align: 'center',
                renderer: function (v) {
                    return '<span style="color:' + statusColor(v) + ';font-weight:bold;">' + Ext.util.Format.htmlEncode(v || '-') + '</span>';
                }
            },
            {   // [NEW] penanda baris tambahan yang memakai cadangan
                header: 'Tambahan', dataIndex: 'is_addendum', width: 80, align: 'center',
                renderer: function (v, meta, rec) {
                    return v ? '<span style="color:#e6a817;font-weight:bold;" title="' + Ext.util.Format.htmlEncode(rec.get('addendum_reason') || '') + '">TAMBAHAN</span>' : '';
                }
            },
            {
                id: 'rabDeleteCol', header: '', dataIndex: 'material_id', width: 32, align: 'center', fixed: true,
                renderer: function () {
                    return '<span class="rabDeleteBtn" style="color:#e05353;cursor:pointer;font-weight:bold;font-size:14px;" title="Hapus baris">&#10005;</span>';
                }
            }
        ],
        listeners: {
            afteredit: function () { updateRabTotalLabel(); },
            cellclick: function (grid, rowIndex, columnIndex) {
                if (grid.getColumnModel().getColumnId(columnIndex) === 'rabDeleteCol') {
                    grid.getStore().removeAt(rowIndex);
                    updateRabTotalLabel();
                }
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    // Inject tombol Tambah Material & Subtotal bawah
    (function injectExtraUI() {
        var gridEditorContainer = document.getElementById('editorFormGrid');
        if (gridEditorContainer && !document.getElementById('lblRabTotal')) {
            var totalsDiv = document.createElement('div');
            totalsDiv.style.cssText = 'padding:10px 14px; margin-top:8px; font-weight:bold; font-size:14px; text-align:right; background:#1f2d3a; color:#ffffff !important; border-radius:4px;';
            totalsDiv.innerHTML = 'Total Budget RAB: <span id="lblRabTotal" style="color:#ffffff !important;">0.00</span>' +
                                  '<div id="lblRabPlafon" style="font-size:12px; font-weight:normal; margin-top:4px;"></div>';      // [NEW] bar plafon
            gridEditorContainer.parentNode.insertBefore(totalsDiv, gridEditorContainer.nextSibling);

            var btn = document.createElement('button');
            btn.id = 'btnAddRabRow';
            btn.type = 'button';
            btn.innerHTML = '+ Tambah Material';
            btn.style.cssText = 'margin-bottom:6px; padding:5px 12px; background:#2e6da4; color:#fff; border:none; border-radius:3px; cursor:pointer;';
            btn.onclick = addBlankMaterialRow;
            gridEditorContainer.parentNode.insertBefore(btn, gridEditorContainer);

            var hdr = document.createElement('div');
            hdr.id = 'rabHdrForm';
            hdr.style.cssText = 'margin-bottom:8px;';
            gridEditorContainer.parentNode.insertBefore(hdr, btn);
        }
    })();

    function updateRabTotalLabel() {
        var total = 0;
        rabItemDs.each(function (r) {
            var qty = parseFloat(r.get('budget_qty')) || 0;
            var price = parseFloat(r.get('unit_price')) || 0;
            total += qty * price;
        });
        var el = document.getElementById('lblRabTotal');
        if (el) {
            var html = Ext.util.Format.number(total, '0,000.00') + ' ' + Ext.util.Format.htmlEncode(rabCur.code);
            if (!rabCur.isBase) {
                html += ' <span style="color:#9fc3d1; font-weight:normal;">&asymp; ' + Ext.util.Format.htmlEncode(rabCur.baseCode) + ' ' +
                        Ext.util.Format.number(total * (parseFloat(rabCur.rate) || 1), '0,000.00') + '</span>';
            }
            el.innerHTML = html;
        }
        updatePlafonBar(total);      // [NEW]
    }

    // [NEW] Kurs yang berlaku di editor (dipakai konversi ke mata uang dasar untuk cek plafon)
    function curRate() { return parseFloat(fRate ? fRate.getValue() : rabCur.rate) || parseFloat(rabCur.rate) || 1; }

    // [NEW] Evaluasi plafon: kembalikan {block: pesan|null, usesReserve, promptMsg}. Server memvalidasi ulang.
    function plafonCheck(total) {
        var res = { block: null, usesReserve: false, promptMsg: '' };
        if (!plafon) return res;
        var f = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };
        var rate = curRate(), totalBase = total * rate, budgetBase = (plafon.budget || 0) * rate, contract = plafon.contract_value || 0;
        if (contract <= 0 && plafon.require_contract) {
            res.block = 'Nilai Kontrak (plafon) proyek belum diisi. Isi dulu di Master Proyek.';
        } else if (contract > 0 && budgetBase > contract + 0.01) {
            res.block = 'Budget proyek (setara ' + f(budgetBase) + ') melebihi Nilai Kontrak (' + f(contract) + '). Perbaiki di Master Proyek.';
        } else if (contract > 0 && totalBase > contract + 0.01) {
            res.block = 'Total RAB setara ' + f(totalBase) + ' melebihi Nilai Kontrak / plafon (' + f(contract) + '). Kelebihan ' + f(totalBase - contract) + '.';
        } else if (plafon.phase === 'planning' && plafon.budget > 0 && totalBase > budgetBase + 0.01) {
            res.block = 'Total RAB setara ' + f(totalBase) + ' melebihi Budget proyek (' + f(budgetBase) + '). Cadangan baru bisa dipakai setelah proyek berstatus Berjalan.';
        } else if (plafon.phase === 'running' && plafon.budget > 0 && totalBase > budgetBase + 0.01) {
            res.usesReserve = true;
            res.promptMsg = 'Penambahan ini memakai cadangan: <b>' + f(totalBase - budgetBase) + '</b> dari <b>' + f(Math.max(contract - budgetBase, 0)) +
                            '</b> (sisa ' + f(Math.max(contract - totalBase, 0)) + ').<br>Tulis alasan penambahan material:';
        }
        return res;
    }

    // [NEW] Bar plafon di bawah total RAB (mata uang dasar)
    function updatePlafonBar(total) {
        var el = document.getElementById('lblRabPlafon');
        if (!el) return;
        if (!plafon) { el.innerHTML = ''; return; }
        var f = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };
        var rate = curRate(), totalBase = total * rate, budgetBase = (plafon.budget || 0) * rate, contract = plafon.contract_value || 0;
        if (contract <= 0) { el.innerHTML = '<span style="color:#ff6b5e;">Nilai Kontrak (plafon) belum diisi di Master Proyek.</span>'; return; }
        var chk = plafonCheck(total);
        var color = chk.block ? '#ff6b5e' : (chk.usesReserve ? '#e6a817' : '#7fe0a0');
        el.innerHTML = '<span style="color:' + color + ';">Plafon (Nilai Kontrak) ' + f(contract) + ' &nbsp;|&nbsp; Budget ' + f(budgetBase) +
                       ' &nbsp;|&nbsp; Cadangan ' + f(Math.max(contract - budgetBase, 0)) + ' &nbsp;|&nbsp; Total RAB ' + f(totalBase) +
                       ' &nbsp;|&nbsp; Cadangan terpakai ' + f(Math.max(totalBase - budgetBase, 0)) + ' &nbsp;|&nbsp; Sisa plafon ' + f(contract - totalBase) +
                       (chk.block ? ' &nbsp;(MELEBIHI BATAS)' : (chk.usesReserve ? ' &nbsp;(MEMAKAI CADANGAN)' : '')) + '</span>';
    }

    // ------------------------------------------------------------
    // Mata uang anggaran (header editor)
    // ------------------------------------------------------------
    function baseCurrencyCode() {
        var code = 'IDR';
        currencyDs.each(function (r) { if (r.get('is_base') === true) code = r.get('init'); });
        return code;
    }

    // Judul kolom harga & total mengikuti mata uang terpilih
    function applyCurrencyHeaders() {
        var cm = itemGrid.getColumnModel();
        cm.setColumnHeader(3, 'Harga Satuan (' + rabCur.code + ')');
        cm.setColumnHeader(5, 'Total Budget (' + rabCur.code + ')');
    }

    function ensureHdrForm() {
        if (hdrForm) { hdrForm.doLayout(); return; }

        fCur = new myComboField({
            fieldLabel: 'Mata Uang Anggaran', store: currencyDs, displayField: 'init', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, width: 110,
            listeners: { select: function () { onCurrencyChange(); } }
        });
        fRateDate = new Ext.form.DateField({
            fieldLabel: 'Tanggal Kurs', format: 'd/m/Y', value: new Date(), width: 120,
            listeners: {
                select: function () { if (!rateManual) refreshRate(); },
                change: function () { if (!rateManual) refreshRate(); }
            }
        });
        fRate = new myNumberField({
            fieldLabel: 'Kurs ke Mata Uang Dasar', decimalPrecision: 4, value: 1, width: 130,
            listeners: { change: function () { rateManual = true; rabCur.rate = fRate.getValue() || 1; updateRabTotalLabel(); } }
        });
        fRateInfo = new Ext.form.DisplayField({ hideLabel: true, value: '', anchor: '98%' });

        var col = function (w, items) {
            return { columnWidth: w, layout: 'form', border: false, labelWidth: 150, bodyStyle: 'background:transparent;', items: items };
        };
        hdrForm = new Ext.form.FormPanel({
            renderTo: 'rabHdrForm',
            border: false,
            layout: 'column',
            bodyStyle: 'background:transparent; padding:4px 2px;',
            items: [col(0.30, [fCur, fRateDate]), col(0.30, [fRate]), col(0.40, [fRateInfo])]
        });
    }

    function onCurrencyChange() {
        var rec = currencyDs.getById(fCur.getValue());
        if (!rec) return;
        rabCur.id = rec.get('id');
        rabCur.code = rec.get('init');
        rabCur.isBase = rec.get('is_base') === true;
        rabCur.baseCode = baseCurrencyCode();
        rateManual = false;
        applyCurrencyHeaders();
        refreshRate();
        itemGrid.getView().refresh();
        updateRabTotalLabel();
    }

    // Kurs default = kurs pajak KMK pada tanggal kurs; belum ada -> kurs terakhir. Tetap bisa diedit.
    function refreshRate() {
        var cid = fCur.getValue(), dt = fRateDate.getValue();
        if (!cid || !dt) return;
        var token = ++rateReq;
        Ext.Ajax.request({
            url: '../php-crud/rabCRD.php', method: 'POST',
            params: { task: 'get_exchange_rate', currency_id: cid, date: dt.format('Y-m-d') },
            success: function (response) {
                if (token !== rateReq) return;
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); } catch (e) { return; }
                if (!res.success) { fRateInfo.setValue('<span style="color:#c0392b;">' + Ext.util.Format.htmlEncode(res.message || 'Gagal memuat kurs.') + '</span>'); return; }
                var d = res.data || {}, enc = Ext.util.Format.htmlEncode;
                rabCur.isBase = (d.source === 'BASE');
                fRate.setReadOnly(rabCur.isBase);
                if (d.rate) { fRate.setValue(d.rate); rabCur.rate = d.rate; }
                if (d.source === 'BASE') {
                    fRateInfo.setValue('<span style="color:#668894;">Mata uang dasar (kurs 1)</span>');
                } else if (d.source === 'periode') {
                    fRateInfo.setValue('<span style="color:#2e9e4f;">KMK ' + enc(d.kmk_no) + ' (' + enc(d.start_date) + ' - ' + enc(d.end_date) + ')</span>');
                } else if (d.source === 'terakhir') {
                    fRateInfo.setValue('<span style="color:#e6a817;">Belum ada kurs untuk tanggal ini, memakai kurs terakhir: KMK ' +
                                       enc(d.kmk_no) + ' (' + enc(d.start_date) + ' - ' + enc(d.end_date) + ')</span>');
                } else {
                    fRateInfo.setValue('<span style="color:#e6a817;">Data kurs mata uang ini belum tersedia, isi manual.</span>');
                }
                updateRabTotalLabel();
            }
        });
    }

    // Terapkan info mata uang hasil load_rab ke form header
    function applyCurrencyFromServer(c) {
        c = c || {};
        ensureHdrForm();
        rabCur = { id: c.id, code: c.code || 'IDR', baseCode: c.base_code || 'IDR', isBase: !!c.is_base,
                   rate: parseFloat(c.exch_rate) || 1, locked: c.locked_reason || null };
        fCur.setValue(c.id);
        fRate.setValue(rabCur.rate);
        fRate.setReadOnly(rabCur.isBase);
        fRateDate.setValue((c.rate_date && Date.parseDate(c.rate_date, 'Y-m-d')) || new Date());
        rateManual = true;                                   // kurs tersimpan tidak ditimpa otomatis
        fCur.setDisabled(!!rabCur.locked);
        fRateInfo.setValue(rabCur.locked
            ? '<span style="color:#e6a817;">&#128274; Mata uang anggaran terkunci. ' + Ext.util.Format.htmlEncode(rabCur.locked) + ' Kurs tetap bisa diperbarui.</span>'
            : (rabCur.isBase ? '<span style="color:#668894;">Mata uang dasar (kurs 1)</span>'
                             : '<span style="color:#668894;">Kurs tersimpan pada RAB. Ganti mata uang untuk mengambil kurs baru.</span>'));
        applyCurrencyHeaders();
        itemGrid.getView().refresh();
        updateRabTotalLabel();
    }

    function addBlankMaterialRow() {
        var RabRecord = rabItemDs.recordType || Ext.data.Record.create(rabItemField);
        var rec = new RabRecord({
            id: 0, material_id: null, material_code: '', material_name: '', unit: '',
            budget_qty: 0, unit_price: 0, tolerance_pct: 0,
            total_qty_out: 0, remaining_qty: 0, usage_pct: 0, status: 'ON TRACK',
            orig_qty: 0, orig_price: 0, is_addendum: false, addendum_reason: ''      // [NEW]
        });
        rabItemDs.add(rec);
        updateRabTotalLabel();
    }

    // ------------------------------------------------------------
    // 4. Action Handlers (Edit, Save, Modal Close)
    // ------------------------------------------------------------
    document.getElementById('editData').onclick = function () {
        if (!dataRecord) {
            Ext.Msg.alert('Peringatan', 'Pilih proyek terlebih dahulu.');
            return;
        }
        if (getFormAccess() < 3) {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk mengedit RAB.');
            return;
        }
        Ext.Ajax.request({
            url: '../php-crud/rabCRD.php',
            method: 'POST',
            params: { task: 'load_rab', project_id: dataRecord.id },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    Ext.Msg.alert('Gagal', res.message || 'Gagal memuat RAB.');
                    return;
                }
                var loadedItems = res.data.items || [];
                Ext.each(loadedItems, function (i) { i.orig_qty = i.budget_qty; i.orig_price = i.unit_price; });      // [NEW] pembanding baris naik / baru
                plafon = res.data.plafon || null;      // [NEW]
                rabItemDs.loadData({ data: loadedItems });
                document.getElementById('editorWdw').style.display = 'block';
                applyCurrencyFromServer(res.data.currency);
                document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;RAB Material -- ' + Ext.util.Format.htmlEncode(res.data.name || '');
            },
            failure: function () {
                Ext.Msg.alert('Gagal', 'Gagal menghubungi server.');
            }
        });
    };

    // [CHANGED 2026-10-12] Simpan RAB: cek plafon di klien (server memvalidasi ulang); bila memakai cadangan, minta alasan
    //                       untuk baris baru / yang dinaikkan, lalu kirim lewat sendSave().
    function sendSave(arrItems) {
        Ext.Ajax.request({
            url: '../php-crud/rabCRD.php',
            method: 'POST',
            params: {
                task: 'save_rab',
                project_id: dataRecord.id,
                currency_id: fCur ? fCur.getValue() : 0,
                exch_rate: fRate ? (fRate.getValue() || 1) : 1,
                rate_date: (fRateDate && fRateDate.getValue()) ? fRateDate.getValue().format('Y-m-d') : '',
                items: Ext.util.JSON.encode(arrItems)
            },
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { Ext.Msg.alert('Gagal', 'Respons server tidak valid (cek error_log PHP).'); return; }
                if (res.success) {
                    Ext.Msg.alert('Sukses', res.message || 'RAB material berhasil disimpan.');      // [CHANGED] pesan server memuat info cadangan
                    mainDs.load();
                    document.getElementById('editorWdw').style.display = 'none';
                    loadRabDetailPanel(dataRecord.id, dataRecord.name);
                } else {
                    Ext.Msg.alert('Gagal', res.message || 'Terjadi kesalahan sistem.');
                }
            },
            failure: function () {
                Ext.Msg.alert('Gagal', 'Gagal menghubungi server.');
            }
        });
    }

    document.getElementById('saveEdit').onclick = function () {
        if (!dataRecord) return;

        var arrItems = [], flagged = [], total = 0;
        var hasInvalidRow = false;
        var materialSet = {};
        var duplicateFound = false;

        rabItemDs.each(function (r) {
            var materialId = r.get('material_id');
            var qty = parseFloat(r.get('budget_qty')) || 0;
            var price = parseFloat(r.get('unit_price')) || 0;

            if (!materialId && qty <= 0) return;
            if (!materialId || qty <= 0) {
                hasInvalidRow = true;
                return;
            }

            if (materialSet[materialId]) {
                duplicateFound = true;
            } else {
                materialSet[materialId] = true;
            }

            // [NEW] baris baru / qty naik / nilai naik dibanding RAB tersimpan -> kandidat 'tambahan' (butuh alasan bila pakai cadangan)
            var origQty = parseFloat(r.get('orig_qty')) || 0;
            var origVal = origQty * (parseFloat(r.get('orig_price')) || 0);
            var isUp = origQty <= 0 || qty > origQty + 0.00005 || qty * price > origVal + 0.005;
            total += Math.round(qty * price * 100) / 100;

            var item = {
                id: r.get('id') || 0,
                material_id: materialId,
                budget_qty: qty,
                unit_price: price,
                tolerance_pct: parseFloat(r.get('tolerance_pct')) || 0,
                addendum_reason: ''
            };
            if (isUp) flagged.push(item);
            arrItems.push(item);
        });

        if (hasInvalidRow) {
            Ext.Msg.alert('Peringatan', 'Ada baris material yang belum lengkap. Pastikan material dipilih dan Budget Qty > 0.');
            return;
        }

        if (duplicateFound) {
            Ext.Msg.alert('Peringatan', 'Terdapat duplikasi material. Setiap material hanya boleh diinput 1 kali per proyek.');
            return;
        }

        var chk = plafonCheck(total);      // [NEW] plafon
        if (chk.block) { Ext.Msg.alert('Melebihi Plafon', chk.block); return; }
        if (chk.usesReserve && flagged.length) {
            Ext.Msg.prompt('Alasan Penambahan (Pakai Cadangan)', chk.promptMsg, function (btn, text) {
                if (btn !== 'ok') return;
                text = Ext.util.Format.trim(text || '');
                if (!text) { Ext.Msg.alert('Peringatan', 'Alasan penambahan wajib diisi.'); return; }
                Ext.each(flagged, function (it) { it.addendum_reason = text; });
                sendSave(arrItems);
            }, this, true);
            return;
        }
        sendSave(arrItems);
    };

    document.getElementById('popOK').onclick = function () {
        document.getElementById('popWdw').style.display = 'none';
        if (document.getElementById('popCancel')) {
            document.getElementById('popCancel').style.display = 'none';
        }
    };

    document.getElementById('editorWdw').style.display = 'none';
});