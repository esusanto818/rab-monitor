/*
  Document      : warehouse.issuing.js
  Description   : Warehouse Issuing (material out ke proyek). ExtJS 3.4.1
                  Mengikuti pola po.js: grid utama (#divMainGrid) + panel detail di bawah grid,
                  tombol Add/Delete memakai tombol template, editor memakai #editorWdw bawaan template.
                  Aturan: material harus sudah RCV di W/H proyek; qty <= stok W/H dan
                  total issue <= RAB +5% (kuota dihitung server, 'max_issue').
                  Tombol template: Add = issue baru | Edit = tidak dipakai | Delete = Reverse issue.
  Backend       : ../php-crud/warehouse.issuingCRD.php
  [CHANGED]     : seluruh file ditulis ulang -- sebelumnya membuat grid/toolbar/window sendiri ke body,
                  sehingga #editorWdw template tampil kosong dan grid tidak muncul; URL AJAX juga relatif
                  ke /views/ (kini '../php-crud/').
*/
Ext.onReady(function () {
    Ext.QuickTips.init();

    // [CHANGED] header kolom grid editor boleh multi-line (judul panjang membungkus, tidak terpotong "...")
    (function () {
        if (document.getElementById('gridEditorHdrCss')) return;
        var st = document.createElement('style');
        st.id = 'gridEditorHdrCss';
        st.type = 'text/css';
        st.innerHTML = '.x-grid3-gridEditor .x-grid3-hd-inner{white-space:normal !important;text-overflow:clip !important;line-height:13px;padding-top:3px;padding-bottom:3px;}' +
                       '.x-grid3-gridEditor .x-grid3-hd-row td{vertical-align:middle;}';
        document.getElementsByTagName('head')[0].appendChild(st);
    })();
    document.getElementById("mainHeaderText").innerHTML = "Warehouse <em>Issuing</em>";

    var CRD = '../php-crud/warehouse.issuingCRD.php';   // [CHANGED] relatif dari /proyek/views/
    var ACCESS_ADD = 2;      // level akses minimal: buat issue
    var ACCESS_EDIT = 3;     // level akses minimal: reverse
    var EPS = 0.00005;       // toleransi qty

    var dataRecord = null;   // baris issue terpilih di grid utama
    var hdrForm = null, matBar = null;
    var fDate, fProject, fWh, fMandor, fRemark, fMat;

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }

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
        return (v === 'posted') ? '#00ff4c !important' : (v === 'reversed' ? '#c0392b !important' : '#ffffff !important');
    }

    // ------------------------------------------------------------
    // 0. Panel Detail (di bawah grid utama) -- pola sama dengan po.js
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');
    var GridMainHeight = maxGridHeight(gridContainer);
    var DtlHeight = Math.round(GridMainHeight - (GridMainHeight * 0.6) - 30);

    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'issDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.maxHeight = DtlHeight;
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
        var jenis = (h.issue_type === 'SUPPLIER_RETURN') ? 'Retur Supplier' : 'Proyek';
        var html = '<h3 class="detail-title">Detail Issue: ' + enc(h.ref_no) +
                   ' <span style="color:' + statusColor(h.status) + ';">(' + enc(h.status) + ')</span></h3>' +
                   '<hr class="detail-hr" />' +
                   '<div style="padding:4px 2px 10px 2px;">Tanggal: <b>' + enc(h.issue_date) + '</b> &nbsp;|&nbsp; Jenis: <b>' + jenis +
                   '</b> &nbsp;|&nbsp; Proyek: <b>' + enc(h.project_name) + '</b> &nbsp;|&nbsp; Gudang: <b>' + enc(h.warehouse_name) +
                   '</b> &nbsp;|&nbsp; Mandor: <b>' + enc(h.received_by_mandor || '-') + '</b>' +
                   (h.remark ? ' &nbsp;|&nbsp; Catatan: ' + enc(h.remark) : '') + '</div>' +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead><tr class="header-row">' +
                   '<th align="left">Material</th><th align="right">Qty</th>' +
                   '<th align="right">Biaya Satuan (FIFO)</th><th align="right">Nilai</th><th align="left">Catatan</th>' +
                   '</tr></thead><tbody>';
        if (!items.length) {
            html += '<tr><td colspan="5" style="padding:15px; text-align:center; color:#557982;">Belum ada item.</td></tr>';
        }
        Ext.each(items, function (i) {
            var amt = parseFloat(i.amount) || 0;
            grand += amt;
            var over = (i.is_overbudget === true || i.is_overbudget === 't' || i.is_overbudget === 'true');
            html += '<tr class="data-row">' +
                    '<td align="left">' + enc(i.material_name) + ' <span style="color:#668894;">(' + enc(i.material_code || '') + ')</span></td>' +
                    '<td align="right">' + fmtQty(i.qty_out) + ' ' + enc(i.unit || '') + '</td>' +
                    '<td align="right">' + fmtAmt(i.unit_cost) + '</td>' +
                    '<td align="right" class="subtotal-val">' + fmtAmt(amt) + '</td>' +
                    '<td align="left"' + (over ? ' style="color:#e6a817;"' : '') + '>' + enc(i.overbudget_reason || i.notes || '') + '</td>' +
                    '</tr>';
        });
        html += '<tr class="data-row"><td colspan="3" align="right"><b>Total</b></td>' +
                '<td align="right" class="subtotal-val"><b>' + fmtAmt(grand) + '</b></td><td></td></tr>';
        html += '</tbody></table>';
        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';
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
        url: CRD, baseParams: { action: 'lookup_projects' }, root: 'data', idProperty: 'id',
        fields: ['id', 'name'], listeners: { exception: storeError }
    });
    var warehouseDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'lookup_warehouses' }, root: 'data', idProperty: 'id',
        fields: ['id', 'code', 'name'], listeners: { exception: storeError }
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
        renderTo: 'divMainGrid',          // [CHANGED] container template, bukan body
        height: mainHeight,
        columns: [
            { header: 'No. Issue', dataIndex: 'ref_no', width: 110 },
            { header: 'Tanggal', dataIndex: 'issue_date', width: 85, align: 'center' },
            { header: 'Jenis', dataIndex: 'issue_type', width: 95, align: 'center',
              renderer: function (v) { return v === 'SUPPLIER_RETURN' ? '<span style="color:#2bc1e2;font-weight:bold;">Retur Supplier</span>' : 'Proyek'; } },
            { header: 'Proyek', dataIndex: 'project_name', width: 200, renderer: CellWrap },
            { header: 'Gudang', dataIndex: 'warehouse_name', width: 140, renderer: CellWrap },
            { header: 'Mandor Penerima', dataIndex: 'received_by_mandor', width: 130 },
            { header: 'Item', dataIndex: 'line_count', width: 50, align: 'center' },
            { header: 'Nilai (FIFO)', dataIndex: 'total_amount', width: 110, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Allowance', dataIndex: 'has_overbudget', width: 80, align: 'center',
              renderer: function (v) { return (v === true || v === 't') ? '<span style="color:#e6a817;font-weight:bold;">+5%</span>' : '-'; } },
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
        mainBtnDisable('#editData');                       // issue yang sudah posted tidak diedit (gunakan Reverse)
        // Delete = Reverse; hanya issue posted bertipe PROJECT (retur supplier lewat modul Retur)
        if (dataRecord && dataRecord.status === 'posted' && dataRecord.issue_type !== 'SUPPLIER_RETURN') {
            mainBtnEnable('#deleteData');
        } else {
            mainBtnDisable('#deleteData');
        }
    }
    editDataDisable();
    updateMainButtons();

    // ------------------------------------------------------------
    // 3. Editor: header (form) + pemilih material + grid item
    // ------------------------------------------------------------
    var itemGrid = new stdGridEditor({
        store: itemDs,
        renderTo: 'editorFormGrid',
        viewConfig: { forceFit: true, scrollOffset: 0 },
        height: 300,
        columns: [
            { header: 'Kode', dataIndex: 'material_code', width: 90 },
            { header: 'Material', dataIndex: 'material_name', width: 200 },
            { header: 'Sat', dataIndex: 'unit', width: 50, align: 'center' },
            { header: 'Stok W/H', dataIndex: 'onhand', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { header: 'RAB', dataIndex: 'rab_qty', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { header: 'Sudah Issue', dataIndex: 'issued', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { header: 'Maks Issue', dataIndex: 'max_issue', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { header: 'Qty Issue', dataIndex: 'qty_out', width: 90, align: 'right',
              editor: new myNumberField({ decimalPrecision: 4 }),
              renderer: function (v) { return '<b>' + fmtQty(v) + '</b>'; } },
            { id: 'issDeleteCol', header: '', dataIndex: 'material_id', width: 32, align: 'center', fixed: true,
              renderer: function () { return '<span style="color:#e05353;cursor:pointer;font-weight:bold;font-size:14px;" title="Hapus baris">&#10005;</span>'; } }
        ],
        listeners: {
            // tolak qty > batas (server tetap memvalidasi ulang)
            validateedit: function (e) {
                if (e.field === 'qty_out' && parseFloat(e.value) > e.record.get('max_issue') + EPS) {
                    showErr('Qty melebihi batas issue (' + fmtQty(e.record.get('max_issue')) + ').');
                    e.cancel = true;
                }
            },
            cellclick: function (grid, rowIndex, columnIndex) {
                if (grid.getColumnModel().getColumnId(columnIndex) === 'issDeleteCol') {
                    grid.getStore().removeAt(rowIndex);
                }
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    // Tempat form header + bar pemilih material (disisipkan sebelum grid editor)
    (function injectExtraUI() {
        var g = document.getElementById('editorFormGrid');
        if (g && !document.getElementById('issHdrForm')) {
            var hdr = document.createElement('div');
            hdr.id = 'issHdrForm';
            hdr.style.cssText = 'margin-bottom:6px;';
            g.parentNode.insertBefore(hdr, g);

            var bar = document.createElement('div');
            bar.id = 'issMatBar';
            bar.style.cssText = 'margin-bottom:6px;';
            g.parentNode.insertBefore(bar, g);
        }
    })();

    // Form dibuat saat editor dibuka pertama kali (container hidden -> lebar kolom 0 bila dirender lebih awal)
    function ensureHdrForm() {
        if (hdrForm) { hdrForm.doLayout(); return; }

        fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        fProject = new myComboField({
            fieldLabel: 'Proyek', store: projectDs, displayField: 'name', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: {
                select: function () {            // proyek berubah -> muat gudang proyek, kosongkan detail (kuota & stok beda)
                    fWh.clearValue();
                    itemDs.removeAll();
                    fMat.clearValue();
                    warehouseDs.load({ params: { project_id: fProject.getValue() } });
                }
            }
        });
        fWh = new myComboField({
            fieldLabel: 'Gudang', store: warehouseDs, displayField: 'name', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            emptyText: 'Pilih proyek dulu',
            listeners: { select: function () { itemDs.removeAll(); fMat.clearValue(); } }
        });
        fMandor = new myTextField({ fieldLabel: 'Mandor Penerima', anchor: '95%' });
        fRemark = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });

        var col = function (w, items) {
            return { columnWidth: w, layout: 'form', border: false, labelWidth: 110, bodyStyle: 'background:transparent;', items: items };
        };
        hdrForm = new Ext.form.FormPanel({
            renderTo: 'issHdrForm',
            border: false,
            layout: 'column',
            bodyStyle: 'background:transparent; padding:4px 2px 0 2px;',
            items: [col(0.5, [fProject, fWh, fDate]), col(0.5, [fMandor, fRemark])]
        });

        // pemilih material (stok W/H + kuota RAB)
        fMat = new Ext.form.ComboBox({
            store: materialDs, displayField: 'material_name', valueField: 'material_id', mode: 'remote',
            queryParam: 'query', minChars: 0, triggerAction: 'all', forceSelection: true, width: 380, listWidth: 520,
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
        matBar = new Ext.Toolbar({
            renderTo: 'issMatBar',
            items: [fMat, ' ', { text: 'Tambah Material', handler: addMaterial },
                    '->', '<span style="color:#9fc3d1;">Batas issue = min(stok gudang, RAB +5% - sudah di-issue). Melewati RAB (masih +5%) ditandai allowance.</span>']
        });
    }

    function addMaterial() {
        var rec = fMat.findRecord('material_id', fMat.getValue());
        if (!rec) { showErr('Pilih material terlebih dahulu.'); return; }
        if (itemDs.find('material_id', rec.get('material_id')) >= 0) { showErr('Material sudah ada di daftar.'); return; }
        if (rec.get('max_issue') <= 0) {
            showErr('Tidak ada kuota issue untuk material ini (stok kosong / sudah mencapai batas RAB +5% / tidak ada di RAB).');
            return;
        }
        var R = itemDs.recordType;
        itemDs.add(new R({
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
    }

    // ------------------------------------------------------------
    // 4. Action Handlers
    // ------------------------------------------------------------
    // ADD = issue baru
    document.getElementById('addData').onclick = function () {
        if (getFormAccess() < ACCESS_ADD) {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk membuat issue.');
            return;
        }
        showEditor('Warehouse Issuing Baru');
        fDate.setValue(new Date());
        fProject.clearValue();
        fWh.clearValue();
        fMandor.setValue('');
        fRemark.setValue('');
        fMat.clearValue();
        itemDs.removeAll();
        warehouseDs.removeAll();
        projectDs.load();
    };

    // SAVE (Simpan & Posting)
    document.getElementById('saveEdit').onclick = function () {
        if (!fProject.getValue() || !fWh.getValue() || !fDate.getValue()) { showErr('Proyek, gudang, dan tanggal wajib diisi.'); return; }
        var arr = [], err = '';
        itemDs.each(function (r) {
            var q = parseFloat(r.get('qty_out')) || 0;
            if (q <= 0) { err = 'Qty issue ' + r.get('material_name') + ' harus lebih besar dari 0.'; return false; }
            arr.push({ material_id: r.get('material_id'), qty_out: q });
        });
        if (err) { showErr(err); return; }
        if (!arr.length) { showErr('Tambahkan minimal satu material.'); return; }

        ajax({
            action: 'save',
            project_id: fProject.getValue(), warehouse_id: fWh.getValue(),
            issue_date: fDate.getValue().format('Y-m-d'),
            received_by_mandor: fMandor.getValue(), remark: fRemark.getValue(),
            details: Ext.util.JSON.encode(arr)       // detail bertingkat -> JSON via POST
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

    // DELETE = Reverse issue posted (stok dikembalikan)
    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) { showErr('Pilih dokumen terlebih dahulu.'); return; }
        if (dataRecord.status !== 'posted') { showErr('Hanya dokumen posted yang dapat di-reverse.'); return; }
        if (dataRecord.issue_type === 'SUPPLIER_RETURN') {
            showErr('Ini issue Retur Supplier. Gunakan Batal Kirim di modul Retur Pembelian.');
            return;
        }
        if (getFormAccess() < ACCESS_EDIT) {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk reverse issue.');
            return;
        }
        Ext.Msg.confirm('Reverse Issuing', 'Reverse dokumen <b>' + enc(dataRecord.ref_no) + '</b>? Stok akan dikembalikan.', function (btn) {
            if (btn !== 'yes') return;
            ajax({ action: 'reverse', id: dataRecord.id }, function (res) {
                Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                if (res.success) {
                    dataRecord = null;
                    dtlDiv.style.display = 'none';
                    mainDs.load();
                    updateMainButtons();
                }
            });
        });
    };

    document.getElementById('popOK').onclick = function () {
        document.getElementById('popWdw').style.display = 'none';
        if (document.getElementById('popCancel')) {
            document.getElementById('popCancel').style.display = 'none';
        }
    };

    // [CHANGED] window editor template harus disembunyikan saat halaman dibuka (sebelumnya tampil kosong)
    document.getElementById('editorWdw').style.display = 'none';
    var addWdwInit = document.getElementById('addWdw');
    if (addWdwInit) addWdwInit.style.display = 'none';

    projectDs.load();
});