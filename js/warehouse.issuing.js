/*
  Document      : warehouse.issuing.js
  Description   : Warehouse Issuing (material out ke proyek). ExtJS 3.4.1
                  Pola template (sama dengan po.js / purchase.return.js):
                  grid utama = daftar issue (#divMainGrid); klik baris -> panel detail di bawah grid;
                  Add membuka editor (#editorWdw: header form + grid item).
  Aturan        : material harus sudah RCV di W/H; qty <= stok W/H dan
                  total issue proyek <= RAB +5% (kuota dihitung server, 'max_issue').
                  Gudang = semua gudang yang MASIH ADA material (gudang utama dipakai bersama).
                  Issue jenis SUPPLIER_RETURN dibuat dari modul Retur Pembelian (Kirim) dan hanya bisa
                  dibatalkan lewat Batal Kirim di modul itu.
  Backend       : /proyek/php-crud/warehouse.issuingCRD.php
  PERUBAHAN     : [REBUILD] disusun ulang mengikuti pola template (versi standalone Ext.Window sebelumnya salah)
                  [FIX-DATE] tanggal dikirim lewat ymd() (Date.format('Y-m-d') Ext menghasilkan 'Y-10-10' di browser ini)
                  [CHANGED] gudang = gudang yang masih ada material (bukan lagi per-proyek)
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    if (document.getElementById("mainHeaderText")) {
        document.getElementById("mainHeaderText").innerHTML = "Warehouse <em>Issuing</em>";
    }

    var CRD = '../php-crud/warehouse.issuingCRD.php';     // relatif dari /proyek/views/
    var ACCESS_ADD = 2;      // level akses minimal: buat issue
    var ACCESS_EDIT = 3;     // level akses minimal: reverse issue
    var EPS = 0.00005;       // toleransi qty

    var dataRecord = null;   // baris issue terpilih di grid utama
    var hdrForm = null, matBar = null;
    var fProject, fWh, fDate, fMandor, fRemark, fMat;

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    // [FIX-DATE] format('Y-m-d') Ext menghasilkan 'Y-10-10' di browser ini -> susun YYYY-MM-DD manual
    function ymd(d) {
        var m = d.getMonth() + 1, dd = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (dd < 10 ? '0' + dd : dd);
    }
    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }
    function needAccess(level, msg) {
        if (getFormAccess() < level) { Ext.Msg.alert('Akses Ditolak', msg); return false; }
        return true;
    }

    // Exception store: tampilkan pesan backend / HTTP
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; }
        else {
            try { var o = Ext.decode(res.responseText); if (o && o.message) { m = o.message; } }
            catch (e) { if (res && res.status) { m += ' (HTTP ' + res.status + ')'; } }
        }
        showErr(m);
    }

    function ajax(params, onOk) {
        Ext.Ajax.request({
            url: CRD, method: 'POST', params: params,
            success: function (r) {
                var o;
                try { o = Ext.decode(r.responseText); } catch (e) { showErr('Respon server tidak valid (cek error_log PHP).'); return; }
                onOk(o);
            },
            failure: function (r) { showErr('Koneksi ke server gagal (HTTP ' + r.status + ').'); }
        });
    }

    function statusColor(v) {
        return (v === 'posted') ? '#2e9e4f !important' : '#c0392b !important';
    }

    // ------------------------------------------------------------
    // 0. Panel Detail (di bawah grid utama) -- pola sama dengan po.js
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');
    var GridMainHeight = maxGridHeight(gridContainer);
    var DtlHeight = Math.round(GridMainHeight - (GridMainHeight * 0.6) - 30);

    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'whDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.maxHeight = Math.max(DtlHeight, 220) + 'px';      // wajib pakai 'px'
    dtlDiv.style.overflowY = 'auto';
    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(dtlDiv, gridContainer.nextSibling);
    }

    function loadDetailPanel(rec) {
        ajax({ action: 'detail', id: rec.id }, function (res) {
            if (!res.success) {
                dtlDiv.innerHTML = '<div style="color:yellow; padding:10px;">' + enc(res.message || 'Gagal memuat detail.') + '</div>';
                dtlDiv.style.display = 'block';
                return;
            }
            renderDetailPanel(rec, res.data || []);
        });
    }

    function renderDetailPanel(h, items) {
        var grand = 0;
        var isReturn = (h.issue_type === 'SUPPLIER_RETURN');
        // tombol Reverse hanya untuk issue proyek berstatus posted (issue retur dibatalkan lewat Batal Kirim)
        var actHtml = '';
        if (h.status === 'posted' && !isReturn) {
            actHtml = '<div style="float:right; margin:2px 4px 0 0;">' +
                      '<button type="button" id="whReverseBtn" style="padding:8px 16px; background:#8e2b22; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold;">Reverse Issue</button></div>';
        }
        var html = actHtml + '<h3 class="detail-title">Detail Issue: ' + enc(h.ref_no) +
                   ' <span style="color:' + statusColor(h.status) + ';">(' + enc(h.status) + ')</span></h3>' +
                   '<hr class="detail-hr" />' +
                   '<div style="padding:4px 2px 10px 2px;">Tanggal: <b>' + enc(h.issue_date) + '</b> &nbsp;|&nbsp; Jenis: <b>' +
                   (isReturn ? 'Retur Supplier' : 'Proyek') + '</b> &nbsp;|&nbsp; Proyek: <b>' + enc(h.project_name) +
                   '</b> &nbsp;|&nbsp; Gudang: <b>' + enc(h.warehouse_name) + '</b> &nbsp;|&nbsp; Mandor / Penerima: <b>' + enc(h.received_by_mandor || '-') + '</b>' +
                   (h.remark ? ' &nbsp;|&nbsp; Catatan: ' + enc(h.remark) : '') + '</div>' +
                   (isReturn ? '<div style="padding:0 2px 8px 2px; color:#e6a817;">Issue ini dibuat dari Retur Pembelian. Pembatalan lewat Batal Kirim di modul Retur Pembelian.</div>' : '') +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead><tr class="header-row">' +
                   '<th align="left">Material</th><th align="right">Qty</th><th align="right">Biaya Satuan (FIFO)</th>' +
                   '<th align="right">Nilai</th><th align="left">Catatan</th></tr></thead><tbody>';
        if (!items.length) {
            html += '<tr><td colspan="5" style="padding:15px; text-align:center; color:#557982;">Belum ada item.</td></tr>';
        }
        Ext.each(items, function (i) {
            var amt = parseFloat(i.amount) || 0;
            grand += amt;
            html += '<tr class="data-row">' +
                    '<td align="left">' + enc(i.material_name) + ' <span style="color:#668894;">(' + enc(i.material_code || '') + ')</span></td>' +
                    '<td align="right">' + fmtQty(i.qty_out) + ' ' + enc(i.unit || '') + '</td>' +
                    '<td align="right">' + fmtAmt(i.unit_cost) + '</td>' +
                    '<td align="right" class="subtotal-val">' + fmtAmt(amt) + '</td>' +
                    '<td align="left">' + enc(i.overbudget_reason || i.notes || '') + '</td></tr>';
        });
        html += '<tr class="data-row"><td colspan="3" align="right"><b>Total</b></td>' +
                '<td align="right" class="subtotal-val"><b>' + fmtAmt(grand) + '</b></td><td></td></tr>';
        html += '</tbody></table>';

        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';

        var rb = document.getElementById('whReverseBtn');
        if (rb) { rb.onclick = function () { reverseDoc(h); }; }
    }

    // Reverse issue proyek (stok kembali; kuota RAB ikut pulih)
    function reverseDoc(rec) {
        if (!rec) { showErr('Pilih dokumen terlebih dahulu.'); return; }
        if (rec.status !== 'posted') { showErr('Hanya dokumen posted yang dapat di-reverse.'); return; }
        if (rec.issue_type === 'SUPPLIER_RETURN') { showErr('Ini issue Retur Supplier. Gunakan Batal Kirim di modul Retur Pembelian.'); return; }
        if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk me-reverse issue.')) return;
        Ext.Msg.confirm('Reverse Issuing', 'Reverse dokumen <b>' + enc(rec.ref_no) + '</b>? Stok akan dikembalikan.', function (b) {
            if (b !== 'yes') return;
            ajax({ action: 'reverse', id: rec.id }, function (res) {
                Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                if (res.success) {
                    dataRecord = null;
                    dtlDiv.style.display = 'none';
                    mainDs.load();
                    updateMainButtons();
                }
            });
        });
    }

    // ------------------------------------------------------------
    // 1. Stores
    // ------------------------------------------------------------
    // PENTING: assignment polos (bukan var/let) -- konvensi search box global.
    mainDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'list', start: 0, limit: 250 },
        root: 'data', totalProperty: 'totalCount', idProperty: 'id',
        fields: ['id', 'ref_no', 'project_id', 'project_name', 'warehouse_id', 'warehouse_name', 'issue_date',
                 'received_by_mandor', 'status', 'remark', 'issue_type',
                 { name: 'total_amount', type: 'float' }, { name: 'line_count', type: 'int' }, 'has_overbudget'],
        listeners: { exception: storeError }
    });
    mainDs.load();

    var projectDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'lookup_projects' }, root: 'data', fields: ['id', 'name'],
        listeners: { exception: storeError }
    });
    var warehouseDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'lookup_warehouses' }, root: 'data', fields: ['id', 'code', 'name'],
        listeners: { exception: storeError }
    });
    var materialDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'lookup_materials' }, root: 'data',
        fields: ['material_id', 'material_code', 'material_name', 'unit',
                 { name: 'onhand', type: 'float' }, { name: 'rab_qty', type: 'float' },
                 { name: 'issued', type: 'float' }, { name: 'limit_qty', type: 'float' }, { name: 'max_issue', type: 'float' }],
        listeners: { exception: storeError }
    });
    var itemField = ['material_id', 'material_code', 'material_name', 'unit',
                     { name: 'qty_out', type: 'float' }, { name: 'onhand', type: 'float' }, { name: 'max_issue', type: 'float' },
                     { name: 'rab_qty', type: 'float' }, { name: 'issued', type: 'float' }];
    var itemDs = new Ext.data.Store({ reader: new Ext.data.JsonReader({ root: 'data' }, itemField) });

    // ------------------------------------------------------------
    // 2. Main Grid (Daftar Issue)
    // ------------------------------------------------------------
    var mainHeight = Math.round(GridMainHeight * 0.6);
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: mainHeight,
        columns: [
            { header: 'No. Issue', dataIndex: 'ref_no', width: 110 },
            { header: 'Tanggal', dataIndex: 'issue_date', width: 85, align: 'center' },
            { header: 'Jenis', dataIndex: 'issue_type', width: 95, align: 'center',
              renderer: function (v) { return v === 'SUPPLIER_RETURN' ? '<span style="color:#2bc1e2;font-weight:bold;">Retur Supplier</span>' : 'Proyek'; } },
            { header: 'Proyek', dataIndex: 'project_name', width: 200, renderer: CellWrap },
            { header: 'Gudang', dataIndex: 'warehouse_name', width: 140, renderer: CellWrap },
            { header: 'Mandor Penerima', dataIndex: 'received_by_mandor', width: 130, renderer: function (v) { return enc(v || '-'); } },
            { header: 'Item', dataIndex: 'line_count', width: 50, align: 'center' },
            { header: 'Nilai (FIFO)', dataIndex: 'total_amount', width: 110, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Allowance', dataIndex: 'has_overbudget', width: 80, align: 'center',
              renderer: function (v) { return (v === true || v === 'true') ? '<span style="color:#e6a817;font-weight:bold;">+5%</span>' : '-'; } },
            { header: 'Status', dataIndex: 'status', width: 80, align: 'center',
              renderer: function (v) { return '<span style="color:' + statusColor(v) + ';font-weight:bold;">' + enc(v || '-') + '</span>'; } }
        ],
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (!rec) return;
                dataRecord = rec.data;
                updateMainButtons();
                loadDetailPanel(dataRecord);
            }
        }
    });

    function updateMainButtons() {
        mainBtnEnable('#addData');
        mainBtnDisable('#editData');      // dokumen issue posted tidak diedit; koreksi lewat Reverse lalu buat ulang
        if (dataRecord && dataRecord.status === 'posted' && dataRecord.issue_type !== 'SUPPLIER_RETURN') {
            mainBtnEnable('#deleteData');     // Delete = Reverse issue proyek
        } else {
            mainBtnDisable('#deleteData');
        }
    }
    editDataDisable();
    updateMainButtons();

    // ------------------------------------------------------------
    // 3. Editor: header (proyek, gudang, tanggal, mandor, catatan) + pemilih material + grid item
    // ------------------------------------------------------------
    var itemGrid = new stdGridEditor({
        store: itemDs,
        renderTo: 'editorFormGrid',
        viewConfig: { forceFit: true, scrollOffset: 0 },
        height: 300,
        enableHdMenu: false,
        enableColumnMove: false,
        columns: [
            { sortable: false, menuDisabled: true, header: 'Kode', dataIndex: 'material_code', width: 90 },
            { sortable: false, menuDisabled: true, header: 'Material', dataIndex: 'material_name', width: 200 },
            { sortable: false, menuDisabled: true, header: 'Sat', dataIndex: 'unit', width: 50, align: 'center' },
            { sortable: false, menuDisabled: true, header: 'Stok W/H', dataIndex: 'onhand', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'RAB', dataIndex: 'rab_qty', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Sudah Issue', dataIndex: 'issued', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Maks Issue', dataIndex: 'max_issue', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Qty Issue', dataIndex: 'qty_out', width: 90, align: 'right',
              editor: new myNumberField({ decimalPrecision: 4 }),
              renderer: function (v) { return '<b>' + fmtQty(v) + '</b>'; } },
            { id: 'whDeleteCol', sortable: false, menuDisabled: true, header: '', dataIndex: 'material_id', width: 30, align: 'center', fixed: true,
              renderer: function () { return '<span style="color:#e05353;cursor:pointer;font-weight:bold;font-size:14px;" title="Hapus baris">&#10005;</span>'; } }
        ],
        listeners: {
            validateedit: function (e) {      // tolak qty > batas (server tetap memvalidasi ulang)
                if (e.field === 'qty_out' && parseFloat(e.value) > parseFloat(e.record.get('max_issue')) + EPS) {
                    showErr('Qty melebihi batas issue (' + fmtQty(e.record.get('max_issue')) + ').');
                    e.cancel = true;
                }
            },
            cellclick: function (grid, rowIndex, columnIndex) {
                if (grid.getColumnModel().getColumnId(columnIndex) === 'whDeleteCol') {
                    grid.getStore().removeAt(rowIndex);
                }
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    // Tempat form header + bar pemilih material (disisipkan sebelum grid editor)
    (function injectExtraUI() {
        var g = document.getElementById('editorFormGrid');
        if (g && !document.getElementById('whHdrForm')) {
            var hdr = document.createElement('div');
            hdr.id = 'whHdrForm';
            hdr.style.cssText = 'margin-bottom:2px;';
            g.parentNode.insertBefore(hdr, g);

            var bar = document.createElement('div');
            bar.id = 'whMatBar';
            bar.style.cssText = 'margin-bottom:6px;';
            g.parentNode.insertBefore(bar, g);
        }
    })();

    // Form dibuat saat editor dibuka pertama kali (container hidden -> lebar kolom 0 bila dirender lebih awal)
    function ensureHdrForm() {
        if (hdrForm) { hdrForm.doLayout(); matBar.doLayout(); return; }

        fProject = new myComboField({
            fieldLabel: 'Proyek', store: projectDs, displayField: 'name', valueField: 'id', mode: 'remote',
            queryParam: 'query', minChars: 0, triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () { onProjectChange(); } }
        });
        fWh = new myComboField({
            fieldLabel: 'Gudang', store: warehouseDs, displayField: 'name', valueField: 'id', mode: 'local',
            triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%', emptyText: 'Pilih proyek dulu',
            listeners: { select: function () { itemDs.removeAll(); fMat.clearValue(); } }
        });
        fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        fMandor = new myTextField({ fieldLabel: 'Mandor Penerima', anchor: '95%' });
        fRemark = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });

        var col = function (w, items) {
            return { columnWidth: w, layout: 'form', border: false, labelWidth: 110, bodyStyle: 'background:transparent;', items: items };
        };
        hdrForm = new Ext.form.FormPanel({
            renderTo: 'whHdrForm',
            border: false,
            layout: 'column',
            bodyStyle: 'background:transparent; padding:4px 2px 0 2px;',
            items: [col(0.5, [fProject, fWh, fDate]), col(0.5, [fMandor, fRemark])]
        });

        // pemilih material (stok W/H + kuota RAB)
        fMat = new myComboField({
            store: materialDs, displayField: 'material_name', valueField: 'material_id', mode: 'remote',
            queryParam: 'query', minChars: 0, triggerAction: 'all', forceSelection: true, anchor: '98%', listWidth: 520,
            emptyText: 'Cari material yang sudah RCV di gudang...',
            tpl: '<tpl for="."><div class="x-combo-list-item">{material_code} - {material_name} ' +
                 '<b>(stok {onhand}, RAB {rab_qty}, maks issue {max_issue} {unit})</b></div></tpl>',
            listeners: {
                beforequery: function () {
                    if (!fProject.getValue() || !fWh.getValue()) { showErr('Pilih proyek dan gudang terlebih dahulu.'); return false; }
                    materialDs.baseParams.project_id = fProject.getValue();
                    materialDs.baseParams.warehouse_id = fWh.getValue();
                }
            }
        });
        matBar = new Ext.Panel({
            renderTo: 'whMatBar',
            border: false,
            layout: 'column',
            bodyStyle: 'background:transparent; padding:2px;',
            items: [
                { columnWidth: 0.4, layout: 'form', border: false, hideLabels: true, bodyStyle: 'background:transparent;', items: [fMat] },
                { width: 150, border: false, bodyStyle: 'background:transparent; padding-left:6px;',
                  items: [new Ext.Button({ text: '+ Tambah Material', handler: addMaterial })] },
                { columnWidth: 0.6, border: false, bodyStyle: 'background:transparent; padding:6px 0 0 8px; color:#9fc3d1; font-size:11px;',
                  html: 'Batas issue = min(stok gudang, RAB +5% - sudah di-issue). Qty melewati RAB (tapi masih +5%) ditandai allowance.' }
            ]
        });
    }

    // proyek berubah -> muat gudang yang masih ada material, kosongkan detail (kuota & stok beda)
    function onProjectChange() {
        fWh.clearValue();
        itemDs.removeAll();
        fMat.clearValue();
        warehouseDs.load({ params: { project_id: fProject.getValue() } });
    }

    function addMaterial() {
        var rec = fMat.findRecord('material_id', fMat.getValue());
        if (!rec) { showErr('Pilih material terlebih dahulu.'); return; }
        if (itemDs.findBy(function (r) { return String(r.get('material_id')) === String(rec.get('material_id')); }) >= 0) {
            showErr('Material sudah ada di daftar.');
            return;
        }
        if (rec.get('max_issue') <= 0) {
            showErr('Tidak ada kuota issue untuk material ini (stok kosong / sudah mencapai batas RAB +5% / tidak ada di RAB).');
            return;
        }
        itemDs.add(new itemDs.recordType({
            material_id: rec.get('material_id'), material_code: rec.get('material_code'),
            material_name: rec.get('material_name'), unit: rec.get('unit'),
            qty_out: rec.get('max_issue'), onhand: rec.get('onhand'), max_issue: rec.get('max_issue'),
            rab_qty: rec.get('rab_qty'), issued: rec.get('issued')
        }));
        fMat.clearValue();
    }

    function showEditor(title) {
        var addWdw = document.getElementById('addWdw');      // jaga-jaga: window Add bawaan template jangan ikut terbuka
        if (addWdw) addWdw.style.display = 'none';
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;' + enc(title);
        ensureHdrForm();
        itemGrid.doLayout();
    }

    // ------------------------------------------------------------
    // 4. Action Handlers
    // ------------------------------------------------------------
    // ADD = issue baru
    document.getElementById('addData').onclick = function () {
        if (!needAccess(ACCESS_ADD, 'Anda tidak memiliki hak akses untuk membuat issue.')) return;
        showEditor('Warehouse Issuing Baru');
        fProject.clearValue();
        fWh.clearValue();
        warehouseDs.removeAll();
        fMat.clearValue();
        fDate.setValue(new Date()); fMandor.setValue(''); fRemark.setValue('');
        itemDs.removeAll();
        projectDs.load();
    };

    // SAVE = simpan & posting
    document.getElementById('saveEdit').onclick = function () {
        if (!fProject || !fProject.getValue() || !fWh.getValue() || !fDate.getValue()) {
            showErr('Proyek, gudang, dan tanggal wajib diisi.');
            return;
        }
        var arr = [], err = '';
        itemDs.each(function (r) {
            var q = parseFloat(r.get('qty_out')) || 0;
            if (q <= 0) { err = 'Qty issue ' + r.get('material_name') + ' harus lebih besar dari 0.'; return false; }
            if (q > parseFloat(r.get('max_issue')) + EPS) { err = 'Qty issue ' + r.get('material_name') + ' melebihi batas issue (' + fmtQty(r.get('max_issue')) + ').'; return false; }
            arr.push({ material_id: r.get('material_id'), qty_out: q });
        });
        if (err) { showErr(err); return; }
        if (!arr.length) { showErr('Tambahkan minimal satu material.'); return; }

        ajax({
            action: 'save', project_id: fProject.getValue(), warehouse_id: fWh.getValue(),
            issue_date: ymd(fDate.getValue()),                          // [FIX-DATE]
            received_by_mandor: fMandor.getValue(), remark: fRemark.getValue(),
            details: Ext.util.JSON.encode(arr)                          // detail bertingkat -> JSON via POST
        }, function (res) {
            if (res.success) {
                Ext.Msg.alert('Sukses', res.message || 'Issuing berhasil diposting.');
                document.getElementById('editorWdw').style.display = 'none';
                dataRecord = null;
                dtlDiv.style.display = 'none';
                mainDs.load();
                updateMainButtons();
            } else {
                showErr(res.message || 'Terjadi kesalahan sistem.');
            }
        });
    };

    // DELETE = Reverse issue proyek (posted)
    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord || dataRecord.status !== 'posted' || dataRecord.issue_type === 'SUPPLIER_RETURN') {
            showErr('Pilih issue proyek berstatus posted terlebih dahulu. Issue Retur Supplier dibatalkan lewat Batal Kirim di modul Retur Pembelian.');
            return;
        }
        reverseDoc(dataRecord);
    };

    document.getElementById('popOK').onclick = function () {
        document.getElementById('popWdw').style.display = 'none';
        if (document.getElementById('popCancel')) {
            document.getElementById('popCancel').style.display = 'none';
        }
    };

    // window editor template harus disembunyikan saat halaman dibuka
    document.getElementById('editorWdw').style.display = 'none';
    var addWdwInit = document.getElementById('addWdw');
    if (addWdwInit) addWdwInit.style.display = 'none';

});
