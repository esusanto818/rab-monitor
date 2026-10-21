/*
  Document    : stock.card.js
  Description : Stock Card (kartu stok) per item, per gudang / semua gudang. ExtJS 3.4.1
  Backend     : ../php-crud/stock.cardCRD.php
  REVISI      : - URL backend relatif terhadap /rab-project/views/ (sama seperti po.js)      [FIX #1]
                - render ke #divMainGrid (container standar template), bukan #main-content [FIX #2]
                - header, tombol Add/Edit/Delete dinonaktifkan seperti modul lain          [FIX #3]
                - popup error menampilkan cuplikan respons server (debug HTTP 200)         [FIX #4]
                - style toolbar/status bar agar terbaca di tema gelap                      [FIX #5]
                - format tanggal YYYY-MM-DD disusun manual (ymd) -> 'Y-10-1' bug           [FIX #6]
*/
Ext.onReady(function () {
    Ext.QuickTips.init();

    // [FIX #3] judul header + tombol toolbar template tidak dipakai di halaman read-only ini
    if (document.getElementById('mainHeaderText')) {
        document.getElementById('mainHeaderText').innerHTML = 'Kartu <em>Stok</em>';
    }
    try { editDataDisable(); } catch (e) {}
    try { mainBtnDisable('#addData'); mainBtnDisable('#editData'); mainBtnDisable('#deleteData'); } catch (e) {}

    // [FIX #5] CSS tema gelap untuk toolbar filter, tombol, dropdown dan status bar
    Ext.util.CSS.createStyleSheet(
        '.kartu-tbar, .kartu-bbar { background:#0b1d2b !important; border-color:#1d3a52 !important; }' +
        '.kartu-tbar .x-toolbar-text, .kartu-bbar .x-toolbar-text { color:#ffffff !important; font-size:12px; }' +
        '.kartu-tbar input.x-form-field { background:#102a3c !important; color:#ffffff !important; border:1px solid #2e6da4 !important; }' +
        '.kartu-tbar .x-form-field-wrap .x-form-trigger { opacity:1; }' +
        '.kartu-tbar .x-btn button, .kartu-tbar .x-btn-text { color:#ffffff !important; font-weight:bold; }' +
        '.kartu-tbar .x-btn { background:#2e6da4 !important; border-radius:3px; }' +
        '.x-combo-list { background:#0b1d2b !important; border:1px solid #2e6da4 !important; }' +
        '.x-combo-list .x-combo-list-item { color:#ffffff !important; background:#0b1d2b !important; }' +
        '.x-combo-list .x-combo-selected { background:#2e6da4 !important; }',
        'kartu-stok-css'
    );

    // [FIX #1] sebelumnya 'php-crud/stock.cardCRD.php' -> 404 karena halaman berada di /rab-project/views/
    var URL = '../php-crud/stock.cardCRD.php';
    // [FIX #2] container standar template
    var RENDER_TO = 'divMainGrid';

    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }
    function fmtQty(v) { return (v === null || v === undefined || v === '') ? '' : Ext.util.Format.number(v, '0,000.0000'); }
    function fmtAmt(v) { return (v === null || v === undefined || v === '') ? '' : Ext.util.Format.number(v, '0,000.00'); }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    // [FIX #6] Date.format('Y-m-d') di halaman ini menghasilkan 'Y-10-1' (tahun tidak terformat, tanpa nol depan);
    //          susun manual YYYY-MM-DD agar sesuai standar backend.
    function ymd(d) {
        var m = d.getMonth() + 1, day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
    }

    // [FIX #4] tampilkan pesan server; bila respons bukan JSON valid tampilkan 300 karakter pertamanya
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) {
            m = res.message;
        } else if (res && res.status) {
            m += ' (HTTP ' + res.status + ')';
            var t = (res.responseText || '').replace(/^\s+|\s+$/g, '');
            m += '<br/><br/><span style="font-size:11px;">Respons server: ' +
                 (t ? '<pre style="white-space:pre-wrap;">' + enc(t.substr(0, 300)) + '</pre>' : '<i>(kosong - cek error_log PHP)</i>') + '</span>';
        }
        showErr(m);
    }

    // ---------------- stores ----------------
    var whDs = new Ext.data.JsonStore({
        url: URL, baseParams: { action: 'lookup_warehouses' }, root: 'data', fields: ['id', 'name'],
        listeners: { exception: storeError }
    });
    var itemDs = new Ext.data.JsonStore({
        url: URL, baseParams: { action: 'lookup_items' }, root: 'data',
        fields: ['item_id', 'material_code', 'material_name', 'unit'],
        listeners: { exception: storeError }
    });
    mainDs = new Ext.data.JsonStore({      // assignment polos (konvensi template)
        url: URL, baseParams: { action: 'card' }, root: 'data', totalProperty: 'totalCount',
        fields: ['row_type', 'movement_date', 'reference_type', 'reference_no', 'warehouse_name', 'remark',
                 'qty_in', 'qty_out', 'unit_cost', 'balance'],
        listeners: {
            exception: storeError,
            load: function (store) {            // ringkasan periode di status bar
                var s = (store.reader.jsonData || {}).summary;
                if (s) {
                    sumBar.setText('Saldo awal: <b>' + fmtQty(s.opening) + '</b> &nbsp;|&nbsp; Masuk: <b>' + fmtQty(s.total_in) +
                        '</b> &nbsp;|&nbsp; Keluar: <b>' + fmtQty(s.total_out) + '</b> &nbsp;|&nbsp; Saldo akhir: <b>' + fmtQty(s.ending) + '</b>');
                }
            }
        }
    });

    // ---------------- filter ----------------
    var fWh = new Ext.form.ComboBox({
        store: whDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all',
        forceSelection: true, editable: false, width: 190, value: 0
    });
    var fItem = new Ext.form.ComboBox({
        store: itemDs, displayField: 'material_name', valueField: 'item_id', mode: 'remote', queryParam: 'query',
        minChars: 0, triggerAction: 'all', forceSelection: true, width: 320, listWidth: 440,
        emptyText: 'Cari item...',
        tpl: '<tpl for="."><div class="x-combo-list-item">{material_code} - {material_name} ({unit})</div></tpl>',
        listeners: { beforequery: function () { itemDs.baseParams.warehouse_id = fWh.getValue() || 0; } }
    });
    var today = new Date();
    var fFrom = new Ext.form.DateField({ format: 'd/m/Y', value: new Date(today.getFullYear(), today.getMonth(), 1), width: 105 });
    var fTo = new Ext.form.DateField({ format: 'd/m/Y', value: today, width: 105 });
    var sumBar = new Ext.Toolbar.TextItem('Pilih item lalu klik Tampilkan.');

    fWh.on('select', function () { fItem.clearValue(); });     // daftar item bergantung gudang

    function loadCard() {
        if (!fItem.getValue()) { showErr('Pilih item terlebih dahulu.'); return; }
        if (!fFrom.getValue() || !fTo.getValue()) { showErr('Periode wajib diisi.'); return; }
        if (fFrom.getValue() > fTo.getValue()) { showErr('Tanggal awal tidak boleh melebihi tanggal akhir.'); return; }
        mainDs.load({ params: {
            item_id: fItem.getValue(), warehouse_id: fWh.getValue() || 0,
            date_from: ymd(fFrom.getValue()), date_to: ymd(fTo.getValue())      // [FIX #6]
        } });
    }

    // [FIX #2] tinggi mengikuti helper template (sama seperti po.js)
    var gridHeight = 420;
    try { gridHeight = maxGridHeight(document.getElementById('divMainGrid')); } catch (e) {}

    new Ext.grid.GridPanel({
        store: mainDs, renderTo: RENDER_TO,
        height: gridHeight, loadMask: true, stripeRows: true,
        viewConfig: {
            forceFit: true,
            getRowClass: function (rec) { return rec.get('row_type') === 'OPEN' ? 'x-grid3-row-open' : ''; }
        },
        columns: [
            { header: 'Tanggal', dataIndex: 'movement_date', width: 85, align: 'center' },
            { header: 'Jenis', dataIndex: 'reference_type', width: 120 },
            { header: 'No. Dokumen', dataIndex: 'reference_no', width: 120 },
            { header: 'Gudang', dataIndex: 'warehouse_name', width: 140 },
            { header: 'Keterangan', dataIndex: 'remark', width: 160,
              renderer: function (v, m, r) { return r.get('row_type') === 'OPEN' ? '<b>' + enc(v) + '</b>' : enc(v); } },
            { header: 'Masuk', dataIndex: 'qty_in', width: 95, align: 'right', renderer: fmtQty },
            { header: 'Keluar', dataIndex: 'qty_out', width: 95, align: 'right', renderer: fmtQty },
            { header: 'Saldo', dataIndex: 'balance', width: 100, align: 'right',
              renderer: function (v) { return '<b>' + fmtQty(v) + '</b>'; } },
            { header: 'Biaya Satuan', dataIndex: 'unit_cost', width: 100, align: 'right', renderer: fmtAmt }
        ],
        // [FIX #5] toolbar diberi cls agar CSS tema gelap berlaku
        tbar: { cls: 'kartu-tbar', items: ['Gudang:', fWh, ' Item:', fItem, ' Dari:', fFrom, ' s/d:', fTo, { text: 'Tampilkan', handler: loadCard }] },
        bbar: { cls: 'kartu-bbar', items: [sumBar] }
    });
    whDs.load();
});