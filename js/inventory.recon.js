/*
  Document      : inventory.recon.js
  Description   : Rekonsiliasi Persediaan (read-only). Membandingkan nilai BUKU (saldo akun persediaan di buku besar)
                  dengan nilai STOK (ledger stock_movements, IN - OUT bernilai FIFO) sampai tanggal tertentu.
                  Ringkasan: nilai buku, nilai stok, selisih, bagian selisih yang dijelaskan dokumen bermasalah, sisa.
                  Tab: Per Item | Per Gudang | Dokumen vs Jurnal (status OK / TIDAK ADA JURNAL / NILAI BEDA / N/A).
                  Pola mengikuti trialbalance.js (filter bar + summary + grid) dan po.js.
  Backend       : /proyek/php-crud/inventory.reconCRD.php  (logika: php-func/function.inventory.recon.php)
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Rekonsiliasi <em>Persediaan</em>";

    ['addData', 'editData', 'deleteData'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    // [FIX-EDITOR] Template menampilkan window editor/add secara default; halaman laporan ini tidak memakainya -> sembunyikan,
    //              kalau tidak kerangka kosong "SAVE / DISCARD" menutupi seluruh halaman (filter, ringkasan, tab).
    ['editorWdw', 'addWdw'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    var CRD = '../php-crud/inventory.reconCRD.php';

    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    // Workaround: Date.format('Y-m-d') pernah mengembalikan 'Y-10-10' di sebagian browser -> format manual
    function ymd(d) {
        var m = d.getMonth() + 1, day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (day < 10 ? '0' + day : day);
    }
    function showMsg(title, msg) { Ext.Msg.alert(title, msg); }
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; } else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
        showMsg('Gagal', m);
    }

    // ============================================================
    // 0. Elemen filter bar & summary (dibuat dinamis, sama seperti trialbalance.js)
    // ============================================================
    var gridContainer = document.getElementById('divMainGrid');
    var filterBarDiv = document.createElement('div');
    filterBarDiv.id = 'irFilterBar';
    filterBarDiv.style.padding = '0 10px';
    var summaryDiv = document.createElement('div');
    summaryDiv.id = 'irSummary';
    summaryDiv.style.cssText = 'padding:10px 14px; font-size:14px; margin:0 10px 10px 10px; border-radius:4px; background:rgba(255,255,255,0.05); color:#fff;';
    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(filterBarDiv, gridContainer);
        gridContainer.parentNode.insertBefore(summaryDiv, gridContainer);
    }

    // ============================================================
    // 1. Stores
    // ============================================================
    var accDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_accounts' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' },
            [{ name: 'id', type: 'int' }, { name: 'label' }, { name: 'is_default' }]),
        listeners: {
            exception: storeError,
            load: function (store) {
                var defId = null;
                store.each(function (r) {
                    var d = r.get('is_default');
                    if (defId === null && (d === true || d === 'true' || d === 1 || d === '1')) defId = r.get('id');
                });
                if (defId === null && store.getCount()) defId = store.getAt(0).get('id');
                if (defId !== null && !fldAcc.getValue()) fldAcc.setValue(defId);
                loadRecon();
            }
        }
    });

    function jsonStore(fields) {
        return new Ext.data.Store({
            reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, fields),
            listeners: { exception: storeError }
        });
    }
    var itemDs = jsonStore([{ name: 'material_code' }, { name: 'material_name' }, { name: 'unit' },
                            { name: 'qty', type: 'float' }, { name: 'avg_cost', type: 'float' }, { name: 'value', type: 'float' }]);
    var whDs = jsonStore([{ name: 'warehouse_name' }, { name: 'item_count', type: 'int' }, { name: 'value', type: 'float' }]);
    var docDs = jsonStore([{ name: 'doc_date' }, { name: 'reference_type' }, { name: 'reference_no' },
                           { name: 'value', type: 'float' }, { name: 'journal_amount' }, { name: 'status' }]);

    // ============================================================
    // 2. Filter bar
    // ============================================================
    var fldDate = new Ext.form.DateField({ fieldLabel: 'Per Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
    var fldAcc = new myComboField({
        fieldLabel: 'Akun Persediaan', width: 260, store: accDs, displayField: 'label', valueField: 'id',
        mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false
    });
    var fldOnlyIssue = new Ext.form.Checkbox({ boxLabel: 'Dokumen: hanya yang bermasalah', checked: false });
    var btnShow = new Ext.Button({ text: 'Tampilkan', cls: 'field-button grey-field-button' });

    new Ext.Panel({
        renderTo: 'irFilterBar',
        layout: 'column',
        border: false,
        bodyStyle: 'background:transparent; padding:8px 0;',
        defaults: { border: false, bodyStyle: 'background:transparent;' },
        items: [
            { columnWidth: 0.22, layout: 'form', labelWidth: 75, items: fldDate },
            { columnWidth: 0.36, layout: 'form', labelWidth: 110, items: fldAcc },
            { columnWidth: 0.22, style: 'padding-top:6px;', items: fldOnlyIssue },
            { columnWidth: 0.12, style: 'padding-top:2px;', items: btnShow }
        ]
    });

    // ============================================================
    // 3. Grid dalam tab
    // ============================================================
    var statusColor = function (s) {
        return s === 'OK' ? '#2e9e4f' : (s === 'TIDAK ADA JURNAL' ? '#e8554a' : (s === 'NILAI BEDA' ? '#e6a817' : '#999999'));
    };

    var itemGrid = new Ext.grid.GridPanel({
        store: itemDs, loadMask: true, viewConfig: { forceFit: true },
        columns: [
            { header: 'Kode', dataIndex: 'material_code', width: 100 },
            { header: 'Material', dataIndex: 'material_name', width: 260, renderer: CellWrap },
            { header: 'Satuan', dataIndex: 'unit', width: 70, align: 'center' },
            { header: 'Qty', dataIndex: 'qty', width: 110, align: 'right', renderer: fmtQty },
            { header: 'Biaya Rata-rata', dataIndex: 'avg_cost', width: 120, align: 'right', renderer: fmtAmt },
            { header: 'Nilai Stok', dataIndex: 'value', width: 140, align: 'right', renderer: fmtAmt }
        ]
    });
    var whGrid = new Ext.grid.GridPanel({
        store: whDs, loadMask: true, viewConfig: { forceFit: true },
        columns: [
            { header: 'Gudang', dataIndex: 'warehouse_name', width: 260, renderer: CellWrap },
            { header: 'Jumlah Item Bersaldo', dataIndex: 'item_count', width: 140, align: 'center' },
            { header: 'Nilai Stok', dataIndex: 'value', width: 160, align: 'right', renderer: fmtAmt }
        ]
    });
    var docGrid = new Ext.grid.GridPanel({
        store: docDs, loadMask: true, viewConfig: { forceFit: true },
        columns: [
            { header: 'Tanggal', dataIndex: 'doc_date', width: 90, align: 'center' },
            { header: 'Jenis Dokumen', dataIndex: 'reference_type', width: 140 },
            { header: 'No. Dokumen', dataIndex: 'reference_no', width: 150 },
            { header: 'Nilai Stok (+masuk / -keluar)', dataIndex: 'value', width: 170, align: 'right', renderer: fmtAmt },
            {
                header: 'Nilai Jurnal', dataIndex: 'journal_amount', width: 140, align: 'right',
                renderer: function (v) { return (v === null || v === undefined || v === '') ? '-' : fmtAmt(parseFloat(v)); }
            },
            {
                header: 'Status', dataIndex: 'status', width: 140, align: 'center',
                renderer: function (v) { return '<b style="color:' + statusColor(v) + ' !important;">' + enc(v) + '</b>'; }
            }
        ]
    });

    var tabs = new Ext.TabPanel({
        renderTo: 'divMainGrid',
        activeTab: 0,
        deferredRender: false,
        height: 300,
        defaults: { layout: 'fit' },
        items: [
            { title: 'Per Item', items: itemGrid },
            { title: 'Per Gudang', items: whGrid },
            { title: 'Dokumen vs Jurnal', items: docGrid }
        ]
    });

    // Tinggi tab = sisa tinggi layar di bawah posisinya (setelah filter bar & summary terisi)
    function fitTabs() {
        if (!tabs.rendered) return;
        var top = gridContainer.getBoundingClientRect().top;
        var h = (window.innerHeight || document.documentElement.clientHeight) - top - 24;
        tabs.setHeight(Math.max(h, 240));
        tabs.doLayout();
    }
    window.onresize = fitTabs;

    function applyDocFilter() {
        if (fldOnlyIssue.getValue()) {
            docDs.filterBy(function (r) { return r.get('status') === 'TIDAK ADA JURNAL' || r.get('status') === 'NILAI BEDA'; });
        } else {
            docDs.clearFilter();
        }
    }
    fldOnlyIssue.on('check', applyDocFilter);

    // ============================================================
    // 4. Muat rekonsiliasi
    // ============================================================
    function loadRecon() {
        if (!fldDate.getValue() || !fldAcc.getValue()) return;
        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: { task: 'reconcile', as_of: ymd(fldDate.getValue()), account_id: fldAcc.getValue() },
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { summaryDiv.innerHTML = 'Respons server tidak valid (cek error_log PHP).'; return; }
                if (!res.success) { summaryDiv.innerHTML = '<span style="color:#ffeb3b;">' + enc(res.message || 'Gagal memuat data.') + '</span>'; return; }
                var d = res.data, s = d.summary;

                itemDs.loadData({ data: d.items, totalCount: d.items.length }, false);
                whDs.loadData({ data: d.warehouses, totalCount: d.warehouses.length }, false);
                docDs.loadData({ data: d.docs, totalCount: d.docs.length }, false);
                applyDocFilter();

                var ok = (s.status === 'SESUAI');
                var cell = function (label, val, color) {
                    return '<td style="padding:4px 16px 4px 0;"><div style="color:#9fc3d1; font-size:12px;">' + label + '</div>' +
                           '<div style="font-size:18px; font-weight:bold; color:' + (color || '#ffffff') + ';">' + fmtAmt(val) + '</div></td>';
                };
                var html = '<div style="margin-bottom:6px;">Per <b>' + enc(s.as_of) + '</b> &nbsp;|&nbsp; Akun: <b>' + enc(s.account_label) + '</b> &nbsp;|&nbsp; ' +
                           '<b style="color:' + (ok ? '#4ade80' : '#f87171') + ';">' + (ok ? 'SESUAI ✓' : 'SELISIH ✗') + '</b></div>' +
                           '<table style="border-collapse:collapse;"><tr>' +
                           cell('Nilai Buku (akun persediaan)', s.book_balance) +
                           cell('Nilai Stok (stock_movements)', s.stock_value) +
                           cell('Selisih (Buku - Stok)', s.diff, ok ? '#4ade80' : '#f87171') +
                           cell('Dijelaskan dokumen bermasalah', s.explained, '#e6a817') +
                           cell('Tidak terjelaskan', s.unexplained, Math.abs(s.unexplained) <= 1 ? '#4ade80' : '#f87171') +
                           '</tr></table>';
                if (!ok) {
                    html += '<div style="margin-top:6px; color:#e6a817;">' + s.docs_problem + ' dari ' + s.docs_total +
                            ' dokumen stok bermasalah (lihat tab "Dokumen vs Jurnal").' +
                            (Math.abs(s.unexplained) > 1 ? ' Sisa selisih yang tidak terjelaskan biasanya jurnal manual / penyesuaian langsung di akun persediaan.' : '') + '</div>';
                } else {
                    html += '<div style="margin-top:6px; color:#9fc3d1;">' + s.docs_total + ' dokumen stok diperiksa.</div>';
                }
                summaryDiv.innerHTML = html;
                fitTabs();
            },
            failure: function () { summaryDiv.innerHTML = '<span style="color:#ffeb3b;">Gagal menghubungi server.</span>'; }
        });
    }

    btnShow.on('click', loadRecon);
    accDs.load();      // setelah akun terisi (default = akun kredit rule ISSUE_PROJECT), rekonsiliasi dimuat otomatis

    document.getElementById('printData').onclick = function () {
        if (!fldDate.getValue() || !fldAcc.getValue()) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih tanggal dan akun terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        window.open('../pdf/inventoryrecon.php?as_of=' + ymd(fldDate.getValue()) + '&account_id=' + fldAcc.getValue(),
                    '_blank', 'width=900,height=800,scrollbars=yes,resizable=yes');
    };
    if (document.getElementById('popOK')) {
        document.getElementById('popOK').onclick = function () { document.getElementById('popWdw').style.display = 'none'; };
    }
});