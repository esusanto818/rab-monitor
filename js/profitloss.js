/*
  Document      : financial_profit_loss.js
  Description   : Income Statement (Rugi Laba) - Read-only report
  Backend       : /proyek/php-crud/profitlossCRD.php
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Profit & <em>Loss</em>";

    // Sembunyikan toolbar CRUD bawaan
    ['addData', 'editData', 'deleteData'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    // 0. Buat container filter & report secara dinamis
    var gridContainer = document.getElementById('divMainGrid');
    var filterBarDiv = document.createElement('div');
    filterBarDiv.id = 'plFilterBar';
    filterBarDiv.style.padding = '0 10px';

    var reportDiv = document.createElement('div');
    reportDiv.id = 'plReportContainer';
    reportDiv.style.cssText = 'padding:15px; background:rgba(255,255,255,0.03); color:#fff; margin:0 10px 10px 10px; border-radius:4px; border:1px solid rgba(255,255,255,0.1); font-size:13px;';

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(filterBarDiv, gridContainer);
        gridContainer.parentNode.insertBefore(reportDiv, gridContainer);
    }

    // 1. Store Periode
    var periodField = [{ name: 'id' }, { name: 'period_name' }, { name: 'start_date' }, { name: 'end_date' }];
    var periodDs = new Ext.data.Store({
        url: '../php-crud/profitlossCRD.php',
        baseParams: { task: 'read_periods' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, periodField),
        listeners: {
            load: function (store) {
                if (store.getCount() > 0 && !fldPeriod.getValue()) {
                    fldPeriod.setValue(store.getAt(0).get('id'));
                    loadProfitLoss();
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
        renderTo: 'plFilterBar',
        layout: 'column',
        border: false,
        bodyStyle: 'background:transparent; padding:8px 0;',
        defaults: { border: false, bodyStyle: 'background:transparent;' },
        items: [
            { columnWidth: 0.35, layout: 'form', labelWidth: 60, items: fldPeriod },
            { columnWidth: 0.15, style: 'padding-top:2px;', items: btnShow }
        ]
    });

    function loadProfitLoss() {
        var perId = fldPeriod.getValue();
        if (!perId) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih periode terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }

        Ext.Ajax.request({
            url: '../php-crud/profitlossCRD.php',
            method: 'POST',
            params: { task: 'read_profit_loss', period_id: perId },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    reportDiv.innerHTML = '<div style="color:yellow;">' + (res.message || 'Gagal memuat data.') + '</div>';
                    return;
                }
                renderPLView(res);
            }
        });
    }

    function renderPLView(res) {
        var fmt = function (val) { return Ext.util.Format.number(val || 0, '0,000.00'); };

        var html = '<h2 style="text-align:center; margin-bottom:4px; font-weight:normal;">LAPORAN RUGI LABA</h2>' +
                   '<h4 style="text-align:center; color:#bbb; margin-top:0; font-weight:normal;">Periode: ' + res.period_name + ' (' + res.start_date + ' s/d ' + res.end_date + ')</h4>' +
                   '<hr style="border:0; border-top:1px solid rgba(255,255,255,0.15); margin:15px 0;" />';

        // Pendapatan
        html += '<h3 style="color:#2e9e4f; margin-bottom:8px;">PENDAPATAN</h3>' +
                '<table width="100%" style="border-collapse:collapse; margin-bottom:15px;">';
        Ext.each(res.revenues, function (r) {
            html += '<tr style="border-bottom:1px solid rgba(255,255,255,0.05); color: #71de25;">' +
                    '<td padding="6px" width="15%">' + r.account_code + '</td>' +
                    '<td padding="6px">' + r.account_name + '</td>' +
                    '<td padding="6px" align="right" width="20%">' + fmt(r.amount) + '</td>' +
                    '</tr>';
        });
        html += '<tr style="font-weight:bold; background:rgba(255,255,255,0.05); color: #71de25;">' +
                '<td colspan="2" style="padding:8px;">TOTAL PENDAPATAN</td>' +
                '<td align="right" style="padding:8px; color:#2e9e4f;">' + fmt(res.total_revenue) + '</td>' +
                '</tr></table>';

        // Beban
        html += '<h3 style="color:#e74c3c; margin-bottom:8px;">BEBAN / BIAYA</h3>' +
                '<table width="100%" style="border-collapse:collapse; margin-bottom:20px;">';
        Ext.each(res.expenses, function (e) {
            html += '<tr style="border-bottom:1px solid rgba(255,255,255,0.05);color: #e74c3c">' +
                    '<td padding="6px" width="15%">' + e.account_code + '</td>' +
                    '<td padding="6px">' + e.account_name + '</td>' +
                    '<td padding="6px" align="right" width="20%">' + fmt(e.amount) + '</td>' +
                    '</tr>';
        });
        html += '<tr style="font-weight:bold; background:rgba(255,255,255,0.05); color:#e74c3c">' +
                '<td colspan="2" style="padding:8px;">TOTAL BEBAN</td>' +
                '<td align="right" style="padding:8px; color:#e74c3c;">' + fmt(res.total_expense) + '</td>' +
                '</tr></table>';

        // Summary Laba / Rugi
        var netColor = res.net_profit_loss >= 0 ? '#71de25' : '#e74c3c';
        var netLabel = res.net_profit_loss >= 0 ? 'LABA BERSIH' : 'RUGI BERSIH';

        html += '<div style="padding:12px; background:rgba(255,255,255,0.08; ) ;font-size:15px; font-weight:bold; display:flex; justify-content:space-between; border-radius:4px;">' +
                '<span>' + netLabel + '</span>' +
                '<span style="color:' + netColor + ';">' + fmt(res.net_profit_loss) + '</span>' +
                '</div>';

        reportDiv.innerHTML = html;
    }

    btnShow.on('click', loadProfitLoss);

    document.getElementById('printData').onclick = function () {
        var perId = fldPeriod.getValue();
        if (!perId) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih periode terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        window.open('../pdf/profitloss.php?period_id=' + perId, 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');
    };

    if (document.getElementById('popOK')) {
        document.getElementById('popOK').onclick = function () {
            document.getElementById('popWdw').style.display = 'none';
        };
    }
});