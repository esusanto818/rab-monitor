/*
  Document    : warehouse.issuing.js
  Description : Warehouse Issuing (material out ke proyek). ExtJS 3.4.1
  Aturan      : material harus sudah RCV di W/H; qty <= stok W/H dan
                total issue <= RAB +5% (kuota dihitung server, 'max_issue').
  Backend     : php-crud/warehouse.issuingCRD.php
  CATATAN     : container render diasumsikan div #main-content (fallback ke body).
                Sesuaikan RENDER_TO bila template halaman Anda berbeda.
  PERUBAHAN   : [FIX-DATE] tanggal dikirim lewat ymd() (Date.format('Y-m-d') Ext menghasilkan 'Y-10-10' di browser ini)
                [CHANGED] gudang = gudang yang masih ada material (bukan lagi per-proyek)
*/
Ext.onReady(function () {
    Ext.QuickTips.init();

    var URL = 'php-crud/warehouse.issuingCRD.php';
    var RENDER_TO = Ext.get('main-content') ? 'main-content' : Ext.getBody();
    var dataRecord = null;                      // baris grid utama terpilih

    // ---------------- util ----------------
    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }

    // [FIX-DATE] susun YYYY-MM-DD manual, tidak bergantung Date.format Ext
    function ymd(d) {
        var m = d.getMonth() + 1, dd = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (dd < 10 ? '0' + dd : dd);
    }

    // Exception store: tampilkan pesan backend / HTTP
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; }
        else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
        showErr(m);
    }

    function ajaxPost(params, okCb) {
        Ext.Ajax.request({
            url: URL, method: 'POST', params: params,
            success: function (r) {
                var o;
                try { o = Ext.decode(r.responseText); } catch (e) { showErr('Respon server tidak valid.'); return; }
                if (!o.success) { showErr(o.message || 'Proses gagal.'); return; }
                okCb(o);
            },
            failure: function (r) { showErr('Koneksi ke server gagal (HTTP ' + r.status + ').'); }
        });
    }

    function statusRenderer(v) {
        var c = (v === 'posted') ? '#2e9e4f' : '#c0392b';
        return '<span style="color:' + c + ';font-weight:bold;">' + enc(v) + '</span>';
    }

    // ---------------- store grid utama ----------------
    // assignment polos (bukan var) -- konvensi search box global
    mainDs = new Ext.data.JsonStore({
        url: URL, baseParams: { action: 'list' }, root: 'data', totalProperty: 'totalCount', idProperty: 'id',
        fields: ['id', 'ref_no', 'project_id', 'project_name', 'warehouse_id', 'warehouse_name', 'issue_date',
                 'received_by_mandor', 'status', 'remark', 'issue_type',
                 { name: 'total_amount', type: 'float' }, { name: 'line_count', type: 'int' }, 'has_overbudget'],
        listeners: { exception: storeError }
    });

    var searchField = new Ext.form.TextField({
        emptyText: 'Cari No / Proyek / Mandor...', width: 220, enableKeyEvents: true,
        listeners: { specialkey: function (f, e) { if (e.getKey() === e.ENTER) { doSearch(); } } }
    });
    function doSearch() {
        mainDs.baseParams.query = searchField.getValue();
        mainDs.load({ params: { start: 0, limit: 25 } });
    }

    var gridMain = new Ext.grid.GridPanel({
        store: mainDs, renderTo: RENDER_TO, title: 'Warehouse Issuing',
        height: Math.max(420, Ext.getBody().getViewSize().height - 140),
        loadMask: true, stripeRows: true,
        sm: new Ext.grid.RowSelectionModel({ singleSelect: true }),
        viewConfig: { forceFit: true },
        columns: [
            { header: 'No. Issue', dataIndex: 'ref_no', width: 110 },
            { header: 'Tanggal', dataIndex: 'issue_date', width: 85, align: 'center' },
            // jenis issue: ke proyek vs retur supplier
            { header: 'Jenis', dataIndex: 'issue_type', width: 95, align: 'center',
              renderer: function (v) { return v === 'SUPPLIER_RETURN' ? '<span style="color:#2bc1e2;font-weight:bold;">Retur Supplier</span>' : 'Proyek'; } },
            { header: 'Proyek', dataIndex: 'project_name', width: 200 },
            { header: 'Gudang', dataIndex: 'warehouse_name', width: 140 },
            { header: 'Mandor Penerima', dataIndex: 'received_by_mandor', width: 130 },
            { header: 'Item', dataIndex: 'line_count', width: 50, align: 'center' },
            { header: 'Nilai (FIFO)', dataIndex: 'total_amount', width: 110, align: 'right', renderer: fmtAmt },
            {
                header: 'Allowance', dataIndex: 'has_overbudget', width: 80, align: 'center',
                renderer: function (v) { return v ? '<span style="color:#e6a817;font-weight:bold;">+5%</span>' : '-'; }
            },
            { header: 'Status', dataIndex: 'status', width: 80, align: 'center', renderer: statusRenderer }
        ],
        tbar: [
            { text: 'Tambah Issue', iconCls: 'icon-add', handler: function () { openEditor(); } },
            { text: 'Detail', handler: function () {
                if (!dataRecord) { showErr('Pilih dokumen terlebih dahulu.'); return; }
                openDetail(dataRecord);
            } },
            { text: 'Reverse', handler: function () {
                if (!dataRecord) { showErr('Pilih dokumen terlebih dahulu.'); return; }
                if (dataRecord.status !== 'posted') { showErr('Hanya dokumen posted yang dapat di-reverse.'); return; }
                // issue retur supplier dibatalkan lewat modul Retur Supplier (Batal Kirim)
                if (dataRecord.issue_type === 'SUPPLIER_RETURN') { showErr('Ini issue Retur Supplier. Gunakan Batal Kirim di modul Retur Supplier.'); return; }
                Ext.Msg.confirm('Reverse Issuing', 'Reverse dokumen <b>' + enc(dataRecord.ref_no) + '</b>? Stok akan dikembalikan.',
                    function (b) {
                        if (b !== 'yes') { return; }
                        ajaxPost({ action: 'reverse', id: dataRecord.id }, function (o) {
                            Ext.Msg.alert('Sukses', o.message);
                            dataRecord = null;
                            mainDs.reload();
                        });
                    });
            } },
            '->', searchField,
            { text: 'Cari', handler: doSearch }
        ],
        bbar: new Ext.PagingToolbar({ store: mainDs, pageSize: 25, displayInfo: true }),
        listeners: {
            rowclick: function (g, i) { var r = g.getStore().getAt(i); dataRecord = r ? r.data : null; }
        }
    });
    mainDs.load({ params: { start: 0, limit: 25 } });

    // ---------------- window detail (read-only) ----------------
    function openDetail(rec) {
        var ds = new Ext.data.JsonStore({
            url: URL, baseParams: { action: 'detail', id: rec.id }, root: 'data',
            fields: ['material_code', 'material_name', 'unit',
                     { name: 'qty_out', type: 'float' }, { name: 'unit_cost', type: 'float' }, { name: 'amount', type: 'float' },
                     'is_overbudget', 'overbudget_reason', 'notes'],
            listeners: { exception: storeError }
        });
        new Ext.Window({
            title: 'Detail Issuing ' + rec.ref_no, width: 820, height: 380, modal: true, layout: 'fit',
            items: new Ext.grid.GridPanel({
                store: ds, stripeRows: true, viewConfig: { forceFit: true },
                columns: [
                    { header: 'Kode', dataIndex: 'material_code', width: 90 },
                    { header: 'Material', dataIndex: 'material_name', width: 200 },
                    { header: 'Sat', dataIndex: 'unit', width: 50, align: 'center' },
                    { header: 'Qty', dataIndex: 'qty_out', width: 90, align: 'right', renderer: fmtQty },
                    { header: 'Biaya Satuan', dataIndex: 'unit_cost', width: 100, align: 'right', renderer: fmtAmt },
                    { header: 'Nilai', dataIndex: 'amount', width: 110, align: 'right', renderer: fmtAmt },
                    { header: 'Catatan', dataIndex: 'overbudget_reason', width: 160,
                      renderer: function (v, m, r) { return enc(v || r.get('notes') || ''); } }
                ]
            })
        }).show();
        ds.load();
    }

    // ---------------- editor (dokumen baru) ----------------
    var projectDs = new Ext.data.JsonStore({
        url: URL, baseParams: { action: 'lookup_projects' }, root: 'data', fields: ['id', 'name'],
        listeners: { exception: storeError }
    });
    var warehouseDs = new Ext.data.JsonStore({
        url: URL, baseParams: { action: 'lookup_warehouses' }, root: 'data', fields: ['id', 'code', 'name'],
        listeners: { exception: storeError }
    });
    var materialDs = new Ext.data.JsonStore({
        url: URL, baseParams: { action: 'lookup_materials' }, root: 'data',
        fields: ['material_id', 'material_code', 'material_name', 'unit',
                 { name: 'onhand', type: 'float' }, { name: 'rab_qty', type: 'float' },
                 { name: 'issued', type: 'float' }, { name: 'limit_qty', type: 'float' }, { name: 'max_issue', type: 'float' }],
        listeners: { exception: storeError }
    });
    // store detail editor (assignment polos, konvensi template)
    gridAddEditorDs = new Ext.data.JsonStore({
        root: 'data', fields: ['material_id', 'material_code', 'material_name', 'unit',
            { name: 'qty_out', type: 'float' }, { name: 'onhand', type: 'float' }, { name: 'max_issue', type: 'float' },
            { name: 'rab_qty', type: 'float' }, { name: 'issued', type: 'float' }]
    });

    function openEditor() {
        gridAddEditorDs.removeAll();

        var fProject = new Ext.form.ComboBox({
            fieldLabel: 'Proyek', store: projectDs, displayField: 'name', valueField: 'id', mode: 'remote',
            queryParam: 'query', minChars: 0, triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%'
        });
        var fWh = new Ext.form.ComboBox({
            fieldLabel: 'Gudang', store: warehouseDs, displayField: 'name', valueField: 'id', mode: 'local',
            triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%', emptyText: 'Pilih proyek dulu'
        });
        var fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        var fMandor = new Ext.form.TextField({ fieldLabel: 'Mandor Penerima', anchor: '95%' });
        var fRemark = new Ext.form.TextField({ fieldLabel: 'Catatan', anchor: '95%' });

        // proyek berubah -> muat gudang yang masih ada material, kosongkan detail (kuota & stok beda)
        fProject.on('select', function () {
            fWh.clearValue();
            gridAddEditorDs.removeAll();
            warehouseDs.load({ params: { project_id: fProject.getValue() } });
        });
        fWh.on('select', function () { gridAddEditorDs.removeAll(); fMat.clearValue(); });

        // pemilih material (stok W/H + kuota RAB)
        var fMat = new Ext.form.ComboBox({
            store: materialDs, displayField: 'material_name', valueField: 'material_id', mode: 'remote',
            queryParam: 'query', minChars: 0, triggerAction: 'all', forceSelection: true, width: 380, listWidth: 520,
            emptyText: 'Cari material yang sudah RCV di gudang...',
            tpl: '<tpl for="."><div class="x-combo-list-item">{material_code} - {material_name} ' +
                 '<b>(stok {onhand}, RAB {rab_qty}, maks issue {max_issue} {unit})</b></div></tpl>',
            listeners: {
                beforequery: function (qe) {
                    if (!fProject.getValue() || !fWh.getValue()) { showErr('Pilih proyek dan gudang terlebih dahulu.'); return false; }
                    materialDs.baseParams.project_id = fProject.getValue();
                    materialDs.baseParams.warehouse_id = fWh.getValue();
                }
            }
        });

        function addMaterial() {
            var rec = fMat.findRecord('material_id', fMat.getValue());
            if (!rec) { showErr('Pilih material terlebih dahulu.'); return; }
            if (gridAddEditorDs.find('material_id', rec.get('material_id')) >= 0) { showErr('Material sudah ada di daftar.'); return; }
            if (rec.get('max_issue') <= 0) {
                showErr('Tidak ada kuota issue untuk material ini (stok kosong / sudah mencapai batas RAB +5% / tidak ada di RAB).');
                return;
            }
            var R = gridAddEditorDs.recordType;
            gridAddEditorDs.add(new R({
                material_id: rec.get('material_id'), material_code: rec.get('material_code'),
                material_name: rec.get('material_name'), unit: rec.get('unit'),
                qty_out: rec.get('max_issue'), onhand: rec.get('onhand'), max_issue: rec.get('max_issue'),
                rab_qty: rec.get('rab_qty'), issued: rec.get('issued')
            }));
            fMat.clearValue();
        }

        var detailGrid = new Ext.grid.EditorGridPanel({
            region: 'center', store: gridAddEditorDs, clicksToEdit: 1, stripeRows: true,
            viewConfig: { forceFit: true },
            tbar: [fMat, { text: 'Tambah', handler: addMaterial }],
            bbar: ['Batas issue = min(stok gudang, RAB +5% - sudah di-issue). Qty melewati RAB (tapi masih +5%) ditandai allowance.'],
            columns: [
                { header: 'Kode', dataIndex: 'material_code', width: 90 },
                { header: 'Material', dataIndex: 'material_name', width: 200 },
                { header: 'Sat', dataIndex: 'unit', width: 50, align: 'center' },
                { header: 'Stok W/H', dataIndex: 'onhand', width: 90, align: 'right', renderer: fmtQty },
                { header: 'RAB', dataIndex: 'rab_qty', width: 90, align: 'right', renderer: fmtQty },
                { header: 'Sudah Issue', dataIndex: 'issued', width: 90, align: 'right', renderer: fmtQty },
                { header: 'Maks Issue', dataIndex: 'max_issue', width: 90, align: 'right', renderer: fmtQty },
                { header: 'Qty Issue', dataIndex: 'qty_out', width: 90, align: 'right', renderer: fmtQty,
                  editor: new Ext.form.NumberField({ allowNegative: false, decimalPrecision: 4 }) },
                { header: '', dataIndex: 'material_id', width: 30, align: 'center', fixed: true,
                  renderer: function () { return '<span style="color:#e05353;cursor:pointer;font-weight:bold;">&#10005;</span>'; } }
            ],
            listeners: {
                // tolak qty > batas (server tetap memvalidasi ulang)
                validateedit: function (e) {
                    if (e.field === 'qty_out' && parseFloat(e.value) > e.record.get('max_issue') + 0.00005) {
                        showErr('Qty melebihi batas issue (' + fmtQty(e.record.get('max_issue')) + ').');
                        e.cancel = true;
                    }
                },
                cellclick: function (g, row, col) { if (col === g.getColumnModel().getColumnCount() - 1) { g.getStore().removeAt(row); } }
            }
        });

        var form = new Ext.form.FormPanel({
            region: 'north', height: 130, border: false, layout: 'column', bodyStyle: 'padding:8px 6px 0 6px;',
            items: [
                { columnWidth: 0.5, layout: 'form', border: false, labelWidth: 110, items: [fProject, fWh, fDate] },
                { columnWidth: 0.5, layout: 'form', border: false, labelWidth: 110, items: [fMandor, fRemark] }
            ]
        });

        var win = new Ext.Window({
            title: 'Warehouse Issuing Baru', width: 960, height: 600, modal: true, layout: 'border',
            items: [form, detailGrid],
            buttons: [
                { text: 'Simpan & Posting', handler: function () {
                    if (!fProject.getValue() || !fWh.getValue() || !fDate.getValue()) { showErr('Proyek, gudang, dan tanggal wajib diisi.'); return; }
                    var arr = [], err = '';
                    gridAddEditorDs.each(function (r) {
                        var q = parseFloat(r.get('qty_out')) || 0;
                        if (q <= 0) { err = 'Qty issue ' + r.get('material_name') + ' harus lebih besar dari 0.'; return false; }
                        arr.push({ material_id: r.get('material_id'), qty_out: q });
                    });
                    if (err) { showErr(err); return; }
                    if (!arr.length) { showErr('Tambahkan minimal satu material.'); return; }
                    // [FIX-DATE] ambil semua nilai field SEBELUM window ditutup, tanggal lewat ymd()
                    var params = {
                        action: 'save', project_id: fProject.getValue(), warehouse_id: fWh.getValue(),
                        issue_date: ymd(fDate.getValue()),
                        received_by_mandor: fMandor.getValue(), remark: fRemark.getValue(),
                        details: Ext.util.JSON.encode(arr)       // detail bertingkat -> JSON via POST
                    };
                    ajaxPost(params, function (o) { win.close(); Ext.Msg.alert('Sukses', o.message); mainDs.reload(); });
                } },
                { text: 'Batal', handler: function () { win.close(); } }
            ]
        });
        win.show();
        projectDs.load();
    }
});