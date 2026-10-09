/*
  Document    : stock.opname.js
  Description : Stock Opname (draft -> posting -> reverse). ExtJS 3.4.1
  Backend     : php-crud/stock.opnameCRD.php
  CATATAN     : container render diasumsikan div #main-content (fallback ke body).
*/
Ext.onReady(function () {
    Ext.QuickTips.init();

    var URL = 'php-crud/stock.opnameCRD.php';
    var RENDER_TO = Ext.get('main-content') ? 'main-content' : Ext.getBody();
    var dataRecord = null;

    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; } else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
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
        var c = (v === 'posted') ? '#2e9e4f' : (v === 'draft' ? '#e6a817' : '#c0392b');
        return '<span style="color:' + c + ';font-weight:bold;">' + enc(v) + '</span>';
    }
    function diffRenderer(v) {
        v = parseFloat(v) || 0;
        var c = v > 0 ? '#2e9e4f' : (v < 0 ? '#c0392b' : '#888');
        return '<span style="color:' + c + ';font-weight:bold;">' + fmtQty(v) + '</span>';
    }

    // ---------------- grid utama ----------------
    mainDs = new Ext.data.JsonStore({      // assignment polos (konvensi template)
        url: URL, baseParams: { action: 'list' }, root: 'data', totalProperty: 'totalCount', idProperty: 'id',
        fields: ['id', 'ref_no', 'opname_date', 'warehouse_id', 'warehouse_name', 'status', 'remark',
                 { name: 'line_count', type: 'int' }, { name: 'diff_value', type: 'float' }],
        listeners: { exception: storeError }
    });
    var searchField = new Ext.form.TextField({
        emptyText: 'Cari No / Gudang...', width: 200, enableKeyEvents: true,
        listeners: { specialkey: function (f, e) { if (e.getKey() === e.ENTER) { doSearch(); } } }
    });
    function doSearch() { mainDs.baseParams.query = searchField.getValue(); mainDs.load({ params: { start: 0, limit: 25 } }); }

    function needRec(statusWanted, msg) {
        if (!dataRecord) { showErr('Pilih dokumen terlebih dahulu.'); return false; }
        if (statusWanted && dataRecord.status !== statusWanted) { showErr(msg); return false; }
        return true;
    }
    function confirmAction(title, text, params, doneMsg) {
        Ext.Msg.confirm(title, text, function (b) {
            if (b !== 'yes') { return; }
            ajaxPost(params, function (o) { Ext.Msg.alert('Sukses', o.message || doneMsg); dataRecord = null; mainDs.reload(); });
        });
    }

    new Ext.grid.GridPanel({
        store: mainDs, renderTo: RENDER_TO, title: 'Stock Opname',
        height: Math.max(420, Ext.getBody().getViewSize().height - 140), loadMask: true, stripeRows: true,
        sm: new Ext.grid.RowSelectionModel({ singleSelect: true }), viewConfig: { forceFit: true },
        columns: [
            { header: 'No. Opname', dataIndex: 'ref_no', width: 110 },
            { header: 'Tanggal', dataIndex: 'opname_date', width: 85, align: 'center' },
            { header: 'Gudang', dataIndex: 'warehouse_name', width: 180 },
            { header: 'Item', dataIndex: 'line_count', width: 50, align: 'center' },
            { header: 'Nilai Selisih', dataIndex: 'diff_value', width: 120, align: 'right', renderer: fmtAmt },
            { header: 'Status', dataIndex: 'status', width: 80, align: 'center', renderer: statusRenderer }
        ],
        tbar: [
            { text: 'Opname Baru', handler: function () { openEditor(null); } },
            { text: 'Edit Draft', handler: function () {
                if (!needRec('draft', 'Hanya dokumen draft yang dapat diedit.')) { return; }
                openEditor(dataRecord);
            } },
            { text: 'Posting', handler: function () {
                if (!needRec('draft', 'Hanya dokumen draft yang dapat diposting.')) { return; }
                confirmAction('Posting Opname', 'Posting <b>' + enc(dataRecord.ref_no) + '</b>? Selisih akan masuk ke stok dan tidak bisa diedit lagi.',
                    { action: 'post', id: dataRecord.id });
            } },
            { text: 'Detail', handler: function () { if (needRec()) { openDetail(dataRecord); } } },
            { text: 'Reverse', handler: function () {
                if (!needRec('posted', 'Hanya dokumen posted yang dapat di-reverse.')) { return; }
                confirmAction('Reverse Opname', 'Reverse <b>' + enc(dataRecord.ref_no) + '</b>? Mutasi selisih dihapus dari stok.',
                    { action: 'reverse', id: dataRecord.id });
            } },
            { text: 'Hapus Draft', handler: function () {
                if (!needRec('draft', 'Hanya dokumen draft yang dapat dihapus.')) { return; }
                confirmAction('Hapus Draft', 'Hapus draft <b>' + enc(dataRecord.ref_no) + '</b>?',
                    { action: 'delete', id: dataRecord.id });
            } },
            '->', searchField, { text: 'Cari', handler: doSearch }
        ],
        bbar: new Ext.PagingToolbar({ store: mainDs, pageSize: 25, displayInfo: true }),
        listeners: { rowclick: function (g, i) { var r = g.getStore().getAt(i); dataRecord = r ? r.data : null; } }
    });
    mainDs.load({ params: { start: 0, limit: 25 } });

    // ---------------- detail (read-only) ----------------
    function openDetail(rec) {
        var ds = new Ext.data.JsonStore({
            url: URL, baseParams: { action: 'detail', id: rec.id }, root: 'data',
            fields: ['material_code', 'material_name', 'unit', { name: 'system_qty', type: 'float' },
                     { name: 'counted_qty', type: 'float' }, { name: 'diff_qty', type: 'float' },
                     { name: 'unit_cost', type: 'float' }, { name: 'diff_value', type: 'float' }],
            listeners: { exception: storeError }
        });
        new Ext.Window({
            title: 'Detail Opname ' + rec.ref_no, width: 860, height: 400, modal: true, layout: 'fit',
            items: new Ext.grid.GridPanel({
                store: ds, stripeRows: true, viewConfig: { forceFit: true },
                columns: [
                    { header: 'Kode', dataIndex: 'material_code', width: 90 },
                    { header: 'Material', dataIndex: 'material_name', width: 200 },
                    { header: 'Sat', dataIndex: 'unit', width: 50, align: 'center' },
                    { header: 'Stok Sistem', dataIndex: 'system_qty', width: 100, align: 'right', renderer: fmtQty },
                    { header: 'Qty Hitung', dataIndex: 'counted_qty', width: 100, align: 'right', renderer: fmtQty },
                    { header: 'Selisih', dataIndex: 'diff_qty', width: 100, align: 'right', renderer: diffRenderer },
                    { header: 'Biaya Satuan', dataIndex: 'unit_cost', width: 100, align: 'right', renderer: fmtAmt },
                    { header: 'Nilai Selisih', dataIndex: 'diff_value', width: 110, align: 'right', renderer: fmtAmt }
                ]
            })
        }).show();
        ds.load();
    }

    // ---------------- editor draft ----------------
    var whDs = new Ext.data.JsonStore({
        url: URL, baseParams: { action: 'lookup_warehouses' }, root: 'data', fields: ['id', 'name'],
        listeners: { exception: storeError }
    });
    var itemDs = new Ext.data.JsonStore({
        url: URL, baseParams: { action: 'lookup_items' }, root: 'data',
        fields: ['item_id', 'material_code', 'material_name', 'unit', { name: 'system_qty', type: 'float' }],
        listeners: { exception: storeError }
    });
    gridAddEditorDs = new Ext.data.JsonStore({      // store detail editor
        root: 'data', fields: ['item_id', 'material_code', 'material_name', 'unit',
            { name: 'system_qty', type: 'float' }, { name: 'counted_qty', type: 'float' }, 'remark']
    });

    function openEditor(rec) {                      // rec null = baru, rec = edit draft
        gridAddEditorDs.removeAll();
        var editId = rec ? rec.id : 0;

        var fWh = new Ext.form.ComboBox({
            fieldLabel: 'Gudang', store: whDs, displayField: 'name', valueField: 'id', mode: 'local',
            triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%'
        });
        var fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        var fRemark = new Ext.form.TextField({ fieldLabel: 'Catatan', anchor: '95%', value: rec ? rec.remark : '' });

        fWh.on('select', function () { gridAddEditorDs.removeAll(); fItem.clearValue(); });

        var fItem = new Ext.form.ComboBox({
            store: itemDs, displayField: 'material_name', valueField: 'item_id', mode: 'remote', queryParam: 'query',
            minChars: 0, triggerAction: 'all', forceSelection: true, width: 340, listWidth: 480,
            emptyText: 'Tambah item manual...',
            tpl: '<tpl for="."><div class="x-combo-list-item">{material_code} - {material_name} <b>(sistem {system_qty} {unit})</b></div></tpl>',
            listeners: {
                beforequery: function () {
                    if (!fWh.getValue()) { showErr('Pilih gudang terlebih dahulu.'); return false; }
                    itemDs.baseParams.warehouse_id = fWh.getValue();
                }
            }
        });
        function addRow(d) {
            if (gridAddEditorDs.find('item_id', d.item_id) >= 0) { return false; }
            var R = gridAddEditorDs.recordType;
            gridAddEditorDs.add(new R(d));
            return true;
        }
        function addItem() {
            var r = fItem.findRecord('item_id', fItem.getValue());
            if (!r) { showErr('Pilih item terlebih dahulu.'); return; }
            if (!addRow({ item_id: r.get('item_id'), material_code: r.get('material_code'), material_name: r.get('material_name'),
                          unit: r.get('unit'), system_qty: r.get('system_qty'), counted_qty: r.get('system_qty'), remark: '' })) {
                showErr('Item sudah ada di daftar.');
            }
            fItem.clearValue();
        }
        // muat semua item berstok di gudang sebagai lembar hitung
        function loadSystem() {
            if (!fWh.getValue()) { showErr('Pilih gudang terlebih dahulu.'); return; }
            Ext.Ajax.request({
                url: URL, method: 'POST', params: { action: 'load_system', warehouse_id: fWh.getValue() },
                success: function (resp) {
                    var o;
                    try { o = Ext.decode(resp.responseText); } catch (e) { showErr('Respon server tidak valid.'); return; }
                    if (!o.success) { showErr(o.message); return; }
                    gridAddEditorDs.removeAll();
                    Ext.each(o.data, function (d) {
                        addRow({ item_id: d.item_id, material_code: d.material_code, material_name: d.material_name, unit: d.unit,
                                 system_qty: parseFloat(d.system_qty), counted_qty: parseFloat(d.system_qty), remark: '' });
                    });
                },
                failure: function (resp) { showErr('Koneksi ke server gagal (HTTP ' + resp.status + ').'); }
            });
        }

        var detailGrid = new Ext.grid.EditorGridPanel({
            region: 'center', store: gridAddEditorDs, clicksToEdit: 1, stripeRows: true, viewConfig: { forceFit: true },
            tbar: [{ text: 'Muat Semua Item Berstok', handler: function () {
                        if (gridAddEditorDs.getCount()) {
                            Ext.Msg.confirm('Muat Item', 'Daftar saat ini akan diganti. Lanjutkan?', function (b) { if (b === 'yes') { loadSystem(); } });
                        } else { loadSystem(); }
                    } }, '-', fItem, { text: 'Tambah', handler: addItem }],
            columns: [
                { header: 'Kode', dataIndex: 'material_code', width: 90 },
                { header: 'Material', dataIndex: 'material_name', width: 200 },
                { header: 'Sat', dataIndex: 'unit', width: 50, align: 'center' },
                { header: 'Stok Sistem', dataIndex: 'system_qty', width: 100, align: 'right', renderer: fmtQty },
                { header: 'Qty Hitung', dataIndex: 'counted_qty', width: 100, align: 'right', renderer: fmtQty,
                  editor: new Ext.form.NumberField({ allowNegative: false, decimalPrecision: 4 }) },
                { header: 'Selisih', width: 100, align: 'right',
                  renderer: function (v, m, r) { return diffRenderer((parseFloat(r.get('counted_qty')) || 0) - (parseFloat(r.get('system_qty')) || 0)); } },
                { header: 'Catatan', dataIndex: 'remark', width: 140, editor: new Ext.form.TextField() },
                { header: '', dataIndex: 'item_id', width: 30, align: 'center', fixed: true,
                  renderer: function () { return '<span style="color:#e05353;cursor:pointer;font-weight:bold;">&#10005;</span>'; } }
            ],
            bbar: ['Stok sistem dihitung ulang saat Simpan dan saat Posting; selisih final ditentukan saat Posting.'],
            listeners: {
                cellclick: function (g, row, col) { if (col === g.getColumnModel().getColumnCount() - 1) { g.getStore().removeAt(row); } }
            }
        });

        var form = new Ext.form.FormPanel({
            region: 'north', height: 90, border: false, layout: 'column', bodyStyle: 'padding:8px 6px 0 6px;',
            items: [
                { columnWidth: 0.5, layout: 'form', border: false, labelWidth: 80, items: [fWh, fDate] },
                { columnWidth: 0.5, layout: 'form', border: false, labelWidth: 80, items: [fRemark] }
            ]
        });

        var win = new Ext.Window({
            title: rec ? 'Edit Draft Opname ' + rec.ref_no : 'Stock Opname Baru', width: 920, height: 580, modal: true, layout: 'border',
            items: [form, detailGrid],
            buttons: [
                { text: 'Simpan Draft', handler: function () {
                    if (!fWh.getValue() || !fDate.getValue()) { showErr('Gudang dan tanggal wajib diisi.'); return; }
                    var arr = [], err = '';
                    gridAddEditorDs.each(function (r) {
                        var q = parseFloat(r.get('counted_qty'));
                        if (isNaN(q) || q < 0) { err = 'Qty hitung ' + r.get('material_name') + ' harus angka >= 0.'; return false; }
                        arr.push({ item_id: r.get('item_id'), counted_qty: q, remark: r.get('remark') || '' });
                    });
                    if (err) { showErr(err); return; }
                    if (!arr.length) { showErr('Tambahkan minimal satu item.'); return; }
                    ajaxPost({
                        action: 'save', id: editId, warehouse_id: fWh.getValue(),
                        opname_date: fDate.getValue().format('Y-m-d'), remark: fRemark.getValue(),
                        details: Ext.util.JSON.encode(arr)
                    }, function (o) { win.close(); Ext.Msg.alert('Sukses', o.message); mainDs.reload(); });
                } },
                { text: 'Batal', handler: function () { win.close(); } }
            ]
        });
        win.show();

        // edit: tunggu combo gudang terisi, lalu pasang nilai & detail (form sudah ter-render saat window tampil)
        whDs.load({ callback: function () {
            if (!rec) { return; }
            fWh.setValue(rec.warehouse_id);
            fDate.setValue(Date.parseDate(rec.opname_date, 'Y-m-d') || new Date());
            Ext.Ajax.request({
                url: URL, method: 'POST', params: { action: 'detail', id: rec.id },
                success: function (resp) {
                    var o;
                    try { o = Ext.decode(resp.responseText); } catch (e) { showErr('Respon server tidak valid.'); return; }
                    if (!o.success) { showErr(o.message); return; }
                    Ext.each(o.data, function (d) {
                        addRow({ item_id: d.item_id, material_code: d.material_code, material_name: d.material_name, unit: d.unit,
                                 system_qty: parseFloat(d.system_qty), counted_qty: parseFloat(d.counted_qty), remark: d.remark || '' });
                    });
                },
                failure: function (resp) { showErr('Koneksi ke server gagal (HTTP ' + resp.status + ').'); }
            });
        } });
    }
});