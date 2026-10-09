/*
  Document    : purchase.return.js
  Description : Retur Supplier untuk material cacat / rusak. ExtJS 3.4.1
  Alur        : Diajukan -> (Kirim lewat issue W/H) Dikirim -> (Selesaikan) Diganti | Kredit
  Backend     : php-crud/purchase.returnCRD.php
  CATATAN     : container render diasumsikan div #main-content (fallback ke body).
*/
Ext.onReady(function () {
    Ext.QuickTips.init();

    var URL = 'php-crud/purchase.returnCRD.php';
    var RENDER_TO = Ext.get('main-content') ? 'main-content' : Ext.getBody();
    var DEFECT_TYPES = ['Cacat Produksi', 'Rusak Saat Pengiriman', 'Tidak Sesuai Spesifikasi', 'Kualitas Di Bawah Standar', 'Lainnya'];
    var dataRecord = null;

    // ---------------- util ----------------
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
        var c = { Diajukan: '#e6a817', Dikirim: '#2bc1e2', Diganti: '#2e9e4f', Kredit: '#2e9e4f', Dibatalkan: '#c0392b' }[v] || '#888';
        return '<span style="color:' + c + ';font-weight:bold;">' + enc(v) + '</span>';
    }

    // ---------------- grid utama ----------------
    mainDs = new Ext.data.JsonStore({      // assignment polos (konvensi template)
        url: URL, baseParams: { action: 'list' }, root: 'data', totalProperty: 'totalCount', idProperty: 'id',
        fields: ['id', 'ref_no', 'return_date', 'project_id', 'project_name', 'warehouse_id', 'warehouse_name',
                 'supplier_id', 'supplier_name', 'po_id', 'po_number', 'supplier_do_no', 'status', 'remark',
                 'material_out_id', 'issue_ref_no', 'sent_date', 'carrier',
                 'resolution_type', 'resolution_date', 'credit_note_no', 'credit_amount', 'resolution_note',
                 { name: 'line_count', type: 'int' }, { name: 'total_qty', type: 'float' }, { name: 'total_amount', type: 'float' }],
        listeners: { exception: storeError }
    });
    var searchField = new Ext.form.TextField({
        emptyText: 'Cari No / Proyek / Supplier / DO...', width: 230, enableKeyEvents: true,
        listeners: { specialkey: function (f, e) { if (e.getKey() === e.ENTER) { doSearch(); } } }
    });
    function doSearch() { mainDs.baseParams.query = searchField.getValue(); mainDs.load({ params: { start: 0, limit: 25 } }); }

    function need(statusWanted, msg) {
        if (!dataRecord) { showErr('Pilih dokumen terlebih dahulu.'); return false; }
        if (statusWanted && dataRecord.status !== statusWanted) { showErr(msg); return false; }
        return true;
    }
    function confirmAction(title, text, params) {
        Ext.Msg.confirm(title, text, function (b) {
            if (b !== 'yes') { return; }
            ajaxPost(params, function (o) { Ext.Msg.alert('Sukses', o.message); dataRecord = null; mainDs.reload(); });
        });
    }

    new Ext.grid.GridPanel({
        store: mainDs, renderTo: RENDER_TO, title: 'Retur Supplier (Material Cacat / Rusak)',
        height: Math.max(420, Ext.getBody().getViewSize().height - 140), loadMask: true, stripeRows: true,
        sm: new Ext.grid.RowSelectionModel({ singleSelect: true }), viewConfig: { forceFit: true },
        columns: [
            { header: 'No. Retur', dataIndex: 'ref_no', width: 105 },
            { header: 'Tanggal', dataIndex: 'return_date', width: 85, align: 'center' },
            { header: 'Proyek', dataIndex: 'project_name', width: 170 },
            { header: 'Supplier', dataIndex: 'supplier_name', width: 150 },
            { header: 'PO', dataIndex: 'po_number', width: 100 },
            { header: 'Item', dataIndex: 'line_count', width: 45, align: 'center' },
            { header: 'Total Qty', dataIndex: 'total_qty', width: 90, align: 'right', renderer: fmtQty },
            { header: 'Nilai (FIFO)', dataIndex: 'total_amount', width: 105, align: 'right', renderer: fmtAmt },
            { header: 'Issue W/H', dataIndex: 'issue_ref_no', width: 105 },
            { header: 'Penyelesaian', dataIndex: 'resolution_type', width: 95,
              renderer: function (v, m, r) { return v ? enc(v) + (r.get('credit_amount') ? ' (' + fmtAmt(r.get('credit_amount')) + ')' : '') : '-'; } },
            { header: 'Status', dataIndex: 'status', width: 85, align: 'center', renderer: statusRenderer }
        ],
        tbar: [
            { text: 'Retur Baru', handler: function () { openEditor(null); } },
            { text: 'Edit', handler: function () {
                if (!need('Diajukan', 'Hanya retur berstatus Diajukan yang dapat diedit.')) { return; }
                openEditor(dataRecord);
            } },
            { text: 'Detail', handler: function () { if (need()) { openDetail(dataRecord); } } },
            '-',
            { text: 'Kirim (Issue W/H)', handler: function () {
                if (!need('Diajukan', 'Hanya retur berstatus Diajukan yang dapat dikirim.')) { return; }
                openSend(dataRecord);
            } },
            { text: 'Batal Kirim', handler: function () {
                if (!need('Dikirim', 'Hanya retur berstatus Dikirim yang dapat dibatalkan pengirimannya.')) { return; }
                confirmAction('Batal Kirim', 'Batalkan pengiriman <b>' + enc(dataRecord.ref_no) + '</b>? Issue W/H di-reverse dan stok kembali ke gudang.',
                    { action: 'unsend', id: dataRecord.id });
            } },
            { text: 'Selesaikan', handler: function () {
                if (!need('Dikirim', 'Hanya retur berstatus Dikirim yang dapat diselesaikan.')) { return; }
                openResolve(dataRecord);
            } },
            { text: 'Batalkan', handler: function () {
                if (!need('Diajukan', 'Hanya retur berstatus Diajukan yang dapat dibatalkan.')) { return; }
                confirmAction('Batalkan Retur', 'Batalkan retur <b>' + enc(dataRecord.ref_no) + '</b>?', { action: 'cancel', id: dataRecord.id });
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
            fields: ['material_code', 'material_name', 'unit', { name: 'qty_claim', type: 'float' },
                     { name: 'unit_cost', type: 'float' }, { name: 'amount', type: 'float' },
                     'defect_type', 'defect_note', 'evidence_ref'],
            listeners: { exception: storeError }
        });
        var info = 'Supplier: <b>' + enc(rec.supplier_name) + '</b> | Gudang: <b>' + enc(rec.warehouse_name) + '</b>' +
                   (rec.issue_ref_no ? ' | Issue W/H: <b>' + enc(rec.issue_ref_no) + '</b> (' + enc(rec.sent_date) + ', ' + enc(rec.carrier || '-') + ')' : '') +
                   (rec.resolution_type ? ' | Penyelesaian: <b>' + enc(rec.resolution_type) + '</b> ' + enc(rec.resolution_date) +
                       (rec.credit_note_no ? ' (NK ' + enc(rec.credit_note_no) + ')' : '') + (rec.resolution_note ? ' - ' + enc(rec.resolution_note) : '') : '');
        new Ext.Window({
            title: 'Detail Retur ' + rec.ref_no, width: 960, height: 420, modal: true, layout: 'fit',
            items: new Ext.grid.GridPanel({
                store: ds, stripeRows: true, viewConfig: { forceFit: true },
                tbar: [info],
                columns: [
                    { header: 'Kode', dataIndex: 'material_code', width: 80 },
                    { header: 'Material', dataIndex: 'material_name', width: 180 },
                    { header: 'Sat', dataIndex: 'unit', width: 45, align: 'center' },
                    { header: 'Qty', dataIndex: 'qty_claim', width: 80, align: 'right', renderer: fmtQty },
                    { header: 'Biaya Satuan', dataIndex: 'unit_cost', width: 95, align: 'right', renderer: fmtAmt },
                    { header: 'Nilai', dataIndex: 'amount', width: 100, align: 'right', renderer: fmtAmt },
                    { header: 'Jenis Cacat', dataIndex: 'defect_type', width: 140 },
                    { header: 'Keterangan', dataIndex: 'defect_note', width: 160, renderer: enc },
                    { header: 'Bukti', dataIndex: 'evidence_ref', width: 100, renderer: enc }
                ]
            })
        }).show();
        ds.load();
    }

    // ---------------- kirim (issue W/H) ----------------
    function openSend(rec) {
        var fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal Kirim', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        var fCarrier = new Ext.form.TextField({ fieldLabel: 'Kurir / Penerima', anchor: '95%' });
        var win = new Ext.Window({
            title: 'Kirim Retur ' + rec.ref_no + ' (Issue W/H)', width: 440, height: 190, modal: true, layout: 'fit',
            items: new Ext.form.FormPanel({
                border: false, labelWidth: 110, bodyStyle: 'padding:12px;',
                items: [{ xtype: 'displayfield', hideLabel: true,
                          value: 'Stok akan keluar dari gudang lewat dokumen issue W/H (FIFO). Qty tidak mengurangi kuota RAB.' },
                        fDate, fCarrier]
            }),
            buttons: [
                { text: 'Kirim', handler: function () {
                    if (!fDate.getValue()) { showErr('Tanggal kirim wajib diisi.'); return; }
                    ajaxPost({ action: 'send', id: rec.id, sent_date: fDate.getValue().format('Y-m-d'), carrier: fCarrier.getValue() },
                        function (o) { win.close(); Ext.Msg.alert('Sukses', o.message); dataRecord = null; mainDs.reload(); });
                } },
                { text: 'Batal', handler: function () { win.close(); } }
            ]
        });
        win.show();
    }

    // ---------------- selesaikan (ganti barang / kredit) ----------------
    function openResolve(rec) {
        var fType = new Ext.form.ComboBox({
            fieldLabel: 'Penyelesaian', store: ['Ganti Barang', 'Kredit'], triggerAction: 'all', editable: false,
            forceSelection: true, allowBlank: false, anchor: '95%', value: 'Ganti Barang'
        });
        var fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        var fCnNo = new Ext.form.TextField({ fieldLabel: 'No. Nota Kredit', anchor: '95%', disabled: true });
        var fCnAmt = new Ext.form.NumberField({ fieldLabel: 'Nilai Kredit', decimalPrecision: 2, allowNegative: false, width: 160,
            disabled: true, value: Math.round((rec.total_amount || 0) * 100) / 100 });
        var fNote = new Ext.form.TextField({ fieldLabel: 'Catatan', anchor: '95%' });
        fType.on('select', function () {
            var kredit = (fType.getValue() === 'Kredit');
            fCnNo.setDisabled(!kredit);
            fCnAmt.setDisabled(!kredit);
        });
        var win = new Ext.Window({
            title: 'Selesaikan Retur ' + rec.ref_no, width: 460, height: 290, modal: true, layout: 'fit',
            items: new Ext.form.FormPanel({
                border: false, labelWidth: 110, bodyStyle: 'padding:12px;',
                items: [fType, fDate, fCnNo, fCnAmt, fNote,
                        { xtype: 'displayfield', hideLabel: true,
                          value: '<span style="color:#888;">Ganti Barang: barang pengganti diterima lewat RCV biasa.</span>' }]
            }),
            buttons: [
                { text: 'Simpan', handler: function () {
                    if (!fDate.getValue()) { showErr('Tanggal wajib diisi.'); return; }
                    var kredit = (fType.getValue() === 'Kredit');
                    if (kredit && (!fCnNo.getValue() || !(parseFloat(fCnAmt.getValue()) > 0))) {
                        showErr('Kredit wajib mengisi no. nota kredit dan nilai kredit (> 0).'); return;
                    }
                    ajaxPost({
                        action: 'resolve', id: rec.id, resolution_type: fType.getValue(),
                        resolution_date: fDate.getValue().format('Y-m-d'),
                        credit_note_no: kredit ? fCnNo.getValue() : '', credit_amount: kredit ? fCnAmt.getValue() : 0,
                        resolution_note: fNote.getValue()
                    }, function (o) { win.close(); Ext.Msg.alert('Sukses', o.message); dataRecord = null; mainDs.reload(); });
                } },
                { text: 'Batal', handler: function () { win.close(); } }
            ]
        });
        win.show();
    }

    // ---------------- editor (baru / edit Diajukan) ----------------
    var projectDs = new Ext.data.JsonStore({ url: URL, baseParams: { action: 'lookup_projects' }, root: 'data',
        fields: ['id', 'name'], listeners: { exception: storeError } });
    var supplierDs = new Ext.data.JsonStore({ url: URL, baseParams: { action: 'lookup_suppliers' }, root: 'data',
        fields: ['id', 'name'], listeners: { exception: storeError } });
    var whDs = new Ext.data.JsonStore({ url: URL, baseParams: { action: 'lookup_warehouses' }, root: 'data',
        fields: ['id', 'code', 'name'], listeners: { exception: storeError } });
    var poDs = new Ext.data.JsonStore({ url: URL, baseParams: { action: 'lookup_pos' }, root: 'data',
        fields: ['id', 'po_number', 'po_date'], listeners: { exception: storeError } });
    var materialDs = new Ext.data.JsonStore({
        url: URL, baseParams: { action: 'lookup_materials' }, root: 'data',
        fields: ['material_id', 'material_code', 'material_name', 'unit', { name: 'onhand', type: 'float' },
                 { name: 'open_claim', type: 'float' }, { name: 'available', type: 'float' }],
        listeners: { exception: storeError }
    });
    gridAddEditorDs = new Ext.data.JsonStore({      // store detail editor
        root: 'data', fields: ['material_id', 'material_code', 'material_name', 'unit', 'available',
            { name: 'qty_claim', type: 'float' }, 'defect_type', 'defect_note', 'evidence_ref']
    });

    function openEditor(rec) {                      // rec null = baru
        gridAddEditorDs.removeAll();
        var editId = rec ? rec.id : 0;

        var cmb = function (label, store) {
            return new Ext.form.ComboBox({ fieldLabel: label, store: store, displayField: 'name', valueField: 'id', mode: 'local',
                triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%' });
        };
        var fProject = cmb('Proyek', projectDs);
        var fSupplier = cmb('Supplier', supplierDs);
        var fWh = cmb('Gudang', whDs);
        var fPo = new Ext.form.ComboBox({ fieldLabel: 'PO Referensi', store: poDs, displayField: 'po_number', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, anchor: '95%', value: 0 });
        var fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        var fDo = new Ext.form.TextField({ fieldLabel: 'No. DO Asal', anchor: '95%', value: rec ? rec.supplier_do_no : '' });
        var fRemark = new Ext.form.TextField({ fieldLabel: 'Catatan', anchor: '95%', value: rec ? rec.remark : '' });

        function reloadPo() {
            fPo.setValue(0);
            if (fProject.getValue() && fSupplier.getValue()) {
                poDs.load({ params: { project_id: fProject.getValue(), supplier_id: fSupplier.getValue() } });
            } else { poDs.removeAll(); }
        }
        fProject.on('select', function () {
            fWh.clearValue(); gridAddEditorDs.removeAll(); fMat.clearValue();
            whDs.load({ params: { project_id: fProject.getValue() } });
            reloadPo();
        });
        fSupplier.on('select', reloadPo);
        fWh.on('select', function () { gridAddEditorDs.removeAll(); fMat.clearValue(); });

        var fMat = new Ext.form.ComboBox({
            store: materialDs, displayField: 'material_name', valueField: 'material_id', mode: 'remote', queryParam: 'query',
            minChars: 0, triggerAction: 'all', forceSelection: true, width: 400, listWidth: 520,
            emptyText: 'Cari material yang sudah RCV di gudang...',
            tpl: '<tpl for="."><div class="x-combo-list-item">{material_code} - {material_name} ' +
                 '<b>(stok {onhand}, tersedia {available} {unit})</b></div></tpl>',
            listeners: {
                beforequery: function () {
                    if (!fWh.getValue()) { showErr('Pilih proyek dan gudang terlebih dahulu.'); return false; }
                    materialDs.baseParams.warehouse_id = fWh.getValue();
                    materialDs.baseParams.po_id = fPo.getValue() || 0;
                    materialDs.baseParams.exclude_id = editId;
                }
            }
        });
        function addMaterial() {
            var r = fMat.findRecord('material_id', fMat.getValue());
            if (!r) { showErr('Pilih material terlebih dahulu.'); return; }
            if (r.get('available') <= 0) { showErr('Stok tersedia kosong (sudah dicadangkan oleh klaim retur lain yang masih Diajukan).'); return; }
            var R = gridAddEditorDs.recordType;
            gridAddEditorDs.add(new R({
                material_id: r.get('material_id'), material_code: r.get('material_code'), material_name: r.get('material_name'),
                unit: r.get('unit'), available: r.get('available'), qty_claim: Math.min(1, r.get('available')),
                defect_type: DEFECT_TYPES[0], defect_note: '', evidence_ref: ''
            }));
            fMat.clearValue();
        }

        var detailGrid = new Ext.grid.EditorGridPanel({
            region: 'center', store: gridAddEditorDs, clicksToEdit: 1, stripeRows: true, viewConfig: { forceFit: true },
            tbar: [fMat, { text: 'Tambah', handler: addMaterial }],
            bbar: ['Satu material boleh muncul lebih dari sekali (beda jenis cacat); total qty dibatasi stok tersedia.'],
            columns: [
                { header: 'Kode', dataIndex: 'material_code', width: 80 },
                { header: 'Material', dataIndex: 'material_name', width: 170 },
                { header: 'Sat', dataIndex: 'unit', width: 45, align: 'center' },
                { header: 'Tersedia', dataIndex: 'available', width: 80, align: 'right',
                  renderer: function (v) { return (v === null || v === undefined) ? '' : fmtQty(v); } },
                { header: 'Qty Retur', dataIndex: 'qty_claim', width: 85, align: 'right', renderer: fmtQty,
                  editor: new Ext.form.NumberField({ allowNegative: false, decimalPrecision: 4 }) },
                { header: 'Jenis Cacat', dataIndex: 'defect_type', width: 150,
                  editor: new Ext.form.ComboBox({ store: DEFECT_TYPES, triggerAction: 'all', editable: false, forceSelection: true }) },
                { header: 'Keterangan', dataIndex: 'defect_note', width: 160, editor: new Ext.form.TextField() },
                { header: 'Bukti (no. foto/link)', dataIndex: 'evidence_ref', width: 120, editor: new Ext.form.TextField() },
                { header: '', dataIndex: 'material_id', width: 30, align: 'center', fixed: true,
                  renderer: function () { return '<span style="color:#e05353;cursor:pointer;font-weight:bold;">&#10005;</span>'; } }
            ],
            listeners: {
                validateedit: function (e) {
                    var av = e.record.get('available');
                    if (e.field === 'qty_claim' && av !== null && av !== undefined && parseFloat(e.value) > av + 0.00005) {
                        showErr('Qty melebihi stok tersedia (' + fmtQty(av) + ').');
                        e.cancel = true;
                    }
                },
                cellclick: function (g, row, col) { if (col === g.getColumnModel().getColumnCount() - 1) { g.getStore().removeAt(row); } }
            }
        });

        var form = new Ext.form.FormPanel({
            region: 'north', height: 135, border: false, layout: 'column', bodyStyle: 'padding:8px 6px 0 6px;',
            items: [
                { columnWidth: 0.34, layout: 'form', border: false, labelWidth: 90, items: [fProject, fWh, fDate] },
                { columnWidth: 0.33, layout: 'form', border: false, labelWidth: 90, items: [fSupplier, fPo] },
                { columnWidth: 0.33, layout: 'form', border: false, labelWidth: 90, items: [fDo, fRemark] }
            ]
        });

        var win = new Ext.Window({
            title: rec ? 'Edit Retur ' + rec.ref_no : 'Retur Supplier Baru', width: 1020, height: 600, modal: true, layout: 'border',
            items: [form, detailGrid],
            buttons: [
                { text: 'Simpan', handler: function () {
                    if (!fProject.getValue() || !fWh.getValue() || !fSupplier.getValue() || !fDate.getValue()) {
                        showErr('Proyek, gudang, supplier, dan tanggal wajib diisi.'); return;
                    }
                    var arr = [], err = '';
                    gridAddEditorDs.each(function (r) {
                        var q = parseFloat(r.get('qty_claim')) || 0;
                        if (q <= 0) { err = 'Qty retur ' + r.get('material_name') + ' harus lebih besar dari 0.'; return false; }
                        if (!r.get('defect_type')) { err = 'Jenis cacat ' + r.get('material_name') + ' wajib dipilih.'; return false; }
                        arr.push({ material_id: r.get('material_id'), qty_claim: q, defect_type: r.get('defect_type'),
                                   defect_note: r.get('defect_note') || '', evidence_ref: r.get('evidence_ref') || '' });
                    });
                    if (err) { showErr(err); return; }
                    if (!arr.length) { showErr('Tambahkan minimal satu material.'); return; }
                    ajaxPost({
                        action: 'save', id: editId, project_id: fProject.getValue(), warehouse_id: fWh.getValue(),
                        supplier_id: fSupplier.getValue(), po_id: fPo.getValue() || 0,
                        return_date: fDate.getValue().format('Y-m-d'), supplier_do_no: fDo.getValue(), remark: fRemark.getValue(),
                        details: Ext.util.JSON.encode(arr)      // detail bertingkat -> JSON via POST
                    }, function (o) { win.close(); Ext.Msg.alert('Sukses', o.message); mainDs.reload(); });
                } },
                { text: 'Batal', handler: function () { win.close(); } }
            ]
        });
        win.show();

        // muat master (berantai) lalu, bila edit, pasang nilai header + detail
        projectDs.load({ callback: function () {
            supplierDs.load({ callback: function () {
                if (!rec) { return; }
                whDs.load({ params: { project_id: rec.project_id }, callback: function () {
                    poDs.load({ params: { project_id: rec.project_id, supplier_id: rec.supplier_id }, callback: function () {
                        fProject.setValue(rec.project_id);
                        fSupplier.setValue(rec.supplier_id);
                        fWh.setValue(rec.warehouse_id);
                        fPo.setValue(rec.po_id || 0);
                        fDate.setValue(Date.parseDate(rec.return_date, 'Y-m-d') || new Date());
                        Ext.Ajax.request({
                            url: URL, method: 'POST', params: { action: 'detail', id: rec.id },
                            success: function (resp) {
                                var o;
                                try { o = Ext.decode(resp.responseText); } catch (e) { showErr('Respon server tidak valid.'); return; }
                                if (!o.success) { showErr(o.message); return; }
                                var R = gridAddEditorDs.recordType;
                                Ext.each(o.data, function (d) {
                                    gridAddEditorDs.add(new R({
                                        material_id: d.material_id, material_code: d.material_code, material_name: d.material_name,
                                        unit: d.unit, available: null, qty_claim: parseFloat(d.qty_claim),
                                        defect_type: d.defect_type, defect_note: d.defect_note || '', evidence_ref: d.evidence_ref || ''
                                    }));
                                });
                            },
                            failure: function (resp) { showErr('Koneksi ke server gagal (HTTP ' + resp.status + ').'); }
                        });
                    } });
                } });
            } });
        } });
    }
});