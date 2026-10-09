/*
  Document      : financial_balance_sheet.js
  Description   : Balance Sheet (Neraca Keuangan) - Read-only report
  Backend       : /proyek/php-crud/balancesheetCRD.php
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Balance <em>Sheet</em>";

    ['addData', 'editData', 'deleteData'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    // 0. Container filter & report
    var gridContainer = document.getElementById('divMainGrid');
    var filterBarDiv = document.createElement('div');
    filterBarDiv.id = 'bsFilterBar';
    filterBarDiv.style.padding = '0 10px';

    var reportDiv = document.createElement('div');
    reportDiv.id = 'bsReportContainer';
    reportDiv.style.cssText = 'padding:15px; background:rgba(255,255,255,0.03); color:#fff; margin:0 10px 10px 10px; border-radius:4px; border:1px solid rgba(255,255,255,0.1); font-size:13px;';

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(filterBarDiv, gridContainer);
        gridContainer.parentNode.insertBefore(reportDiv, gridContainer);
    }

    // 1. Store Periode
    var periodField = [{ name: 'id' }, { name: 'period_name' }, { name: 'start_date' }, { name: 'end_date' }];
    var periodDs = new Ext.data.Store({
        url: '../php-crud/balancesheetCRD.php',
        baseParams: { task: 'read_periods' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, periodField),
        listeners: {
            load: function (store) {
                if (store.getCount() > 0 && !fldPeriod.getValue()) {
                    fldPeriod.setValue(store.getAt(0).get('id'));
                    loadBalanceSheet();
                }
            }
        }
    });
    periodDs.load();

    // 2. Filter Bar
    var fldPeriod = new myComboField({
        fieldLabel: 'Periode', width: 220, store: periodDs, displayField: 'period_name', valueField: 'id', allowBlank: false
    });
    var btnShow = new Ext.Button({ text: 'Tampilkan', cls: 'field-button grey-field-button' });

    var filterPanel = new Ext.Panel({
        renderTo: 'bsFilterBar',
        layout: 'column',
        border: false,
        bodyStyle: 'background:transparent; padding:8px 0;',
        defaults: { border: false, bodyStyle: 'background:transparent;' },
        items: [
            { columnWidth: 0.35, layout: 'form', labelWidth: 60, items: fldPeriod },
            { columnWidth: 0.15, style: 'padding-top:2px;', items: btnShow }
        ]
    });

    function loadBalanceSheet() {
        var perId = fldPeriod.getValue();
        if (!perId) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih periode terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }

        Ext.Ajax.request({
            url: '../php-crud/balancesheetCRD.php',
            method: 'POST',
            params: { task: 'read_balance_sheet', period_id: perId },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    reportDiv.innerHTML = '<div style="color:yellow;">' + (res.message || 'Gagal memuat data.') + '</div>';
                    return;
                }
                renderBSView(res);
            }
        });
    }

    function renderBSView(res) {
        var fmt = function (val) { return Ext.util.Format.number(val || 0, '0,000.00'); };

        var balanceColor = res.is_balanced ? '#2e9e4f' : '#c0392b';
        var balanceText  = res.is_balanced ? 'BALANCE ✓' : 'TIDAK BALANCE ✗';

        var html = '<h2 style="text-align:center; margin-bottom:4px; font-weight:normal;">NERACA KEUANGAN</h2>' +
                   '<h4 style="text-align:center; color:#bbb; margin-top:0; font-weight:normal;">Per Tanggal: ' + res.as_of_date + ' (' + res.period_name + ')</h4>' +
                   '<div style="text-align:center; margin-bottom:15px;"><b style="color:' + balanceColor + '; font-size:14px;">[ STATUS: ' + balanceText + ' ]</b></div>' +
                   '<hr style="border:0; border-top:1px solid rgba(255,255,255,0.15); margin:15px 0;" />';

        html += '<table width="100%" style="border-collapse:collapse;" color:"#ffeb3b"; font-size:"13px";"cellpadding="6">' +
                '<tr style="vertical-align:top;">' +

                // KOLOM KIRI: ASET
                '<td width="50%" style="border-right:1px solid rgb(138, 0, 0); padding-right:15px;">' +
                    '<h3 style="color:#98db34; margin-bottom:8px;">ASET (AKTIVA)</h3>' +
                    '<table width="100%" style="border-collapse:collapse;">';
        
        Ext.each(res.assets, function (a) {
            html += '<tr style="border-bottom:1px solid rgba(0, 0, 0, 0.2); color:#ffeb3b ;">' +
                    '<td>' + a.account_code + '</td>' +
                    '<td>' + a.account_name + '</td>' +
                    '<td align="right">' + fmt(a.balance) + '</td>' +
                    '</tr>';
        });

        html += '</table>' +
                '<div style="margin-top:20px; padding:10px; background:rgba(234, 0, 255, 0.05);color: #98db34; font-weight:bold; display:flex; justify-content:space-between;">' +
                '<span>TOTAL ASET</span>' +
                '<span style="color:#98db34;">' + fmt(res.total_asset) + '</span>' +
                '</div>' +
                '</td>' +

                // KOLOM KANAN: LIABILITAS & EKUITAS
                '<td width="50%" style="padding-left:15px;">' +
                    '<h3 style="color:#f39c12; margin-bottom:8px;">LIABILITAS (KEWAJIBAN)</h3>' +
                    '<table width="100%" style="border-collapse:collapse; margin-bottom:15px;color: #f39c12;">';
        
        Ext.each(res.liabilities, function (l) {
            html += '<tr style="border-bottom:1px solid rgba(255,255,255,0.05);">' +
                    '<td>' + l.account_code + '</td>' +
                    '<td>' + l.account_name + '</td>' +
                    '<td align="right">' + fmt(l.balance) + '</td>' +
                    '</tr>';
        });

        html += '<tr style="font-weight:bold; background:rgba(255,255,255,0.03);">' +
                '<td colspan="2">TOTAL LIABILITAS</td>' +
                '<td align="right">' + fmt(res.total_liability) + '</td>' +
                '</tr></table>' +

                '<h3 style="color:#f39c12; margin-bottom:8px;">EKUITAS (MODAL)</h3>' +
                '<table width="100%" style="border-collapse:collapse;color: #f39c12;">';

        Ext.each(res.equities, function (eq) {
            html += '<tr style="border-bottom:1px solid rgba(255,255,255,0.05);">' +
                    '<td>' + eq.account_code + '</td>' +
                    '<td>' + eq.account_name + '</td>' +
                    '<td align="right">' + fmt(eq.balance) + '</td>' +
                    '</tr>';
        });

        html += '<tr style="font-weight:bold; background:rgba(255,255,255,0.03);">' +
                '<td colspan="2">TOTAL EKUITAS</td>' +
                '<td align="right">' + fmt(res.total_equity) + '</td>' +
                '</tr></table>' +

                '<div style="margin-top:20px; padding:10px; background:rgba(255,255,255,0.05); font-weight:bold; display:flex; justify-content:space-between;color: #f39c12;">' +
                '<span>TOTAL LIABILITAS & EKUITAS</span>' +
                '<span style="color:#f39c12;">' + fmt(res.total_liability_equity) + '</span>' +
                '</div>' +

                '</td>' +
                '</tr>' +
                '</table>';

        reportDiv.innerHTML = html;
    }

    btnShow.on('click', loadBalanceSheet);

    document.getElementById('printData').onclick = function () {
        var perId = fldPeriod.getValue();
        if (!perId) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih periode terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        window.open('../pdf/balancesheet.php?period_id=' + perId, 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');
    };

    if (document.getElementById('popOK')) {
        document.getElementById('popOK').onclick = function () {
            document.getElementById('popWdw').style.display = 'none';
        };
    }
});