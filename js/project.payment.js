/*
  Document      : project.payment.js
  Description   : Pembayaran Termin per CLIENT (multi proyek). Grid utama = daftar pembayaran dari client; klik baris ->
                   panel detail di bawah grid (alokasi per termin + tombol Cetak Tanda Terima / Hapus).
                   Add membuka editor: pilih CLIENT -> daftar termin yang masih punya sisa tagihan dari SEMUA proyek client tsb
                   -> isi "Bayar Sekarang" per termin (bisa alokasi otomatis berurutan dari tanggal termin, lintas proyek).
                   Tombol "+ Tambah Termin" membuat tagihan termin baru (project_invoices) untuk salah satu proyek client terpilih.
                   Pembayaran tidak bisa diedit; koreksi = hapus (reverse) lalu input ulang.
                   Kelebihan bayar (jumlah diterima > total alokasi) disimpan di excess_amount.
                   [MULTI-PROYEK] satu pembayaran boleh mengalokasikan ke termin beberapa proyek, selama client-nya sama.
                   [MULTI-CURRENCY] pembayaran punya mata uang + kurs (default IDR, kurs KMK pada tanggal bayar). Jumlah Diterima & Bayar Sekarang
                   diisi dalam mata uang pembayaran; tagihan termin tetap IDR, nilai setara IDR = nilai x kurs (dibatasi sisa tagihan).
                   Butuh migrasi: sql/2026-10-15.project_payment_currency.sql
                   [JURNAL] simpan pembayaran memposting jurnal otomatis (Kas/Bank wajib dipilih); hapus pembayaran menghapus jurnalnya.
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
    // [RULE-TERMIN] aturan minimal pembayaran per urutan termin (persen dari NILAI KONTRAK proyek)
    var TERMIN_RULE_PCT = { 1: 30, 2: 20 };   // termin ke-1 = 30%, termin ke-2 = 20%; termin ke-3 dst belum ada aturan -> hanya porsi proporsional
    var DP_PCT = 30;                          // proyek belum pernah dibayar -> alokasikan sebagai DP 30% dari kontrak
    var allocNote = '';                       // [RULE-TERMIN] keterangan hasil alokasi otomatis (ditampilkan di bawah total)

    var dataRecord = null;         // baris pembayaran terpilih di grid utama
    var hdrForm = null;
    var fDate, fClient, fAccount, fMethod, fRef, fTotal, fNotes, fInfo;     // [MULTI-PROYEK] fProject dihapus; fClient = combo client
    var fCurrency, fRate, fRateInfo;       // [MULTI-CURRENCY]
    var rateManual = false;                // [MULTI-CURRENCY] kurs diubah manual -> jangan ditimpa kurs otomatis
    var rateReq = 0;                       // [MULTI-CURRENCY] penanda request kurs terbaru (abaikan respons usang)
    var totalManual = false;       // jumlah diterima diisi manual -> jangan ditimpa total alokasi otomatis
    var autoMode = true;           // [AUTO-ALLOC] default TRUE: Jumlah Diterima diisi -> alokasi dibagi otomatis ke proyek (porsi = nilai kontrak). Edit manual 'Bayar Sekarang' / Lunasi Semua Sisa mematikannya
    var projSummary = [];          // [MULTI-PROYEK] ringkasan kontrak per proyek milik client terpilih

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

    // [FIX] baca nilai field angka; fallback ke raw value (buang pemisah ribuan) bila getValue() tidak numerik
    function numVal(f) {
        if (!f) return 0;
        var v = parseFloat(f.getValue());
        if (isNaN(v)) v = parseFloat(String(f.getRawValue() || '').replace(/,/g, ''));
        return isNaN(v) ? 0 : v;
    }

    // ---- [MULTI-CURRENCY] helper mata uang & kurs -------------------------
    function fmtRate(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    function currencyCode(id) { var r = id ? currencyDs.getById(id) : null; return r ? r.get('init') : 'IDR'; }
    function baseCurrencyId() {
        var id = null;
        currencyDs.each(function (r) { if (id === null && r.get('is_base') === true) id = r.get('id'); });
        return id;
    }
    function curId() { return Number((fCurrency && fCurrency.getValue()) || baseCurrencyId()) || null; }
    function isBaseCur(id) { var r = id ? currencyDs.getById(id) : null; return !r || r.get('is_base') === true; }
    function curCode() { return currencyCode(curId()); }
    function curRate() { return isBaseCur(curId()) ? 1 : (parseFloat(fRate.getValue()) || 0); }
    function rateTol() { return 0.005 * curRate() + VEPS; }      // toleransi pembulatan 2 desimal mata uang asing -> IDR
    // Setara IDR dari nilai bayar; di-snap ke sisa tagihan bila selisihnya hanya akibat pembulatan
    function toIdr(pay, remaining) {
        var idr = round2(pay * curRate());
        if (Math.abs(idr - remaining) <= rateTol()) idr = round2(remaining);
        return idr;
    }
    // Nilai bayar maksimum (mata uang pembayaran) untuk sisa tagihan IDR tertentu
    function maxPay(remaining) { var r = curRate(); return r > 0 ? round2(remaining / r) : 0; }

    // ------------------------------------------------------------
    // 0. Panel Detail (di bawah grid utama)
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');
    var GridMainHeight = maxGridHeight(document.getElementById('divMainGrid'));
    var DtlHeight = Math.round(GridMainHeight - (GridMainHeight * 0.6) - 30);

    // [FIX] header kolom grid boleh multi-baris (sebelumnya terpotong '...'): grid utama & grid termin di editor
    (function injectHeaderCss() {
        var st = document.createElement('style');
        st.type = 'text/css';
        st.appendChild(document.createTextNode(
            // [FIX] tinggi semua sel header SAMA (muat 2 baris) & teks di tengah vertikal -> garis header rata walau ada header 1 / 2 baris.
            //       flex column: rata vertikal tengah, text-align bawaan Ext (kiri/kanan/tengah) tetap berlaku.
            '.ppMainGrid .x-grid3-hd-inner, #editorFormGrid .x-grid3-hd-inner {' +
            ' white-space:normal !important; overflow:hidden !important; text-overflow:clip !important;' +
            ' line-height:14px !important; height:36px !important; padding:0 6px !important; box-sizing:border-box;' +
            ' display:flex !important; flex-direction:column; justify-content:center; }' +
            '.ppMainGrid .x-grid3-hd, #editorFormGrid .x-grid3-hd { padding:0 !important; vertical-align:top; }'));
        document.getElementsByTagName('head')[0].appendChild(st);
    })();

    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'ppDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.maxHeight = DtlHeight + 'px';       // [FIX] sebelumnya angka tanpa 'px' -> diabaikan browser, panel detail tidak bisa di-scroll
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
        var cur = h.currency_code || 'IDR', foreign = (cur !== 'IDR'), allocCur = 0;     // [MULTI-CURRENCY]
        var html = '<h3 class="detail-title">Detail Pembayaran: ' + enc(h.payment_no) + '</h3>' +
                   '<hr class="detail-hr" />' +
                   '<div style="padding:4px 2px 10px 2px;">Tanggal: <b>' + enc(h.payment_date) + '</b> &nbsp;|&nbsp; Client: <b>' + enc(h.client_name) +
                   '</b> &nbsp;|&nbsp; Metode: <b>' + enc(methodLabel(h.payment_method)) + '</b>' +
                   ' &nbsp;|&nbsp; Mata uang: <b>' + enc(cur) + '</b>' + (foreign ? ' (kurs ' + fmtRate(h.exch_rate) + ')' : '') +
                   (h.account_name ? ' &nbsp;|&nbsp; Akun: <b>' + enc(h.account_code + ' - ' + h.account_name) + '</b>' : '') +
                   (h.reference_no ? ' &nbsp;|&nbsp; Ref: ' + enc(h.reference_no) : '') +
                   (h.notes ? ' &nbsp;|&nbsp; Catatan: ' + enc(h.notes) : '') + '</div>' +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead><tr class="header-row">' +
                   '<th align="left">Proyek</th>' +
                   '<th align="left">Termin</th>' +
                   '<th align="left">No. Invoice</th>' +
                   '<th align="right">Tagihan</th>' +
                   '<th align="right">Dibayar (IDR)</th>' +
                   '<th align="right">Sisa Setelah Bayar</th>' +
                   (foreign ? '<th align="right">Dibayar (' + enc(cur) + ')</th>' : '') +
                   '</tr></thead><tbody>';

        if (!items.length) {
            html += '<tr><td colspan="6" style="padding:15px; text-align:center; color:#557982;">Tidak ada alokasi termin.</td></tr>';
        }
        Ext.each(items, function (i) {
            var sisaColor = i.sisa_after <= VEPS ? '#2e9e4f' : '#e6a817';
            allocCur += parseFloat(i.amount_cur) || 0;
            html += '<tr class="data-row">' +
                    '<td align="left">' + enc(i.project_name) + '</td>' +
                    '<td align="left">' + enc(i.termin_name) + (i.percentage !== null ? ' <span style="color:#668894;">(' + fmtAmt(i.percentage) + '%)</span>' : '') + '</td>' +
                    '<td align="left">' + enc(i.inv_no) + '</td>' +
                    '<td align="right">' + fmtAmt(i.invoice_amount) + '</td>' +
                    '<td align="right" class="subtotal-val">' + fmtAmt(i.amount_paid) + '</td>' +
                    '<td align="right" style="color:' + sisaColor + ';">' + fmtAmt(i.sisa_after) + '</td>' +
                    (foreign ? '<td align="right">' + fmtAmt(i.amount_cur) + '</td>' : '') +
                    '</tr>';
        });
        html += '<tr class="data-row"><td colspan="4" align="right"><b>Total Dialokasikan</b></td>' +
                '<td align="right" class="subtotal-val"><b>' + fmtAmt(h.allocated_amount) + '</b></td><td></td>' + (foreign ? '<td align="right"><b>' + fmtAmt(allocCur) + '</b></td>' : '') + '</tr>';
        if (h.excess_amount > VEPS) {
            html += '<tr class="data-row"><td colspan="4" align="right"><b>Lebih Bayar</b></td>' +
                    '<td align="right" style="color:#e6a817;"><b>' + fmtAmt(h.excess_amount) + '</b></td><td></td>' + (foreign ? '<td align="right" style="color:#e6a817;"><b>' + fmtAmt(round2(h.total_amount_cur - allocCur)) + '</b></td>' : '') + '</tr>';
        }
        html += '<tr class="data-row"><td colspan="4" align="right"><b>Total Diterima</b></td>' +
                '<td align="right" class="subtotal-val"><b>' + fmtAmt(h.total_amount) + '</b></td><td></td>' + (foreign ? '<td align="right"><b>' + fmtAmt(h.total_amount_cur) + '</b></td>' : '') + '</tr>';
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
        { name: 'excess_amount', type: 'float' }, { name: 'reference_no' }, { name: 'notes' },
        { name: 'currency_code' }, { name: 'exch_rate', type: 'float' }, { name: 'total_amount_cur', type: 'float' }     // [MULTI-CURRENCY]
    ];

    var projectDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_project_options' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' },
            [{ name: 'id' }, { name: 'name' }, { name: 'client_id' }, { name: 'client_name' }, { name: 'contract_value', type: 'float' }]),
        listeners: { exception: onStoreException }
    });
    // [MULTI-PROYEK] daftar client yang punya proyek
    var clientDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_client_options' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'name' }]),
        listeners: { exception: onStoreException }
    });
    // [MULTI-CURRENCY] daftar mata uang (is_base = IDR)
    var currencyDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_currencies' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'init' }, { name: 'name' }, { name: 'is_base' }]),
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
        { name: 'invoice_id' }, { name: 'project_id' }, { name: 'project_name' },     // [MULTI-PROYEK] proyek asal termin
        { name: 'inv_no' }, { name: 'termin_name' }, { name: 'invoice_date' },
        { name: 'termin_no', type: 'int' },                                           // [RULE-TERMIN] urutan termin dalam proyek (dari backend)
        { name: 'percentage', type: 'float' }, { name: 'amount', type: 'float' },
        { name: 'paid_amount', type: 'float' }, { name: 'remaining', type: 'float' },
        { name: 'pay', type: 'float', defaultValue: 0 },            // nilai bayar dalam MATA UANG PEMBAYARAN
        { name: 'pay_idr', type: 'float', defaultValue: 0 }         // [MULTI-CURRENCY] setara IDR (dibandingkan dengan sisa tagihan)
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
        cls: 'ppMainGrid',                      // [FIX] target CSS header multi-baris
        height: mainHeight,
        columns: [
            { header: 'No. Pembayaran', dataIndex: 'payment_no', width: 130 },
            { header: 'Tanggal', dataIndex: 'payment_date', width: 90, align: 'center' },
            { header: 'Client', dataIndex: 'client_name', width: 180, renderer: CellWrap },
            { header: 'Proyek', dataIndex: 'project_names', width: 220, renderer: function (v) { return CellWrap(v || '-'); } },
            { header: 'Metode', dataIndex: 'payment_method', width: 100, align: 'center', renderer: function (v) { return methodLabel(v); } },
            { header: 'Akun', dataIndex: 'account_name', width: 140, renderer: function (v) { return CellWrap(v || '-'); } },
            { header: 'Mata Uang', dataIndex: 'currency_code', width: 70, align: 'center', renderer: function (v) { return v || 'IDR'; } },     // [MULTI-CURRENCY]
            { header: 'Jumlah Diterima', dataIndex: 'total_amount_cur', width: 120, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Total Diterima (IDR)', dataIndex: 'total_amount', width: 130, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Teralokasi (IDR)', dataIndex: 'allocated_amount', width: 120, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            {
                header: 'Lebih Bayar (IDR)', dataIndex: 'excess_amount', width: 100, align: 'right',
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

    setTimeout(function () { try { gridMain.getView().layout(); } catch (e) {} }, 100);     // [FIX] hitung ulang area scroll setelah header multi-baris

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
            { header: 'Proyek', dataIndex: 'project_name', width: 170, renderer: CellWrap },      // [MULTI-PROYEK] kolom baru
            { header: 'Termin', dataIndex: 'termin_name', width: 150, renderer: CellWrap },
            { header: 'No. Invoice', dataIndex: 'inv_no', width: 110 },
            { header: 'Tgl Termin', dataIndex: 'invoice_date', width: 85, align: 'center' },
            {
                header: '%', dataIndex: 'percentage', width: 50, align: 'right',
                renderer: function (v) { return v ? fmtAmt(v) : ''; }
            },
            { header: 'Tagihan (IDR)', dataIndex: 'amount', width: 110, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Terbayar (IDR)', dataIndex: 'paid_amount', width: 110, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Sisa Tagihan (IDR)', dataIndex: 'remaining', width: 110, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            {
                header: 'Bayar Sekarang', dataIndex: 'pay', width: 120, align: 'right',
                editor: new myNumberField({ decimalPrecision: 2 }),
                renderer: function (v) { return '<b>' + fmtAmt(v) + '</b>'; }
            },
            {   // [MULTI-CURRENCY] setara IDR dari Bayar Sekarang x kurs
                header: 'Setara IDR', dataIndex: 'pay_idr', width: 110, align: 'right',
                renderer: function (v) { return '<span style="color:#9fc3d1;">' + fmtAmt(v) + '</span>'; }
            },
            {   // [NEW] tombol hapus termin (salah entry)
                id: 'ppDeleteCol', dataIndex: 'invoice_id', width: 32, align: 'center', fixed: true,
                // [NEW] ikon tempat sampah di header kolom hapus termin
                header: '<span title="Hapus termin" style="display:inline-block;line-height:0;">' +
                        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#e05353" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                        '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>' +
                        '<path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></span>',
                renderer: function () {
                    return '<span style="color:#e05353;cursor:pointer;font-weight:bold;font-size:14px;" title="Hapus termin ini">&#10005;</span>';
                }
            }
        ],
        listeners: {
            afteredit: function (e) {
                if (e.field === 'pay') {
                    autoMode = false; allocNote = '';                                       // [FIX] diedit manual -> keluar dari mode alokasi otomatis
                    var v = round2(e.value), max = maxPay(round2(e.record.get('remaining')));     // [MULTI-CURRENCY] batas dalam mata uang pembayaran
                    if (v < 0) v = 0;
                    if (v > max) v = max;                       // tidak boleh melebihi sisa tagihan termin (setara IDR)
                    e.record.set('pay', v);
                    recalcAll();
                }
            },
            // [NEW] klik kolom ✕ -> hapus termin yang salah entry (hanya yang belum ada pembayaran)
            cellclick: function (grid, rowIndex, columnIndex) {
                if (grid.getColumnModel().getColumnId(columnIndex) !== 'ppDeleteCol') return;
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) deleteTermin(rec);
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
            totalsDiv.style.cssText = 'padding:10px 14px; margin-top:8px; font-weight:bold; font-size:14px; font-family:arial; text-align:right; background:#1f2d3a; color:#ffffff !important; border-radius:4px;';
            totalsDiv.innerHTML = '<div>Total Dialokasikan: <span id="lblPpAlloc" style="color:#ffffff !important;">0.00</span></div>' +
                                  '<div id="lblPpSplit" style="font-size:14px; font-family:arial; line-height:22px; font-weight:normal; margin-top:4px; color:#c8dde6;"></div>' +
                                  '<div id="lblPpExcess" style="font-size:14px; font-family:arial; line-height:22px; font-weight:normal; margin-top:4px;"></div>';     // [FIX] font = ringkasan kontrak di atas
            g.parentNode.insertBefore(totalsDiv, g.nextSibling);
        }
    })();

    // [FIX] tinggi grid termin = sisa ruang di editor (agar semua baris termin + total terlihat); editor tetap bisa di-scroll bila masih kurang
    function fitGrid() {
        var ef = document.getElementById('editorForm'), ld = document.getElementById('lblPpAlloc');
        if (!ef || !ld || !itemGrid.rendered || ef.offsetHeight === 0) return;
        var tot = ld.parentNode.parentNode;
        var avail = ef.getBoundingClientRect().bottom - itemGrid.getEl().dom.getBoundingClientRect().top - tot.offsetHeight - 24;
        itemGrid.setHeight(Math.max(120, Math.floor(avail)));
    }
    Ext.EventManager.onWindowResize(fitGrid);

    // Form header dibuat saat editor dibuka pertama kali (container hidden -> lebar kolom 0 bila dirender lebih awal)
    function ensureHdrForm() {
        if (hdrForm) { hdrForm.doLayout(); return; }

        fDate = new Ext.form.DateField({
            fieldLabel: 'Tanggal Bayar', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 130,
            listeners: { select: function () { onDateChange(); }, change: function () { onDateChange(); } }     // [MULTI-CURRENCY] kurs ikut tanggal bayar
        });
        // [MULTI-PROYEK] pilih CLIENT; termin outstanding dari semua proyek client dimuat ke grid
        fClient = new myComboField({
            fieldLabel: 'Client', store: clientDs, displayField: 'name', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () { onClientChange(); } }
        });
        // [MULTI-CURRENCY] mata uang pembayaran (default IDR) + kurs
        fCurrency = new myComboField({
            fieldLabel: 'Mata Uang', store: currencyDs, displayField: 'init', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, width: 100,
            listeners: { select: function () { onCurrencyChange(); } }
        });
        fRate = new myNumberField({
            fieldLabel: 'Kurs', decimalPrecision: 4, value: 1, width: 110, readOnly: true,
            listeners: { change: function () { rateManual = true; recalcAll(); } }      // diubah user -> jangan ditimpa lagi
        });
        fRateInfo = new Ext.form.DisplayField({ fieldLabel: 'Sumber Kurs', value: '-', anchor: '98%' });
        fAccount = new myComboField({
            fieldLabel: 'Akun Kas/Bank', store: accountDs, displayField: 'label', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%'     // [JURNAL] wajib
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
                    totalManual = numVal(fTotal) > 0;   // dikosongkan -> ikut total alokasi lagi
                    if (autoMode) {                                                    // [AUTO-ALLOC] alokasi otomatis tiap Jumlah Diterima berubah
                        if (totalManual && curRate() > 0) { autoAllocate(true); return; }
                        if (!totalManual) outDs.each(function (r) { r.set('pay', 0); r.set('pay_idr', 0); });   // total dikosongkan -> alokasi dibersihkan
                    }
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
            items: [col(0.34, [fDate, fClient, fCurrency]), col(0.34, [fAccount, fMethod, fRef]), col(0.32, [fTotal, fRate, fNotes])]     // [MULTI-PROYEK][MULTI-CURRENCY]
        });
        new Ext.form.FormPanel({
            renderTo: 'ppHdrForm',
            border: false,
            bodyStyle: 'background:transparent; padding:0 2px 4px 2px;',
            items: [fRateInfo, fInfo]      // [MULTI-CURRENCY] sumber kurs di atas ringkasan kontrak
        });
    }

    function resetHdrForm() {
        fDate.setValue(new Date());
        fClient.clearValue();                                   // [MULTI-PROYEK] client, bukan proyek
        var baseCur = baseCurrencyId();                         // [MULTI-CURRENCY] default IDR
        if (baseCur !== null) fCurrency.setValue(baseCur); else fCurrency.clearValue();
        fRate.setValue(1);
        fRate.setReadOnly(true);
        rateManual = false;
        fRateInfo.setValue('<span style="color:#668894;">Mata uang dasar (IDR, kurs 1)</span>');
        applyCurrencyLabels();
        fAccount.clearValue();
        fMethod.setValue('BANK_TRANSFER');
        fRef.setValue('');
        fNotes.setValue('');
        fTotal.setValue(0);
        totalManual = false;
        autoMode = true;                // [AUTO-ALLOC]
        allocNote = '';
        projSummary = [];
        describeContract();
    }

    // [MULTI-PROYEK] Ringkasan kontrak: total semua proyek client + rincian per proyek (bila > 1 proyek)
    // REMARK (FIX): font & line-height dipaksa inline (tema form membuat teks DisplayField kecil & rapat); tiap proyek = 1 baris (div)
    function describeContract() {
        if (!fClient || !fClient.getValue()) { fInfo.setValue(''); return; }
        var cv = 0, tt = 0, tp = 0, bt = 0, lines = '';
        Ext.each(projSummary, function (p) {
            var bel = Math.max(p.contract_value - p.total_termin, 0);
            cv += p.contract_value; tt += p.total_termin; tp += p.total_paid; bt += bel;
            lines += '<div style="font-size:14px; font-family: arial;line-height:22px; color:#c8dde6;">&bull; <b>' + enc(p.project_name) + '</b>' +
                     ' &nbsp;&ndash;&nbsp; kontrak <b>' + fmtAmt(p.contract_value) + '</b>' +
                     ' &nbsp;|&nbsp; termin <b>' + fmtAmt(p.total_termin) + '</b>' +
                     ' &nbsp;|&nbsp; dibayar <b>' + fmtAmt(p.total_paid) + '</b>' +
                     ' &nbsp;|&nbsp; belum ditagihkan <b>' + fmtAmt(bel) + '</b></div>';
        });
        var multi = projSummary.length > 1;
        fInfo.setValue('<div style="padding:6px 2px 2px 2px;">' +
                       '<div style="font-size:14px; line-height:24px; color:#ffffff;">' + (multi ? ('Total ' + projSummary.length + ' proyek &nbsp;&ndash;&nbsp; ') : '') +
                       'Nilai kontrak: <b>' + fmtAmt(cv) + '</b> &nbsp;|&nbsp; Total termin: <b>' + fmtAmt(tt) +
                       '</b> &nbsp;|&nbsp; Sudah dibayar: <b>' + fmtAmt(tp) + '</b> &nbsp;|&nbsp; Belum ditagihkan: <b>' + fmtAmt(bt) + '</b></div>' +
                       (multi ? ('<div style="margin-top:4px;">' + lines + '</div>') : '') + '</div>');
        setTimeout(fitGrid, 0);                                                          // [FIX] tinggi ringkasan kontrak berubah
    }

    // [MULTI-PROYEK] Muat termin outstanding SEMUA proyek milik client; nilai 'pay' yang sudah diisi dipertahankan bila termin masih ada
    function loadOutstanding(clientId, cb) {
        var keep = {};
        outDs.each(function (r) { keep[r.get('invoice_id')] = r.get('pay'); });
        if (!clientId) {
            outDs.removeAll();
            projSummary = [];
            describeContract();
            recalcAll();
            if (cb) cb();
            return;
        }
        outDs.load({
            params: { client_id: clientId },
            callback: function () {
                var sm = (outDs.reader.jsonData || {}).summary;
                projSummary = [];
                if (sm && sm.projects) {
                    Ext.each(sm.projects, function (p) {
                        projSummary.push({
                            project_id: parseInt(p.project_id, 10), project_name: p.project_name,
                            contract_value: parseFloat(p.contract_value) || 0,
                            total_termin: parseFloat(p.total_termin) || 0,
                            total_paid: parseFloat(p.total_paid) || 0
                        });
                    });
                }
                outDs.each(function (r) {
                    var p = keep[r.get('invoice_id')];
                    if (p !== undefined) r.set('pay', Math.min(round2(p), round2(r.get('remaining'))));
                });
                describeContract();
                if (autoMode && numVal(fTotal) > 0 && curRate() > 0) { totalManual = true; autoAllocate(true); }     // [AUTO-ALLOC] termin (re)load -> bagi ulang
                else recalcAll();
                if (cb) cb();
            }
        });
    }

    // [MULTI-PROYEK] ganti client -> alokasi lama dibuang, termin client baru dimuat
    function onClientChange() {
        outDs.removeAll();
        totalManual = false;
        autoMode = true;                // [AUTO-ALLOC]
        allocNote = '';
        fTotal.setValue(0);
        loadOutstanding(fClient.getValue());
    }

    // ---- [MULTI-CURRENCY] mata uang & kurs --------------------------------
    function applyCurrencyLabels() {
        var code = curCode();
        if (fTotal && fTotal.label) fTotal.label.update('Jumlah Diterima (' + code + ')');
        itemGrid.getColumnModel().setColumnHeader(8, 'Bayar Sekarang (' + code + ')');
    }

    // Kurs default = KMK pada tanggal bayar; belum ada -> kurs terakhir. Tetap bisa diedit.
    function refreshRate() {
        var cid = curId(), dt = fDate.getValue();
        if (!cid || !dt) return;
        if (isBaseCur(cid)) {
            fRate.setValue(1);
            fRate.setReadOnly(true);
            fRateInfo.setValue('<span style="color:#668894;">Mata uang dasar (IDR, kurs 1)</span>');
            recalcAll();
            return;
        }
        fRate.setReadOnly(false);
        var token = ++rateReq;
        fRateInfo.setValue('<span style="color:#668894;">memuat kurs...</span>');
        ajax({ task: 'get_exchange_rate', currency_id: cid, date: dt.dateFormat('Y-m-d') }, function (res) {
            if (token !== rateReq) return;                       // sudah ada permintaan yang lebih baru
            if (!res.success) {
                fRateInfo.setValue('<span style="color:#c0392b;">' + enc(res.message || 'Gagal memuat kurs.') + '</span>');
                return;
            }
            var d = res.data || {};
            if (d.rate) fRate.setValue(d.rate);
            if (d.source === 'periode') {
                fRateInfo.setValue('<span style="color:#2e9e4f;">KMK ' + enc(d.kmk_no) + ' (' + enc(d.start_date) + ' - ' + enc(d.end_date) + ')</span>');
            } else if (d.source === 'terakhir') {
                fRateInfo.setValue('<span style="color:#e6a817;">Belum ada kurs untuk tanggal bayar, memakai kurs terakhir: KMK ' +
                                   enc(d.kmk_no) + ' (' + enc(d.start_date) + ' - ' + enc(d.end_date) + ')</span>');
            } else {
                fRateInfo.setValue('<span style="color:#e6a817;">Data kurs mata uang ini belum tersedia, isi manual.</span>');
            }
            recalcAll();
        });
    }

    function onDateChange() { if (!rateManual) refreshRate(); }

    // Ganti mata uang: nilai bayar lama (mata uang berbeda) dikosongkan agar tidak salah satuan
    function onCurrencyChange() {
        rateManual = false;
        outDs.each(function (r) { r.set('pay', 0); r.set('pay_idr', 0); });
        totalManual = false;
        autoMode = true;                // [AUTO-ALLOC]
        allocNote = '';
        fTotal.setValue(0);
        applyCurrencyLabels();
        refreshRate();
    }

    // Hitung total alokasi, setara IDR, jumlah diterima otomatis, dan selisih (lebih bayar)
    function recalcAll() {
        var code = curCode(), foreign = !isBaseCur(curId()), rate = curRate();
        var alloc = 0, allocIdr = 0;
        outDs.each(function (r) {
            var rem = round2(r.get('remaining')), pay = round2(r.get('pay'));
            var mx = maxPay(rem);
            if (pay > mx) { pay = mx; r.set('pay', pay); }                 // kurs berubah -> batas bayar ikut berubah
            var idr = pay > 0 ? toIdr(pay, rem) : 0;
            if (round2(r.get('pay_idr')) !== idr) r.set('pay_idr', idr);
            alloc += pay;
            allocIdr += idr;
        });
        alloc = round2(alloc);
        allocIdr = round2(allocIdr);
        if (fTotal && !totalManual) fTotal.setValue(alloc);
        var total = fTotal ? (parseFloat(fTotal.getValue()) || 0) : alloc;
        var excess = round2(total - alloc);

        var el = document.getElementById('lblPpAlloc');
        if (el) {
            el.innerHTML = fmtAmt(alloc) + ' ' + enc(code) +
                           (foreign ? ' <span style="color:#9fc3d1; font-weight:normal;">&asymp; ' + fmtAmt(allocIdr) + ' IDR</span>' : '');
        }
        var ex = document.getElementById('lblPpExcess');
        if (ex) {
            if (foreign && !(rate > 0)) ex.innerHTML = '<span style="color:#e6a817;">Isi kurs ' + enc(code) + ' agar nilai setara IDR dapat dihitung.</span>';
            else if (excess < -VEPS) ex.innerHTML = '<span style="color:#ff6b5e;">Jumlah diterima kurang ' + fmtAmt(-excess) + ' ' + enc(code) + ' dari total alokasi.</span>';
            else if (excess > VEPS) ex.innerHTML = '<span style="color:#e6a817;">Lebih bayar ' + fmtAmt(excess) + ' ' + enc(code) + ' (dicatat sebagai excess, tidak dialokasikan ke termin).</span>';
            else ex.innerHTML = '';
        }
        // [AUTO-ALLOC] ringkasan porsi alokasi per proyek (tampil bila > 1 proyek terisi)
        var sp = document.getElementById('lblPpSplit');
        if (sp) {
            var sums = {}, names = [];
            outDs.each(function (r) {
                var pv = parseFloat(r.get('pay')) || 0;
                if (pv <= 0) return;
                var k = r.get('project_name');
                if (sums[k] === undefined) { sums[k] = 0; names.push(k); }
                sums[k] += pv;
            });
            if (names.length > 1 && alloc > 0) {
                var parts = [];
                Ext.each(names, function (k) {
                    parts.push(enc(k) + ' <b>' + Ext.util.Format.number(sums[k] / alloc * 100, '0.0') + '%</b> (' + fmtAmt(sums[k]) + ')');
                });
                sp.innerHTML = 'Porsi per proyek: ' + parts.join(' &nbsp;|&nbsp; ');
            } else {
                sp.innerHTML = '';
            }
            if (allocNote) sp.innerHTML += (sp.innerHTML ? '<br/>' : '') + '<span style="color:#e6c36a;">' + allocNote + '</span>';     // [RULE-TERMIN]
        }
        fitGrid();                                                                       // [FIX] tinggi footer berubah -> sesuaikan grid
    }

    // [AUTO-ALLOC][RULE-TERMIN] Bagi Jumlah Diterima OTOMATIS ke proyek milik client. Semua hitungan dalam IDR, lalu dikonversi ke mata uang bayar.
    //  1. Jumlah diterima >= total sisa tagihan semua termin  -> semua termin LUNAS; kelebihannya = lebih bayar (deposit client / excess_amount).
    //  2. Jumlah diterima <  total sisa tagihan -> tiap proyek (yang punya termin outstanding) dihitung dua angka, DIAMBIL YANG BESAR
    //     supaya proyek lebih cepat lunas:
    //       a. Porsi proporsional  = diterima x kontrak proyek / total kontrak proyek peserta  (semua kontrak 0 -> bagi rata)
    //       b. Aturan termin       = % kontrak sesuai urutan termin yang akan dibayar (termin 1 = 30%, termin 2 = 20%, TERMIN_RULE_PCT);
    //                                proyek yang belum pernah dibayar = DP 30% kontrak. Bila termin tsb sudah dicicil, dikurangi yang sudah dibayar.
    //     Angka terbesar dibatasi total sisa termin proyek. Bila jumlah semua target > uang diterima -> target diskalakan proporsional.
    //     Bila target < uang diterima (karena batas sisa termin) -> sisanya dibagi lagi proporsional kontrak ke proyek yang masih punya ruang.
    //  3. Di dalam proyek, porsi diisikan ke termin berurutan dari tanggal terlama. Sisa yang tak tertampung = lebih bayar (excess).
    //  silent = dipanggil otomatis (tanpa popup peringatan)
    function autoAllocate(silent) {
        silent = (silent === true);                         // klik tombol mengirim event -> bukan silent
        itemGrid.stopEditing();
        var total = numVal(fTotal), rate = curRate();
        if (total <= 0) { if (!silent) Ext.Msg.alert('Peringatan', 'Isi Jumlah Diterima terlebih dahulu.'); return; }
        if (!(rate > 0)) { if (!silent) Ext.Msg.alert('Peringatan', 'Isi kurs ' + curCode() + ' terlebih dahulu.'); return; }
        if (!outDs.getCount()) { if (!silent) Ext.Msg.alert('Peringatan', 'Belum ada termin yang bisa dialokasikan. Pilih client atau tambah termin dulu.'); return; }

        var totalIdr = round2(total * rate);                // [RULE-TERMIN] hitung dalam IDR
        var smap = {};
        Ext.each(projSummary, function (p) { smap[p.project_id] = p; });

        // kelompokkan termin per proyek (urutan termin = urutan tanggal dari backend)
        var recs = outDs.getRange(), groups = {}, order = [], i, sumRemain = 0, sumContract = 0;
        for (i = 0; i < recs.length; i++) {
            var pid = recs[i].get('project_id'), rem = round2(recs[i].get('remaining'));
            if (!groups[pid]) { groups[pid] = { recs: [], cap: 0, given: 0, w: 0, prop: 0, rule: 0, want: 0, name: recs[i].get('project_name') }; order.push(pid); }
            groups[pid].recs.push(recs[i]);
            groups[pid].cap = round2(groups[pid].cap + rem);
            sumRemain = round2(sumRemain + rem);
        }
        Ext.each(order, function (id) {
            var sm = smap[id];
            groups[id].contract = sm ? sm.contract_value : 0;
            groups[id].everPaid = !!sm && sm.total_paid > VEPS;
            sumContract += groups[id].contract;
        });

        var notes = [];
        if (totalIdr + VEPS >= sumRemain) {
            // (1) uang diterima cukup untuk semua termin -> lunas semua, sisanya deposit
            Ext.each(order, function (id) { groups[id].given = groups[id].cap; });
            notes.push('Jumlah diterima &ge; total sisa tagihan (' + fmtAmt(sumRemain) + ' IDR): semua termin dilunasi, kelebihan dicatat sebagai deposit client.');
        } else {
            // (2) uang kurang -> bandingkan porsi proporsional vs aturan termin, ambil yang besar
            var sumWant = 0;
            Ext.each(order, function (id) {
                var g = groups[id], first = g.recs[0];
                g.w = sumContract > 0 ? g.contract : 1;                                        // semua kontrak 0 -> bagi rata
                g.prop = round2(totalIdr * g.w / (sumContract > 0 ? sumContract : order.length));
                var n = parseInt(first.get('termin_no'), 10) || 1;
                var pct = g.everPaid ? (TERMIN_RULE_PCT[n] || 0) : DP_PCT;                    // belum pernah bayar -> DP 30%
                g.ruleLabel = g.everPaid ? ('termin ke-' + n + ' ' + pct + '%') : ('DP ' + pct + '%');
                g.rule = Math.max(round2(g.contract * pct / 100) - round2(first.get('paid_amount')), 0);   // kurangi cicilan termin tsb
                g.want = Math.min(Math.max(g.prop, g.rule), g.cap);                           // ambil angka terbesar, maks sisa termin proyek
                sumWant = round2(sumWant + g.want);
            });
            var left = totalIdr;
            if (sumWant > totalIdr) {
                // target melebihi uang diterima -> skala proporsional
                var f = totalIdr / sumWant, used = 0;
                Ext.each(order, function (id) { var g = groups[id]; g.given = Math.min(round2(g.want * f), g.cap); used = round2(used + g.given); });
                left = round2(totalIdr - used);
            } else {
                Ext.each(order, function (id) { groups[id].given = groups[id].want; });
                left = round2(totalIdr - sumWant);
            }
            // sisa dana (batas sisa termin / pembulatan) -> bagi proporsional kontrak ke proyek yang masih punya ruang
            var active = order.slice();
            for (var guard = 0; guard < 50 && left >= 0.01 && active.length; guard++) {
                var wsum = 0, used2 = 0, next = [];
                Ext.each(active, function (id) { wsum += groups[id].w; });
                if (!(wsum > 0)) break;
                Ext.each(active, function (id) {
                    var g = groups[id], room = round2(g.cap - g.given);
                    var take = Math.min(round2(left * g.w / wsum), room, round2(left - used2));
                    if (take > 0) { g.given = round2(g.given + take); used2 = round2(used2 + take); }
                    if (round2(g.cap - g.given) >= 0.01) next.push(id);
                });
                if (used2 < 0.01) break;
                left = round2(left - used2);
                active = next;
            }
            for (i = 0; i < order.length && left >= 0.01; i++) {                              // sisa receh -> isi berurutan
                var g2 = groups[order[i]], t2 = Math.min(left, round2(g2.cap - g2.given));
                if (t2 > 0) { g2.given = round2(g2.given + t2); left = round2(left - t2); }
            }
            if (left <= -0.01) {                                                              // [FIX] pembulatan skala melebihi uang diterima (mis. +0.01)
                var big = order[0];
                Ext.each(order, function (id) { if (groups[id].given > groups[big].given) big = id; });
                groups[big].given = round2(groups[big].given + left);
                left = 0;
            }
            Ext.each(order, function (id) {
                var g = groups[id];
                notes.push('&bull; ' + enc(g.name) + ': ' + enc(g.ruleLabel) + ' = ' + fmtAmt(g.rule) + ' | proporsional = ' + fmtAmt(g.prop) +
                           ' &rarr; target ' + fmtAmt(g.want) + (Math.abs(g.given - g.want) >= 0.01 ? ' (disesuaikan menjadi ' + fmtAmt(g.given) + ')' : ''));
            });
        }

        // porsi proyek (IDR) -> termin berurutan; konversi ke mata uang pembayaran
        Ext.each(order, function (id) {
            var rest = groups[id].given;
            Ext.each(groups[id].recs, function (r) {
                var rm = round2(r.get('remaining'));
                var idr = Math.min(rest, rm);
                var pay = (idr >= rm - VEPS) ? maxPay(rm) : round2(idr / rate);
                if (!(pay > 0)) pay = 0;
                r.set('pay', round2(pay));
                rest = round2(rest - idr);
            });
        });
        allocNote = notes.join('<br/>');
        autoMode = true;
        totalManual = true;
        recalcAll();
        itemGrid.getView().refresh();
    }

    function payAllRemaining() {
        itemGrid.stopEditing();
        if (!(curRate() > 0)) { Ext.Msg.alert('Peringatan', 'Isi kurs ' + curCode() + ' terlebih dahulu.'); return; }
        outDs.each(function (r) { r.set('pay', maxPay(round2(r.get('remaining')))); });
        autoMode = false;
        allocNote = '';
        totalManual = false;
        recalcAll();
    }

    // [NEW] Hapus termin yang salah entry (tombol ✕ di grid). Backend hanya mengizinkan bila termin belum pernah dibayar.
    function deleteTermin(rec) {
        if (getFormAccess() < ACCESS_EDIT) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk menghapus termin.'); return; }
        var cid = fClient.getValue();
        Ext.Msg.confirm('Hapus Termin',
            'Hapus termin <b>' + enc(rec.get('inv_no')) + '</b> (' + enc(rec.get('termin_name')) + ') proyek <b>' + enc(rec.get('project_name')) + '</b>?<br/>' +
            'Termin dihapus permanen dari data proyek. Hanya bisa bila belum ada pembayaran.',
            function (btn) {
                if (btn !== 'yes') return;
                ajax({ task: 'delete_termin', id: rec.get('invoice_id'), client_id: cid }, function (res) {
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) loadOutstanding(cid);      // muat ulang: grid + ringkasan kontrak (nilai pay lain dipertahankan)
                });
            });
    }

    // ---- Window Tambah Termin ----------------------------------------------
    // [MULTI-PROYEK] termin dibuat untuk salah satu proyek milik client terpilih (combo Proyek di dalam window)
    function openTerminWindow() {
        if (getFormAccess() < ACCESS_ADD) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk membuat termin.'); return; }
        var cid = fClient.getValue();
        if (!cid) { Ext.Msg.alert('Peringatan', 'Pilih client terlebih dahulu.'); return; }

        var prjRows = [];
        projectDs.each(function (r) {
            if (Number(r.get('client_id')) === Number(cid)) prjRows.push([r.get('id'), r.get('name'), parseFloat(r.get('contract_value')) || 0]);
        });
        if (!prjRows.length) { Ext.Msg.alert('Peringatan', 'Client ini belum memiliki proyek.'); return; }
        var contract = prjRows[0][2];

        var tProject = new myComboField({
            fieldLabel: 'Proyek', allowBlank: false, anchor: '95%',
            store: new Ext.data.ArrayStore({ fields: ['id', 'name', 'contract_value'], data: prjRows }),
            displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true,
            value: prjRows[0][0],
            listeners: {
                select: function (combo, rec) {
                    contract = parseFloat(rec.get('contract_value')) || 0;
                    refreshContractInfo();
                    recalcFromPct();
                }
            }
        });
        var tName = new myTextField({ fieldLabel: 'Nama Termin', allowBlank: false, anchor: '95%', emptyText: 'mis. Termin 1 - DP' });
        var tDate = new Ext.form.DateField({ fieldLabel: 'Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 130 });
        var tAmt = new myNumberField({ fieldLabel: 'Nilai Termin', decimalPrecision: 2, value: 0, width: 150 });
        var tPct = new myNumberField({
            fieldLabel: 'Persentase (%)', decimalPrecision: 2, width: 100,
            listeners: { change: function () { recalcFromPct(); } }
        });
        var tInfo = new Ext.form.DisplayField({ hideLabel: true, value: '' });

        function recalcFromPct() {      // nilai otomatis = persentase x nilai kontrak proyek terpilih
            var p = parseFloat(tPct.getValue()) || 0;
            if (contract > 0 && p > 0) tAmt.setValue(round2(contract * p / 100));
        }
        function refreshContractInfo() {
            tInfo.setValue('<span style="color:#9fc3d1;">Nilai kontrak: <b>' + fmtAmt(contract) + '</b>. ' +
                (contract > 0 ? 'Isi persentase untuk menghitung nilai otomatis.' : 'Nilai kontrak proyek masih 0, isi nilai termin manual.') + '</span>');
        }
        refreshContractInfo();

        // REMARK (FIX): window sebelumnya layout 'fit' tanpa tinggi -> body menciut & tombol bawaan tema gelap tak terbaca.
        // Sekarang: tinggi tetap, label berwarna terang, tombol HTML biasa (gaya sama dengan tombol editor).
        var lblStyle = 'color:#9fc3d1;';
        var btnCss = 'margin-left:8px; padding:7px 18px; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold;';
        var win = new Ext.Window({
            title: 'Tambah Termin - ' + enc(fClient.getRawValue()),
            width: 460, height: 360, modal: true, closeAction: 'close', resizable: false,      // [MULTI-PROYEK] tinggi +40 untuk combo Proyek
            layout: 'fit',
            bodyStyle: 'background:#0f1d29;',
            items: [new Ext.form.FormPanel({
                border: false, labelWidth: 120, labelStyle: lblStyle, autoScroll: true,
                bodyStyle: 'background:#0f1d29; padding:14px;',
                defaults: { labelStyle: lblStyle },
                items: [
                    tProject, tName, tDate, tPct, tAmt, tInfo,
                    {
                        xtype: 'panel', border: false, bodyStyle: 'background:transparent; text-align:right; padding-top:10px;',
                        html: '<button type="button" id="ppTerminSave" style="' + btnCss + 'background:#03615e;">Simpan</button>' +
                              '<button type="button" id="ppTerminCancel" style="' + btnCss + 'background:#555555;">Batal</button>',
                        listeners: {
                            afterrender: function () {
                                document.getElementById('ppTerminCancel').onclick = function () { win.close(); };
                                document.getElementById('ppTerminSave').onclick = function () {
                                    if (!tProject.getValue()) { Ext.Msg.alert('Peringatan', 'Proyek wajib dipilih.'); return; }
                                    if (!tName.getValue()) { Ext.Msg.alert('Peringatan', 'Nama termin wajib diisi.'); return; }
                                    if (!tDate.getValue()) { Ext.Msg.alert('Peringatan', 'Tanggal termin wajib diisi.'); return; }
                                    if (!((parseFloat(tAmt.getValue()) || 0) > 0)) { Ext.Msg.alert('Peringatan', 'Nilai termin harus lebih dari 0.'); return; }
                                    ajax({
                                        task: 'save_termin',
                                        client_id: cid,                                          // [MULTI-PROYEK] backend validasi proyek milik client ini
                                        project_id: tProject.getValue(),
                                        termin_name: tName.getValue(),
                                        invoice_date: tDate.getValue().dateFormat('Y-m-d'),     // [FIX] .format() tertimpa global (hasil 'Y-10-15'); dateFormat = fungsi asli Ext
                                        percentage: (parseFloat(tPct.getValue()) || 0) > 0 ? tPct.getValue() : '',
                                        amount: tAmt.getValue()
                                    }, function (res) {
                                        Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                                        if (res.success) { win.close(); loadOutstanding(cid); }
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
        var ef = document.getElementById('editorForm');
        if (ef) ef.style.overflowY = 'auto';                  // [FIX] editor bisa di-scroll
        ensureHdrForm();
        setTimeout(function () { fitGrid(); try { itemGrid.getView().layout(); } catch (e) {} }, 50);
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
    // [MULTI-PROYEK] kirim client_id; item = invoice_id + amount_paid (invoice boleh dari proyek berbeda, backend validasi client)
    document.getElementById('saveEdit').onclick = function () {
        if (!fClient || !fClient.getValue()) { Ext.Msg.alert('Peringatan', 'Client wajib dipilih.'); return; }
        if (!fDate.getValue()) { Ext.Msg.alert('Peringatan', 'Tanggal pembayaran wajib diisi.'); return; }
        if (!fAccount.getValue()) { Ext.Msg.alert('Peringatan', 'Akun Kas/Bank wajib dipilih.'); return; }     // [JURNAL]
        var cid = fClient.getValue();
        if (!isBaseCur(curId()) && !(curRate() > 0)) { Ext.Msg.alert('Peringatan', 'Kurs ' + curCode() + ' wajib diisi.'); return; }     // [MULTI-CURRENCY]

        var arr = [], alloc = 0, err = '';
        outDs.each(function (r) {
            var pay = round2(r.get('pay'));
            if (pay <= 0) return;                                  // termin tanpa pembayaran diabaikan
            if (pay > maxPay(round2(r.get('remaining'))) + VEPS) {      // [MULTI-CURRENCY] banding dalam mata uang pembayaran
                err = err || ('Termin ' + r.get('inv_no') + ' (' + r.get('project_name') + '): bayar melebihi sisa tagihan.');
                return;
            }
            arr.push({ invoice_id: r.get('invoice_id'), amount_paid: pay });
            alloc += pay;
        });
        if (err) { Ext.Msg.alert('Peringatan', err); return; }
        alloc = round2(alloc);
        if (!arr.length) { Ext.Msg.alert('Peringatan', 'Isi "Bayar Sekarang" pada minimal satu termin.'); return; }

        var total = round2(fTotal.getValue());
        if (total <= 0) total = alloc;
        if (total + VEPS < alloc) { Ext.Msg.alert('Peringatan', 'Jumlah diterima lebih kecil dari total alokasi termin.'); return; }
        var excess = round2(total - alloc);

        var doSave = function () {
            ajax({
                task: 'save_payment',
                client_id: cid,                                          // [MULTI-PROYEK] ganti project_id
                currency_id: curId() || 0,                               // [MULTI-CURRENCY] total_amount & amount_paid = mata uang ini
                exch_rate: curRate(),
                payment_date: fDate.getValue().dateFormat('Y-m-d'),     // [FIX] idem
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
                    loadOutstanding(cid);     // sisa tagihan mungkin berubah -> muat ulang
                }
            });
        };

        if (excess > VEPS) {
            Ext.Msg.confirm('Konfirmasi Lebih Bayar',
                'Jumlah diterima melebihi total alokasi sebesar <b>' + fmtAmt(excess) + ' ' + curCode() + '</b>. Selisih dicatat sebagai lebih bayar. Lanjutkan simpan?',
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
    projectDs.load();      // dipakai window Tambah Termin (proyek per client)
    clientDs.load();       // [MULTI-PROYEK]
    currencyDs.load();     // [MULTI-CURRENCY]
    accountDs.load();
});