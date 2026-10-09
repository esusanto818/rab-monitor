/*
  Document      : financialreport.js
  Description   : Laporan Keuangan - Neraca & Laba Rugi, render sebagai
                   HTML table (bukan Ext Grid, supaya mudah bikin format
                   laporan keuangan konvensional: indentasi, subtotal, dll).
  Backend       : /proyek/php-crud/financialreportCRD.php
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Financial <em>Report</em>";

    ['addData', 'editData', 'deleteData'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    // ============================================================
    // 0. Buat elemen filter bar & area laporan secara dinamis
    // ============================================================
    var gridContainer = document.getElementById('divMainGrid');
    if (gridContainer) gridContainer.style.display = 'none'; // tidak pakai Ext grid di modul ini

    var wrapper = document.createElement('div');
    wrapper.style.padding = '0 10px';
    wrapper.innerHTML =
        '<div id="frFilterBar" style="padding:8px 0;"></div>' +
        '<div style="display:flex; gap:20px; flex-wrap:wrap;">' +
        '  <div id="frBalanceSheet" style="flex:1; min-width:400px;"></div>' +
        '  <div id="frIncomeStatement" style="flex:1; min-width:400px;"></div>' +
        '</div>';
    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(wrapper, gridContainer);
    }

    // ============================================================
    // 1. Filter periode
    // ============================================================
    var periodField = [{ name: 'id' }, { name: 'period_name' }];
    var periodDs = new Ext.data.Store({
        url: '../php-crud/financialreportCRD.php',
        baseParams: { task: 'read_periods' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, periodField),
        listeners: {
            load: function (store) {
                if (store.getCount() > 0 && !fldPeriod.getValue()) {
                    fldPeriod.setValue(store.getAt(0).get('id'));
                    loadReport();
                }
            }
        }
    });
    periodDs.load();

    var fldPeriod = new myComboField({
        fieldLabel: 'Periode', width: 200, store: periodDs, displayField: 'period_name', valueField: 'id', allowBlank: false
    });
    var btnShow = new Ext.Button({ text: 'Tampilkan', cls: 'field-button grey-field-button' });

    new Ext.Panel({
        renderTo: 'frFilterBar',
        layout: 'column',
        border: false,
        bodyStyle: 'background:transparent;',
        defaults: { border: false, bodyStyle: 'background:transparent;' },
        items: [
            { columnWidth: 0.3, layout: 'form', labelWidth: 60, items: fldPeriod },
            { columnWidth: 0.15, style: 'padding-top:2px;', items: btnShow }
        ]
    });
    btnShow.on('click', loadReport);

    // ============================================================
    // 2. Helper render tabel HTML
    // ============================================================
    function fmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }

    function renderAccountRows(items) {
        return items.map(function (it) {
            return '<tr style="color:#e5e5e5;"><td style="padding-left:20px;">' + it.account_code + ' - ' + it.account_name + '</td>' +
                   '<td style="text-align:right;">' + fmt(it.balance !== undefined ? it.balance : it.amount) + '</td></tr>';
        }).join('');
    }

    function renderBalanceSheet(bs) {
        var balColor = bs.is_balance ? '#2e9e4f' : '#c0392b';
        var balText = bs.is_balance ? 'BALANCE ✓' : 'TIDAK BALANCE ✗';
        var html = '<h3 style="margin-bottom:6px; color:#fff;">NERACA</h3>' +
            '<table style="width:100%; border-collapse:collapse; color:#e5e5e5;" class="fr-table">' +
            '<tr style="background:rgba(255,255,255,0.12); color:#fff;"><td colspan="2"><b>ASET</b></td></tr>' +
            renderAccountRows(bs.asset) +
            '<tr style="border-top:1px solid #888; color:#fff;"><td><b>Total Aset</b></td><td style="text-align:right;"><b>' + fmt(bs.asset_total) + '</b></td></tr>' +
            '<tr><td colspan="2">&nbsp;</td></tr>' +
            '<tr style="background:rgba(255,255,255,0.12); color:#fff;"><td colspan="2"><b>LIABILITAS</b></td></tr>' +
            renderAccountRows(bs.liability) +
            '<tr style="border-top:1px solid #888; color:#fff;"><td><b>Total Liabilitas</b></td><td style="text-align:right;"><b>' + fmt(bs.liability_total) + '</b></td></tr>' +
            '<tr><td colspan="2">&nbsp;</td></tr>' +
            '<tr style="background:rgba(255,255,255,0.12); color:#fff;"><td colspan="2"><b>EKUITAS</b></td></tr>' +
            renderAccountRows(bs.equity) +
            '<tr><td style="padding-left:20px;">Laba (Rugi) Kumulatif s/d Periode Ini</td><td style="text-align:right;">' + fmt(bs.net_income_cumulative) + '</td></tr>' +
            '<tr style="border-top:1px solid #888; color:#fff;"><td><b>Total Ekuitas</b></td><td style="text-align:right;"><b>' + fmt(bs.equity_total) + '</b></td></tr>' +
            '<tr><td colspan="2">&nbsp;</td></tr>' +
            '<tr style="border-top:2px solid #888; color:#fff;"><td><b>Total Liabilitas + Ekuitas</b></td><td style="text-align:right;"><b>' + fmt(bs.liability_total + bs.equity_total) + '</b></td></tr>' +
            '</table>' +
            '<p style="color:' + balColor + '; font-weight:bold; margin-top:8px;">' + balText + '</p>';
        document.getElementById('frBalanceSheet').innerHTML = html;
    }

    function renderIncomeStatement(is) {
        var niColor = is.net_income_period >= 0 ? '#2e9e4f' : '#c0392b';
        var niLabel = is.net_income_period >= 0 ? 'LABA BERSIH' : 'RUGI BERSIH';
        var html = '<h3 style="margin-bottom:6px; color:#fff;">LAPORAN LABA RUGI</h3>' +
            '<table style="width:100%; border-collapse:collapse; color:#e5e5e5;" class="fr-table">' +
            '<tr style="background:rgba(255,255,255,0.12); color:#fff;"><td colspan="2"><b>PENDAPATAN</b></td></tr>' +
            renderAccountRows(is.revenue) +
            '<tr style="border-top:1px solid #888; color:#fff;"><td><b>Total Pendapatan</b></td><td style="text-align:right;"><b>' + fmt(is.revenue_total) + '</b></td></tr>' +
            '<tr><td colspan="2">&nbsp;</td></tr>' +
            '<tr style="background:rgba(255,255,255,0.12); color:#fff;"><td colspan="2"><b>BEBAN</b></td></tr>' +
            renderAccountRows(is.expense) +
            '<tr style="border-top:1px solid #888; color:#fff;"><td><b>Total Beban</b></td><td style="text-align:right;"><b>' + fmt(is.expense_total) + '</b></td></tr>' +
            '<tr><td colspan="2">&nbsp;</td></tr>' +
            '<tr style="border-top:2px solid #888;"><td style="color:#fff;"><b>' + niLabel + '</b></td><td style="text-align:right; color:' + niColor + ';"><b>' + fmt(Math.abs(is.net_income_period)) + '</b></td></tr>' +
            '</table>';
        document.getElementById('frIncomeStatement').innerHTML = html;
    }

    function loadReport() {
        var perId = fldPeriod.getValue();
        if (!perId) return;
        Ext.Ajax.request({
            url: '../php-crud/financialreportCRD.php',
            method: 'POST',
            params: { task: 'read_report', period_id: perId },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    Ext.Msg.alert('Error', res.message || 'Gagal memuat laporan.');
                    return;
                }
                renderBalanceSheet(res.balance_sheet);
                renderIncomeStatement(res.income_statement);
            }
        });
    }

    document.getElementById('printData').onclick = function () {
        var perId = fldPeriod.getValue();
        if (!perId) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih periode terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        window.open('../pdf/financialreport.php?period_id=' + perId, 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');
    };
});