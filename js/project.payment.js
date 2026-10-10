/*
  Document      : project.payment.js
  Description   : Pembayaran Termin per Proyek. Grid utama = daftar pembayaran dari client; klik baris ->
                   panel detail di bawah grid (alokasi per termin + tombol Cetak Tanda Terima / Hapus).
                   Add membuka editor: pilih proyek -> daftar termin yang masih punya sisa tagihan ->
                   isi "Bayar Sekarang" per termin (bisa alokasi otomatis berurutan dari tanggal termin).
                   Tombol "+ Tambah Termin" membuat tagihan termin baru (project_invoices) untuk proyek terpilih.
                   Pembayaran tidak bisa diedit; koreksi = hapus (reverse) lalu input ulang.
                   Kelebihan bayar (jumlah diterima > total alokasi) disimpan di excess_amount.
  Backend       : /proyek/php-crud/project.paymentCRD.php
  Cetak         : /proyek/views/project.payment.print.php?id=<id>  (project.payment.print.js)
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Pembayaran <em>Termin</em>";

    var CRD = '../php-crud/project.paymentCRD.php';
    var PRINT_URL = '/proyek/views/project.payment.print.php?id=';
    var ACCESS_ADD = 2;      // level akses minimal: input pembayaran / termin
    var ACCESS_EDIT = 3;     // level akses minimal: hapus pembayaran
    var VEPS = 0.01;         // toleransi nilai

    var dataRecord = null;         // baris pembayaran terpilih di grid utama
    var hdrForm = null;
    var fDate, fProject, fClient, fAccount, fMethod, fRef, fTotal, fNotes, fInfo;
    var totalManual = false;       // jumlah diterima diisi manual -> jangan ditimpa total alokasi otomatis
    var termSummary = { contract_value: 0, total_termin: 0, total_paid: 0 };

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }

    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function round2(v) { return Math.round((parseFloat(v) || 0) * 100) / 100; }

    var METHOD_LABEL = { BANK_TRANSFER: 'Transfer Bank', CASH: 'Tunai', GIRO: 'Giro', CEK: 'Cek' };
    function methodLabel(v) { return METHOD_LABEL[v] || v || '-'; }

    function ajax(params, onOk, onFail) {
        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: params,
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { Ext.Msg.alert('Gagal', 'Respons server tidak valid (cek error_log PHP).'); return; }
                onOk(res);
            },
            failure: function () {
                if (onFail) onFail(); else Ext.Msg.alert('Gagal', 'Gagal menghubungi server.');
            }
        });
    }

    // Standar: setiap Store wajib punya penanganan error muat data
    function onStoreException() {
        Ext.Msg.alert('Gagal', 'Gagal memuat data dari server. Coba muat ulang halaman atau hubungi admin.');
    }

    // ------------------------------------------------------------
    // 0. Panel Detail (di bawah grid utama)
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');
    var GridMainHeight = maxGridHeight(document.getElementById('divMainGrid'));
    var DtlHeight = Math.round(GridMainHeight - (GridMainHeight * 0.6) - 30);

    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'ppDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.maxHeight = DtlHeight;
    dtlDiv.style.overflowY = 'auto';
    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(dtlDiv, gridContainer.nextSibling);
    }

    function loadDetailPanel(id) {
        ajax({ task: 'load_payment', id: id }, function (res) {
            if (!res.success) {
                dtlDiv.innerHTML = '<div style="color:yellow; padding:10px;">' + enc(res.message || 'Gagal memuat detail pembayaran.') + '</div>';
                dtlDiv.style.display = 'block';
                return;
            }
            renderDetailPanel(res.data);
        }, function () {
            dtlDiv.innerHTML = '<div style="color:red; padding:10px;">Gagal terhubung ke server.</div>';
            dtlDiv.style.display = 'block';
        });
    }

    function renderDetailPanel(d) {
        var h = d.header, items = d.items || [];
        var html = '<h3 class="detail-title">Detail Pembayaran: ' + enc(h.payment_no) + '</h3>' +
                   '<hr class="detail-hr" />' +
                   '<div style="padding:4px 2px 10px 2px;">Tanggal: <b>' + enc(h.payment_date) + '</b> &nbsp;|&nbsp; Client: <b>' + enc(h.client_name) +
                   '</b> &nbsp;|&nbsp; Metode: <b>' + enc(methodLabel(h.payment_method)) + '</b>' +
                   (h.account_name ? ' &nbsp;|&nbsp; Akun: <b>' + enc(h.account_code + ' - ' + h.account_name) + '</b>' : '') +
                   (h.reference_no ? ' &nbsp;|&nbsp; Ref: ' + enc(h.reference_no) : '') +
                   (h.notes ? ' &nbsp;|&nbsp; Catatan: ' + enc(h.notes) : '') + '</div>' +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead><tr class="header-row">' +
                   '<th align="left">Proyek</th>' +
                   '<th align="left">Termin</th>' +
                   '<th align="left">No. Invoice</th>' +
                   '<th align="right">Tagihan</th>' +
                   '<th align="right">Dibayar</th>' +
                   '<th align="right">Sisa Setelah Bayar</th>' +
                   '</tr></thead><tbody>';

        if (!items.length) {
            html += '<tr><td colspan="6" style="padding:15px; text-align:center; color:#557982;">Tidak ada alokasi termin.</td></tr>';
        }
        Ext.each(items, function (i) {
            var sisaColor = i.sisa_after <= VEPS ? '#2e9e4f' : '#e6a817';
            html += '<tr class="data-row">' +
                    '<td align="left">' + enc(i.project_name) + '</td>' +
                    '<td align="left">' + enc(i.termin_name) + (i.percentage !== null ? ' <span style="color:#668894;">(' + fmtAmt(i.percentage) + '%)</span>' : '') + '</td>' +
                    '<td align="left">' + enc(i.inv_no) + '</td>' +
                    '<td align="right">' + fmtAmt(i.invoice_amount) + '</td>' +
                    '<td align="right" class="subtotal-val">' + fmtAmt(i.amount_paid) + '</td>' +
                    '<td align="right" style="color:' + sisaColor + ';">' + fmtAmt(i.sisa_after) + '</td>' +
                    '</tr>';
        });
        html += '<tr class="data-row"><td colspan="4" align="right"><b>Total Dialokasikan</b></td>' +
                '<td align="right" class="subtotal-val"><b>' + fmtAmt(h.allocated_amount) + '</b></td><td></td></tr>';
        if (h.excess_amount > VEPS) {
            html += '<tr class="data-row"><td colspan="4" align="right"><b>Lebih Bayar</b></td>' +
                    '<td align="right" style="color:#e6a817;"><b>' + fmtAmt(h.excess_amount) + '</b></td><td></td></tr>';
        }
        html += '<tr class="data-row"><td colspan="4" align="right"><b>Total Diterima</b></td>' +
                '<td align="right" class="subtotal-val"><b>' + fmtAmt(h.total_amount) + '</b></td><td></td></tr>';
        html += '</tbody></table>';

        html += '<div style="margin-top:15px; text-align:right;">' +
                '<button type="button" id="ppBtnPrint" style="margin-left:8px; padding:8px 16px; background:#03615e; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold;">Cetak Tanda Terima</button>' +
                '</div>';

        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';
        document.getElementById('ppBtnPrint').onclick = function () { printReceipt(h.id); };
    }

    function printReceipt(id) {
        window.open(PRINT_URL + encodeURIComponent(id), '_blank', 'width=900,height=700,scrollbars=yes,resizable=yes');
    }

    // ------------------------------------------------------------
    // 1. Fields & Stores
    // ------------------------------------------------------------
    var payField = [
        { name: 'id' }, { name: 'payment_no' }, { name: 'payment_date' },
        { name: 'client_id' }, { name: 'client_name' }, { name: 'project_names' },
        { name: 'account_id' }, { name: 'account_name' }, { name: 'payment_method' },
        { name: 'total_amount', type: 'float' }, { name: 'allocated_amount', type: 'float' },
        { name: 'excess_amount', type: 'float' }, { name: 'reference_no' }, { name: 'notes' }
    ];

    var projectDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_project_options' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' },
            [{ name: 'id' }, { name: 'name' }, { name: 'client_id' }, { name: 'client_name' }, { name: 'contract_value', type: 'float' }]),
        listeners: { exception: onStoreException }
    });
    var accountDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_account_options' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'label' }]),
        listeners: { exception: onStoreException }
    });
    var methodDs = new Ext.data.ArrayStore({
        fields: ['id', 'name'],
        data: [['BANK_TRANSFER', 'Transfer Bank'], ['CASH', 'Tunai'], ['GIRO', 'Giro'], ['CEK', 'Cek']]
    });

    // Termin proyek yang masih punya sisa tagihan; kolom 'pay' = nilai bayar yang diisi user
    var outField = [
        { name: 'invoice_id' }, { name: 'inv_no' }, { name: 'termin_name' }, { name: 'invoice_date' },
        { name: 'percentage', type: 'float' }, { name: 'amount', type: 'float' },
        { name: 'paid_amount', type: 'float' }, { name: 'remaining', type: 'float' },
        { name: 'pay', type: 'float', defaultValue: 0 }
    ];
    var outDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_outstanding' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'invoice_id' }, outField),
        listeners: { exception: onStoreException }
    });

    // PENTING: assignment polos (bukan var/let) -- konvensi search box global.
    mainDs = new Ext.data.Store({
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'list_payments' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, payField),
        listeners: { exception: onStoreException }
    });
    mainDs.load();

    // ------------------------------------------------------------
    // 2. Main Grid (Daftar Pembayaran)
    // ------------------------------------------------------------
    var mainHeight = Math.round(GridMainHeight * 0.6);
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: mainHeight,
        columns: [
            { header: 'No. Pembayaran', dataIndex: 'payment_no', width: 130 },
            { header: 'Tanggal', dataIndex: 'payment_date', width: 90, align: 'center' },
            { header: 'Client', dataIndex: 'client_name', width: 180, renderer: CellWrap },
            { header: 'Proyek', dataIndex: 'project_names', width: 220, renderer: function (v) { return CellWrap(v || '-'); } },
            { header: 'Metode', dataIndex: 'payment_method', width: 100, align: 'center', renderer: function (v) { return methodLabel(v); } },
            { header: 'Akun', dataIndex: 'account_name', width: 140, renderer: function (v) { return CellWrap(v || '-'); } },
            { header: 'Total Diterima', dataIndex: 'total_amount', width: 120, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Teralokasi', dataIndex: 'allocated_amount', width: 120, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            {
                header: 'Lebih Bayar', dataIndex: 'excess_amount', width: 100, align: 'right',
                renderer: function (v) { return v > VEPS ? '<span style="color:#e6a817;font-weight:bold;">' + fmtAmt(v) + '</span>' : '-'; }
            },
            { header: 'Referensi', dataIndex: 'reference_no', width: 120, renderer: function (v) { return CellWrap(v || ''); } }
        ],
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (!rec) return;
                dataRecord = rec.data;
                updateMainButtons();
                loadDetailPanel(dataRecord.id);
            }
        }
    });

    function updateMainButtons() {
        mainBtnEnable('#addData');
        mainBtnDisable('#editData');                 // pembayaran tidak bisa diedit (hapus lalu input ulang)
        if (dataRecord) mainBtnEnable('#deleteData'); else mainBtnDisable('#deleteData');
    }
    editDataDisable();
    updateMainButtons();

    // ------------------------------------------------------------
    // 3. Editor Pembayaran: header (form) + grid termin
    // ------------------------------------------------------------
    var itemGrid = new stdGridEditor({
        store: outDs,
        renderTo: 'editorFormGrid',
        viewConfig: { forceFit: true, scrollOffset: 0 },
        height: 280,
        columns: [
            { header: 'Termin', dataIndex: 'termin_name', width: 170, renderer: CellWrap },
            { header: 'No. Invoice', dataIndex: 'inv_no', width: 110 },
            { header: 'Tgl Termin', dataIndex: 'invoice_date', width: 85, align: 'center' },
            {
                header: '%', dataIndex: 'percentage', width: 50, align: 'right',
                renderer: function (v) { return v ? fmtAmt(v) : ''; }
            },
            { header: 'Tagihan', dataIndex: 'amount', width: 110, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Terbayar', dataIndex: 'paid_amount', width: 110, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Sisa Tagihan', dataIndex: 'remaining', width: 110, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            {
                header: 'Bayar Sekarang', dataIndex: 'pay', width: 120, align: 'right',
                editor: new myNumberField({ decimalPrecision: 2 }),
                renderer: function (v) { return '<b>' + fmtAmt(v) + '</b>'; }
            }
        ],
        listeners: {
            afteredit: function (e) {
                if (e.field === 'pay') {
                    var v = round2(e.value), max = round2(e.record.get('remaining'));
                    if (v < 0) v = 0;
                    if (v > max) v = max;                       // tidak boleh melebihi sisa tagihan termin
                    e.record.set('pay', v);
                    recalcAll();
                }
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    // Tempat form header + tombol aksi + ringkasan total
    (function injectExtraUI() {
        var g = document.getElementById('editorFormGrid');
        if (g && !document.getElementById('ppHdrForm')) {
            var hdr = document.createElement('div');
            hdr.id = 'ppHdrForm';
            hdr.style.cssText = 'margin-bottom:6px;';
            g.parentNode.insertBefore(hdr, g);

            var mkBtn = function (id, label, bg, fn) {
                var b = document.createElement('button');
                b.id = id; b.type = 'button'; b.innerHTML = label;
                b.style.cssText = 'margin:0 6px 6px 0; padding:5px 12px; background:' + bg + '; color:#fff; border:none; border-radius:3px; cursor:pointer;';
                b.onclick = fn;
                g.parentNode.insertBefore(b, g);
            };
            mkBtn('btnPpAddTermin', '+ Tambah Termin', '#2e6da4', openTerminWindow);
            mkBtn('btnPpAutoAlloc', 'Alokasi Otomatis', '#03615e', autoAllocate);
            mkBtn('btnPpPayAll', 'Lunasi Semua Sisa', '#555555', payAllRemaining);

            var totalsDiv = document.createElement('div');
            totalsDiv.style.cssText = 'padding:10px 14px; margin-top:8px; font-weight:bold; font-size:14px; text-align:right; background:#1f2d3a; color:#ffffff !important; border-radius:4px;';
            totalsDiv.innerHTML = '<div>Total Dialokasikan: <span id="lblPpAlloc" style="color:#ffffff !important;">0.00</span></div>' +
                                  '<div id="lblPpExcess" style="font-size:12px; font-weight:normal; margin-top:4px;"></div>';
            g.parentNode.insertBefore(totalsDiv, g.nextSibling);
        }
    })();

    // Form header dibuat saat editor dibuka pertama kali (container hidden -> lebar kolom 0 bila dirender lebih awal)
    function ensureHdrForm() {
        if (hdrForm) { hdrForm.doLayout(); return; }

        fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal Bayar', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 130 });
        fProject = new myComboField({
            fieldLabel: 'Proyek', store: projectDs, displayField: 'name', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () { onProjectChange(); } }
        });
        fClient = new Ext.form.DisplayField({ fieldLabel: 'Client', value: '-', anchor: '95%' });
        fAccount = new myComboField({
            fieldLabel: 'Akun Kas/Bank', store: accountDs, displayField: 'label', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, anchor: '95%'
        });
        fMethod = new myComboField({
            fieldLabel: 'Metode', store: methodDs, displayField: 'name', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, value: 'BANK_TRANSFER', width: 140
        });
        fRef = new myTextField({ fieldLabel: 'No. Referensi', anchor: '95%' });
        fTotal = new myNumberField({
            fieldLabel: 'Jumlah Diterima', decimalPrecision: 2, value: 0, width: 140,
            listeners: {
                change: function () {
                    totalManual = (parseFloat(fTotal.getValue()) || 0) > 0;   // dikosongkan -> ikut total alokasi lagi
                    recalcAll();
                }
            }
        });
        fNotes = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });
        fInfo = new Ext.form.DisplayField({ hideLabel: true, value: '', anchor: '98%' });

        var col = function (w, items) {
            return { columnWidth: w, layout: 'form', border: false, labelWidth: 105, bodyStyle: 'background:transparent;', items: items };
        };
        hdrForm = new Ext.form.FormPanel({
            renderTo: 'ppHdrForm',
            border: false,
            layout: 'column',
            bodyStyle: 'background:transparent; padding:4px 2px 0 2px;',
            items: [col(0.34, [fDate, fProject, fClient]), col(0.34, [fAccount, fMethod, fRef]), col(0.32, [fTotal, fNotes])]
        });
        new Ext.form.FormPanel({
            renderTo: 'ppHdrForm',
            border: false,
            bodyStyle: 'background:transparent; padding:0 2px 4px 2px;',
            items: [fInfo]
        });
    }

    function resetHdrForm() {
        fDate.setValue(new Date());
        fProject.clearValue();
        fClient.setValue('-');
        fAccount.clearValue();
        fMethod.setValue('BANK_TRANSFER');
        fRef.setValue('');
        fNotes.setValue('');
        fTotal.setValue(0);
        totalManual = false;
        termSummary = { contract_value: 0, total_termin: 0, total_paid: 0 };
        describeContract();
    }

    // Ringkasan kontrak proyek terpilih
    function describeContract() {
        if (!fProject || !fProject.getValue()) { fInfo.setValue(''); return; }
        var cv = termSummary.contract_value, tt = termSummary.total_termin, tp = termSummary.total_paid;
        fInfo.setValue('<span style="color:#9fc3d1;">Nilai kontrak: <b>' + fmtAmt(cv) + '</b> &nbsp;|&nbsp; Total termin: <b>' + fmtAmt(tt) +
                       '</b> &nbsp;|&nbsp; Sudah dibayar: <b>' + fmtAmt(tp) + '</b> &nbsp;|&nbsp; Belum ditagihkan: <b>' + fmtAmt(Math.max(cv - tt, 0)) + '</b></span>');
    }

    // Muat termin outstanding proyek; nilai 'pay' yang sudah diisi dipertahankan bila termin masih ada
    function loadOutstanding(projectId, cb) {
        var keep = {};
        outDs.each(function (r) { keep[r.get('invoice_id')] = r.get('pay'); });
        if (!projectId) {
            outDs.removeAll();
            termSummary = { contract_value: 0, total_termin: 0, total_paid: 0 };
            describeContract();
            recalcAll();
            if (cb) cb();
            return;
        }
        outDs.load({
            params: { project_id: projectId },
            callback: function () {
                var sm = (outDs.reader.jsonData || {}).summary;
                if (sm) termSummary = { contract_value: parseFloat(sm.contract_value) || 0, total_termin: parseFloat(sm.total_termin) || 0, total_paid: parseFloat(sm.total_paid) || 0 };
                outDs.each(function (r) {
                    var p = keep[r.get('invoice_id')];
                    if (p !== undefined) r.set('pay', Math.min(round2(p), round2(r.get('remaining'))));
                });
                describeContract();
                recalcAll();
                if (cb) cb();
            }
        });
    }

    function onProjectChange() {
        var rec = projectDs.getById(fProject.getValue());
        fClient.setValue(rec && rec.get('client_name') ? enc(rec.get('client_name')) : '<span style="color:#c0392b;">Proyek belum memiliki client</span>');
        outDs.removeAll();                    // ganti proyek -> alokasi lama dibuang
        totalManual = false;
        fTotal.setValue(0);
        loadOutstanding(fProject.getValue());
    }

    // Hitung total alokasi, jumlah diterima otomatis, dan selisih (lebih bayar)
    function recalcAll() {
        var alloc = 0;
        outDs.each(function (r) { alloc += parseFloat(r.get('pay')) || 0; });
        alloc = round2(alloc);
        if (fTotal && !totalManual) fTotal.setValue(alloc);
        var total = fTotal ? (parseFloat(fTotal.getValue()) || 0) : alloc;
        var excess = round2(total - alloc);

        var el = document.getElementById('lblPpAlloc');
        if (el) el.innerHTML = fmtAmt(alloc);
        var ex = document.getElementById('lblPpExcess');
        if (ex) {
            if (excess < -VEPS) ex.innerHTML = '<span style="color:#ff6b5e;">Jumlah diterima kurang ' + fmtAmt(-excess) + ' dari total alokasi.</span>';
            else if (excess > VEPS) ex.innerHTML = '<span style="color:#e6a817;">Lebih bayar ' + fmtAmt(excess) + ' (dicatat sebagai excess, tidak dialokasikan ke termin).</span>';
            else ex.innerHTML = '';
        }
    }

    // Distribusikan Jumlah Diterima ke termin berurutan (tanggal termin terlama dulu)
    function autoAllocate() {
        var total = parseFloat(fTotal.getValue()) || 0;
        if (total <= 0) { Ext.Msg.alert('Peringatan', 'Isi Jumlah Diterima terlebih dahulu.'); return; }
        var left = total;
        outDs.each(function (r) {
            var pay = Math.min(left, round2(r.get('remaining')));
            if (pay < 0) pay = 0;
            r.set('pay', round2(pay));
            left = round2(left - pay);
        });
        totalManual = true;
        recalcAll();
    }

    function payAllRemaining() {
        outDs.each(function (r) { r.set('pay', round2(r.get('remaining'))); });
        totalManual = false;
        recalcAll();
    }

    // ---- Window Tambah Termin ----------------------------------------------
    function openTerminWindow() {
        if (getFormAccess() < ACCESS_ADD) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk membuat termin.'); return; }
        var pid = fProject.getValue();
        if (!pid) { Ext.Msg.alert('Peringatan', 'Pilih proyek terlebih dahulu.'); return; }
        var prj = projectDs.getById(pid);
        var contract = prj ? (parseFloat(prj.get('contract_value')) || 0) : 0;

        var tName = new myTextField({ fieldLabel: 'Nama Termin', allowBlank: false, anchor: '95%', emptyText: 'mis. Termin 1 - DP' });
        var tDate = new Ext.form.DateField({ fieldLabel: 'Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 130 });
        var tAmt = new myNumberField({ fieldLabel: 'Nilai Termin', decimalPrecision: 2, value: 0, width: 150 });
        var tPct = new myNumberField({
            fieldLabel: 'Persentase (%)', decimalPrecision: 2, width: 100,
            listeners: {
                change: function () {      // nilai otomatis = persentase x nilai kontrak
                    var p = parseFloat(tPct.getValue()) || 0;
                    if (contract > 0 && p > 0) tAmt.setValue(round2(contract * p / 100));
                }
            }
        });

        // REMARK (FIX): window sebelumnya layout 'fit' tanpa tinggi -> body menciut & tombol bawaan tema gelap tak terbaca.
        // Sekarang: tinggi tetap, label berwarna terang, tombol HTML biasa (gaya sama dengan tombol editor).
        var lblStyle = 'color:#9fc3d1;';
        var btnCss = 'margin-left:8px; padding:7px 18px; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold;';
        var win = new Ext.Window({
            title: 'Tambah Termin - ' + (prj ? prj.get('name') : ''),
            width: 460, height: 320, modal: true, closeAction: 'close', resizable: false,
            layout: 'fit',
            bodyStyle: 'background:#0f1d29;',
            items: [new Ext.form.FormPanel({
                border: false, labelWidth: 120, labelStyle: lblStyle, autoScroll: true,
                bodyStyle: 'background:#0f1d29; padding:14px;',
                defaults: { labelStyle: lblStyle },
                items: [
                    tName, tDate, tPct, tAmt,
                    new Ext.form.DisplayField({ hideLabel: true, value: '<span style="color:#9fc3d1;">Nilai kontrak: <b>' + fmtAmt(contract) + '</b>. ' +
                        (contract > 0 ? 'Isi persentase untuk menghitung nilai otomatis.' : 'Nilai kontrak proyek masih 0, isi nilai termin manual.') + '</span>' }),
                    {
                        xtype: 'panel', border: false, bodyStyle: 'background:transparent; text-align:right; padding-top:10px;',
                        html: '<button type="button" id="ppTerminSave" style="' + btnCss + 'background:#03615e;">Simpan</button>' +
                              '<button type="button" id="ppTerminCancel" style="' + btnCss + 'background:#555555;">Batal</button>',
                        listeners: {
                            afterrender: function () {
                                document.getElementById('ppTerminCancel').onclick = function () { win.close(); };
                                document.getElementById('ppTerminSave').onclick = function () {
                                    if (!tName.getValue()) { Ext.Msg.alert('Peringatan', 'Nama termin wajib diisi.'); return; }
                                    if (!tDate.getValue()) { Ext.Msg.alert('Peringatan', 'Tanggal termin wajib diisi.'); return; }
                                    if (!((parseFloat(tAmt.getValue()) || 0) > 0)) { Ext.Msg.alert('Peringatan', 'Nilai termin harus lebih dari 0.'); return; }
                                    ajax({
                                        task: 'save_termin',
                                        project_id: pid,
                                        termin_name: tName.getValue(),
                                        invoice_date: tDate.getValue().format('Y-m-d'),
                                        percentage: (parseFloat(tPct.getValue()) || 0) > 0 ? tPct.getValue() : '',
                                        amount: tAmt.getValue()
                                    }, function (res) {
                                        Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                                        if (res.success) { win.close(); loadOutstanding(pid); }
                                    });
                                };
                            }
                        }
                    }
                ]
            })]
        });
        win.show();
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
    // ADD = pembayaran baru
    document.getElementById('addData').onclick = function () {
        if (getFormAccess() < ACCESS_ADD) {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk input pembayaran.');
            return;
        }
        showEditor('Pembayaran Termin Baru');
        resetHdrForm();
        outDs.removeAll();
        recalcAll();
    };

    // SAVE
    document.getElementById('saveEdit').onclick = function () {
        if (!fProject || !fProject.getValue()) { Ext.Msg.alert('Peringatan', 'Proyek wajib dipilih.'); return; }
        if (!fDate.getValue()) { Ext.Msg.alert('Peringatan', 'Tanggal pembayaran wajib diisi.'); return; }
        var prj = projectDs.getById(fProject.getValue());
        if (!prj || !prj.get('client_id')) { Ext.Msg.alert('Peringatan', 'Proyek ini belum memiliki client. Lengkapi data proyek terlebih dahulu.'); return; }

        var arr = [], alloc = 0;
        outDs.each(function (r) {
            var pay = round2(r.get('pay'));
            if (pay <= 0) return;                                  // termin tanpa pembayaran diabaikan
            if (pay > round2(r.get('remaining')) + VEPS) { return; }
            arr.push({ invoice_id: r.get('invoice_id'), amount_paid: pay });
            alloc += pay;
        });
        alloc = round2(alloc);
        if (!arr.length) { Ext.Msg.alert('Peringatan', 'Isi "Bayar Sekarang" pada minimal satu termin.'); return; }

        var total = round2(fTotal.getValue());
        if (total <= 0) total = alloc;
        if (total + VEPS < alloc) { Ext.Msg.alert('Peringatan', 'Jumlah diterima lebih kecil dari total alokasi termin.'); return; }
        var excess = round2(total - alloc);

        var doSave = function () {
            ajax({
                task: 'save_payment',
                project_id: fProject.getValue(),
                payment_date: fDate.getValue().format('Y-m-d'),
                account_id: fAccount.getValue() || 0,
                payment_method: fMethod.getValue() || 'BANK_TRANSFER',
                total_amount: total,
                reference_no: fRef.getValue(),
                notes: fNotes.getValue(),
                items: Ext.util.JSON.encode(arr)
            }, function (res) {
                if (res.success) {
                    document.getElementById('editorWdw').style.display = 'none';
                    mainDs.load();
                    var newId = res.data && res.data.id;
                    if (newId) {
                        dataRecord = { id: newId };
                        updateMainButtons();
                        loadDetailPanel(newId);
                        Ext.Msg.confirm('Sukses', (res.message || 'Pembayaran berhasil disimpan.') + '<br/><br/>Cetak tanda terima sekarang?', function (btn) {
                            if (btn === 'yes') printReceipt(newId);
                        });
                    } else {
                        Ext.Msg.alert('Sukses', res.message || 'Pembayaran berhasil disimpan.');
                    }
                } else {
                    Ext.Msg.alert('Gagal', res.message || 'Terjadi kesalahan sistem.');
                    loadOutstanding(fProject.getValue());     // sisa tagihan mungkin berubah -> muat ulang
                }
            });
        };

        if (excess > VEPS) {
            Ext.Msg.confirm('Konfirmasi Lebih Bayar',
                'Jumlah diterima melebihi total alokasi sebesar <b>' + fmtAmt(excess) + '</b>. Selisih dicatat sebagai lebih bayar. Lanjutkan simpan?',
                function (btn) { if (btn === 'yes') doSave(); });
        } else {
            doSave();
        }
    };

    // DELETE = hapus (reverse) pembayaran
    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih pembayaran terlebih dahulu dari tabel.'); return; }
        if (getFormAccess() < ACCESS_EDIT) {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk menghapus pembayaran.');
            return;
        }
        Ext.Msg.confirm('Hapus Pembayaran',
            'Hapus pembayaran <b>' + enc(dataRecord.payment_no || '') + '</b>? Saldo termin akan dikembalikan. Tindakan ini tidak dapat dibatalkan.',
            function (btn) {
                if (btn !== 'yes') return;
                ajax({ task: 'delete_payment', id: dataRecord.id }, function (res) {
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

    document.getElementById('editorWdw').style.display = 'none';

    // Combo harus terisi sebelum editor dibuka
    projectDs.load();
    accountDs.load();
});