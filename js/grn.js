/*
  Document      : grn00_5.js
  Description   : Purchase GRN (Goods Receipt Note) - ExtJS 3.x Frontend Controller
  Backend       : /inv/php-crud/grnCRD.php
  Multi currency: GRN membekukan mata uang & kurs PO. Harga satuan GRN dalam mata uang PO (bisa diedit);
                  nilai dalam mata uang dasar (= harga x kurs PO) dihitung server dan dipakai WH Rcv.
                  GRN hanya bisa dibuat dari PO berstatus Disetujui.
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Goods Receipt Note";

    var task = '';
    var dataRecord = null;
    var grnCur = { code: 'IDR', base: 'IDR', rate: 1 };      // mata uang GRN yang sedang dilihat/diedit

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }

    // ------------------------------------------------------------
    // 0. Container Detail Item GRN
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');

    var grnDtlDiv = document.createElement('div');
    grnDtlDiv.id = 'grnDtlContainer';
    grnDtlDiv.className = 'detail-panel-container';
    grnDtlDiv.style.display = 'none';

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(grnDtlDiv, gridContainer.nextSibling);
    }

    (function injectExtraUI() {
        var gridEditorContainer = document.getElementById('editorFormGrid');
        if (gridEditorContainer && !document.getElementById('lblGrnTotal')) {
            var totalsDiv = document.createElement('div');
            totalsDiv.className = 'invoice-totals';
            totalsDiv.style.cssText = 'padding:8px 0; font-weight:bold; font-size:14px; color:#fff; text-align:right;';
            totalsDiv.innerHTML = 'Total Nilai GRN: <span id="lblGrnTotal">0.00</span>';
            gridEditorContainer.parentNode.insertBefore(totalsDiv, gridEditorContainer.nextSibling);
        }
    })();

    // ------------------------------------------------------------
    // 1. Fields & Stores
    // ------------------------------------------------------------
    var grnField = [
        { name: 'id' }, { name: 'ref_no' }, { name: 'grn_date', type: 'date', dateFormat: 'Y-m-d' },
        { name: 'po_id' }, { name: 'po_ref_no' }, { name: 'supplier_id' }, { name: 'supplier_name' },
        { name: 'status' }, { name: 'remark' },
        { name: 'total_qty', type: 'float' }, { name: 'total_amount', type: 'float' },
        { name: 'currency_code' }, { name: 'exch_rate', type: 'float' }, { name: 'total_base', type: 'float' }
    ];

    var itemField = [
        { name: 'po_dtl_id' }, { name: 'item_id' }, { name: 'item_name' },
        { name: 'qty_ordered', type: 'float' }, { name: 'unit_price', type: 'float' },
        { name: 'qty_received', type: 'float' },
        { name: 'sisa_qty_po', type: 'float' },
        { name: 'qty_max_allowed', type: 'float' },
        { name: 'qty_receivable_max', type: 'float' },
        { name: 'qty_received_this_grn', type: 'float' },
        { name: 'unit_cost_this_grn', type: 'float' },
        { name: 'qty_input', type: 'float' },
        { name: 'unit_cost', type: 'float' }
    ];

    var poField = [{ name: 'id' }, { name: 'ref_no' }, { name: 'supplier_id' }, { name: 'supplier_name' },
                   { name: 'currency_code' }, { name: 'exch_rate', type: 'float' }];
    var poDs = new Ext.data.Store({
        url: '../php-crud/grnCRD.php',
        baseParams: { task: 'list_po_open' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, poField)
    });
    poDs.load();

    var itemGridDs = new Ext.data.Store({
        url: '../php-crud/grnCRD.php',
        baseParams: { task: 'read_po_items' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, itemField),
        listeners: {
            load: function (store) {
                var cu = store.reader.jsonData && store.reader.jsonData.currency;
                if (cu) {
                    grnCur = { code: cu.code || 'IDR', base: cu.base_code || 'IDR', rate: parseFloat(cu.exch_rate) || 1 };
                    applyCurrencyHeaders();
                }
                store.each(function (r) {
                    var existingQty = parseFloat(r.get('qty_received_this_grn')) || 0;
                    if (existingQty > 0 && task === 'update') {
                        r.set('qty_input', existingQty);
                    } else {
                        var defaultQty = parseFloat(r.get('sisa_qty_po')) || 0;
                        r.set('qty_input', defaultQty);
                    }

                    var existingCost = r.get('unit_cost_this_grn');
                    var poUnitPrice = parseFloat(r.get('unit_price')) || 0;
                    r.set('unit_cost', (existingCost !== null && existingCost !== undefined && existingCost !== '') ? existingCost : poUnitPrice);
                });
                updateGrnTotalLabel();
            }
        }
    });

    var mainDs = new Ext.data.Store({
        url: '../php-crud/grnCRD.php',
        idProperty: 'id',
        baseParams: { task: 'read' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, grnField)
    });
    mainDs.load();

    // ------------------------------------------------------------
    // 2. Main Grid
    // ------------------------------------------------------------
    var GridDtlHeight = (maxGridHeight(document.getElementById('divMainGrid')));
    var mainHeight = Math.round(GridDtlHeight - (GridDtlHeight * 0.45));

    var gridMain = new stdGrid({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: mainHeight,
        columns: [
            { header: 'No. GRN', dataIndex: 'ref_no', width: 110, renderer: CellWrap },
            { header: 'Tanggal', dataIndex: 'grn_date', width: 90, renderer: Ext.util.Format.dateRenderer('d M Y') },
            { header: 'No. PO', dataIndex: 'po_ref_no', width: 110, renderer: CellWrap },
            { header: 'Supplier', dataIndex: 'supplier_name', width: 170, renderer: CellWrap },
            {
                header: 'Total Qty', dataIndex: 'total_qty', width: 100, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
            },
            { header: 'Mata Uang', dataIndex: 'currency_code', width: 80, align: 'center', renderer: function (v) { return v || '-'; } },
            {
                header: 'Total Nilai (Mata Uang PO)', dataIndex: 'total_amount', width: 150, align: 'right',
                renderer: function (v) { return '<b>' + Ext.util.Format.number(v || 0, '0,000.00') + '</b>'; }
            },
            {
                header: 'Total Nilai (Dasar)', dataIndex: 'total_base', width: 140, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            {
                header: 'Status', dataIndex: 'status', width: 110, align: 'center',
                renderer: function (v) {
                    if (v === 'draft') return '<span style="color:#e08e0b;font-weight:bold;">Draft</span>';
                    if (v === 'cancelled') return '<span style="color:#c0392b;font-weight:bold;">Cancelled</span>';
                    return '<span style="color:#2e9e4f;font-weight:bold;">Confirmed</span>';
                }
            },
            { header: 'Keterangan', dataIndex: 'remark', width: 160, renderer: CellWrap }
        ],
        listeners: {
            cellclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) {
                    dataRecord = rec.data;
                    mainBtnEnable('#deleteData');
                    editDataEnable();
                    loadGrnDetail(dataRecord.id, dataRecord.ref_no);
                }
            }
        }
    });
    editDataDisable();
    mainBtnDisable('#deleteData');

    // ------------------------------------------------------------
    // 3. Detail View Renderer
    // ------------------------------------------------------------
    function loadGrnDetail(grnId, grnNo) {
        Ext.Ajax.request({
            url: '../php-crud/grnCRD.php',
            method: 'POST',
            params: { task: 'read_grn_items', grn_id: grnId },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    grnDtlDiv.innerHTML = '<div style="color:yellow;">Gagal memuat detail GRN.</div>';
                    grnDtlDiv.style.display = 'block';
                    return;
                }
                renderGrnDetailView(res.data || [], grnNo, res.currency);
            },
            failure: function () {
                grnDtlDiv.innerHTML = '<div style="color:red;">Gagal terhubung ke server.</div>';
                grnDtlDiv.style.display = 'block';
            }
        });
    }

    function renderGrnDetailView(rows, grnNo, cur) {
        var fmtQty = function (v) { return Ext.util.Format.number(v || 0, '0,000.0000'); };
        var fmtAmt = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };

        var code = (cur && cur.code) || 'IDR', baseCode = (cur && cur.base_code) || 'IDR';
        var diff = code !== baseCode;
        var html = '<h3 class="detail-title">Detail Item GRN: ' + Ext.util.Format.htmlEncode(grnNo || '') + '</h3>' +
                   '<hr class="detail-hr" />' +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead>' +
                   '<tr class="header-row">' +
                   '<th align="left">Nama Item</th>' +
                    '<th style="text-align:right;">Qty Diterima</th>' +
                    '<th style="text-align:right;">Harga Satuan (' + Ext.util.Format.htmlEncode(code) + ')</th>' +
                    '<th style="text-align:right;">Subtotal (' + Ext.util.Format.htmlEncode(code) + ')</th>' +
                    (diff ? '<th style="text-align:right;">Subtotal Dasar (' + Ext.util.Format.htmlEncode(baseCode) + ')</th>' : '') +
                   '</tr>' +
                   '</thead>' +
                   '<tbody>';

        var grandTotal = 0, grandBase = 0;

        if (!rows || rows.length === 0) {
            html += '<tr><td colspan="5" class="empty-msg">Tidak ada item detail.</td></tr>';
        } else {
            Ext.each(rows, function (r) {
                var qty = parseFloat(r.qty) || 0;
                var cost = parseFloat(r.unit_price) || 0;
                var subtotal = qty * cost;
                var subBase = qty * (parseFloat(r.unit_cost_base) || 0);
                grandTotal += subtotal;
                grandBase += subBase;

                html += '<tr class="data-row">' +
                        '<td align="left">' + Ext.util.Format.htmlEncode(r.item_name || '-') + '</td>' +
                        '<td align="right" style="color:#4ade80; font-weight:bold;">' + fmtQty(qty) + '</td>' +
                        '<td align="right">' + fmtAmt(cost) + '</td>' +
                        '<td align="right" class="subtotal-val">' + fmtAmt(subtotal) + '</td>' +
                        (diff ? '<td align="right" style="color:#9fc3d1;">' + fmtAmt(subBase) + '</td>' : '') +
                        '</tr>';
            });
        }

        html += '</tbody>' +
                '<tfoot>' +
                '<tr class="total-row">' +
                '<td colspan="3" align="right" class="total-label">Total Nilai GRN:</td>' +
                '<td align="right" class="total-val">' + fmtAmt(grandTotal) + ' ' + Ext.util.Format.htmlEncode(code) + '</td>' +
                (diff ? '<td align="right" class="total-val">' + fmtAmt(grandBase) + ' ' + Ext.util.Format.htmlEncode(baseCode) + '</td>' : '') +
                '</tr>' +
                '</tfoot>' +
                '</table>';

        grnDtlDiv.innerHTML = html;
        grnDtlDiv.style.display = 'block';
    }

    // ------------------------------------------------------------
    // 4. Item Grid Editor
    // ------------------------------------------------------------
    function toleranceRenderer(v, meta, record) {
        var qtyOrdered = parseFloat(record.get('qty_ordered')) || 0;
        var qtyMax = parseFloat(record.get('qty_max_allowed')) || (qtyOrdered * 1.10);
        var qtyInput = parseFloat(v) || 0;

        var pctOfOrdered = qtyOrdered > 0 ? (qtyInput / qtyOrdered) * 100 : 0;
        var color = '#2e9e4f';
        if (qtyInput > qtyOrdered && qtyInput <= qtyMax) {
            color = '#e08e0b';
        } else if (qtyInput > qtyMax) {
            color = '#c0392b';
        }
        return '<b style="color:' + color + ';">' + Ext.util.Format.number(qtyInput, '0,000.0000') +
               '</b> <span style="color:#888;font-size:11px;">(' + pctOfOrdered.toFixed(0) + '% dari PO)</span>';
    }

    var itemGrid = new stdGridEditor({
        store: itemGridDs,
        renderTo: 'editorFormGrid',
        viewConfig: { forceFit: true, scrollOffset: 0 },
        height: 240,
        columns: [
            { header: 'Item', dataIndex: 'item_name', width: 180, renderer: CellWrap },
            {
                header: 'Qty PO', dataIndex: 'qty_ordered', width: 90, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
            },
            {
                header: 'Sisa Qty PO', dataIndex: 'sisa_qty_po', width: 90, align: 'right',
                renderer: function (v) { return '<b style="color:#38bdf8;">' + Ext.util.Format.number(v || 0, '0,000.0000') + '</b>'; }
            },
            {
                header: 'Batas +10%', dataIndex: 'qty_max_allowed', width: 90, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
            },
            {
                header: 'Qty Diterima', dataIndex: 'qty_input', width: 150, align: 'right',
                editor: new myNumberField({ decimalPrecision: 4 }),
                renderer: toleranceRenderer
            },
            {
                header: 'Harga Satuan', dataIndex: 'unit_cost', width: 110, align: 'right',
                editor: new myNumberField({ decimalPrecision: 2 }),
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            {
                header: 'Subtotal', dataIndex: 'qty_input', width: 130, align: 'right',
                renderer: function (v, meta, record) {
                    var subtotal = (parseFloat(record.get('qty_input')) || 0) * (parseFloat(record.get('unit_cost')) || 0);
                    return '<b>' + Ext.util.Format.number(subtotal, '0,000.00') + '</b>';
                }
            },
            {
                header: 'Subtotal Dasar', dataIndex: 'unit_cost', width: 130, align: 'right', hidden: true,
                renderer: function (v, meta, record) {
                    var base = (parseFloat(record.get('qty_input')) || 0) * (parseFloat(record.get('unit_cost')) || 0) * grnCur.rate;
                    return '<span style="color:#9fc3d1;">' + Ext.util.Format.number(base, '0,000.00') + '</span>';
                }
            }
        ],
        listeners: {
            afteredit: function (e) {
                if (e.field === 'qty_input') {
                    var qtyMax = parseFloat(e.record.get('qty_max_allowed')) || 0;
                    var qtyInput = parseFloat(e.value) || 0;
                    if (qtyInput > qtyMax) {
                        e.record.set('qty_input', qtyMax);
                        Ext.Msg.alert('Peringatan', 'Qty diterima melebihi batas toleransi 10% dari PO. Otomatis dibatasi ke ' + Ext.util.Format.number(qtyMax, '0,000.0000') + '.');
                    }
                    if (qtyInput < 0) {
                        e.record.set('qty_input', 0);
                    }
                }
                updateGrnTotalLabel();
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    // Judul kolom mengikuti mata uang PO; kolom "Subtotal Dasar" hanya tampil bila mata uang PO bukan mata uang dasar
    function applyCurrencyHeaders() {
        var cm = itemGrid.getColumnModel();
        var diff = grnCur.code !== grnCur.base;
        cm.setColumnHeader(5, 'Harga Satuan (' + grnCur.code + ')');
        cm.setColumnHeader(6, 'Subtotal (' + grnCur.code + ')');
        cm.setColumnHeader(7, 'Subtotal Dasar (' + grnCur.base + ')');
        cm.setHidden(7, !diff);
        setCurInfo();
        itemGrid.getView().refresh();
    }

    function setCurInfo() {
        if (!fldCurInfo) return;
        fldCurInfo.setValue('<b>' + Ext.util.Format.htmlEncode(grnCur.code) + '</b>' +
            (grnCur.code !== grnCur.base
                ? ' &nbsp;(kurs PO: 1 ' + Ext.util.Format.htmlEncode(grnCur.code) + ' = ' + Ext.util.Format.number(grnCur.rate, '0,000.0000') + ' ' + Ext.util.Format.htmlEncode(grnCur.base) + ')'
                : ' &nbsp;(mata uang dasar)'));
    }

    function updateGrnTotalLabel() {
        var total = 0;
        itemGridDs.each(function (r) {
            var qty = parseFloat(r.get('qty_input')) || 0;
            var cost = parseFloat(r.get('unit_cost')) || 0;
            total += qty * cost;
        });
        var el = document.getElementById('lblGrnTotal');
        if (el) {
            var html = Ext.util.Format.number(total, '0,000.00') + ' ' + Ext.util.Format.htmlEncode(grnCur.code);
            if (grnCur.code !== grnCur.base) {
                html += ' <span style="color:#9fc3d1; font-weight:normal;">&asymp; ' + Ext.util.Format.number(total * grnCur.rate, '0,000.00') + ' ' + Ext.util.Format.htmlEncode(grnCur.base) + '</span>';
            }
            el.innerHTML = html;
        }
    }

    // ------------------------------------------------------------
    // 5. Editor Form
    // ------------------------------------------------------------
    var fldRefNo = new myTextField({ fieldLabel: 'No. GRN', id: 'ref_no', name: 'ref_no', readOnly: true, emptyText: 'Auto-generate' });
    var fldPoHdr = new myComboField({
        fieldLabel: 'Purchase Order', id: 'po_id', name: 'po_id', hiddenName: 'po_id',
        store: poDs, displayField: 'ref_no', valueField: 'id', allowBlank: false,
        listeners: {
            select: function (combo, record) {
                Ext.getCmp('supplier_id_hidden') && Ext.getCmp('supplier_id_hidden').setValue(record.get('supplier_id'));
                grnCur = { code: record.get('currency_code') || grnCur.base, base: grnCur.base, rate: parseFloat(record.get('exch_rate')) || 1 };
                setCurInfo();
                itemGridDs.load({
                    params: {
                        po_id: record.get('id'),
                        grn_id: (task === 'update' && dataRecord) ? dataRecord.id : 0
                    }
                });
            }
        }
    });
    var fldCurInfo = new Ext.form.DisplayField({ fieldLabel: 'Mata Uang PO', value: '-' });
    var fldGrnDate = new myDateField({ fieldLabel: 'Tgl GRN', id: 'grn_date', name: 'grn_date', allowBlank: false, value: new Date() });
    var fldSupplierIdHidden = new myTextField({ id: 'supplier_id_hidden', name: 'supplier_id', hidden: true });
    var fldRemark = new myTextArea({ fieldLabel: 'Keterangan', id: 'remark', name: 'remark' });

    var frmEditor = new stdForm({
        id: 'frmEditor',
        renderTo: 'editorFormMain',
        labelWidth: 120,
        defaults: { xtype: 'container', layout: 'form', columnWidth: 0.5, baseCls: 'x-plain' },
        items: [
            { columnWidth: .333, items: fldRefNo },
            { columnWidth: .333, items: fldGrnDate },
            { columnWidth: .333, items: fldPoHdr },
            { columnWidth: 1, items: fldCurInfo },
            { columnWidth: 1, items: fldRemark },
            { items: fldSupplierIdHidden }
        ]
    });

    document.getElementById('addData').onclick = function () {
        if (getFormAccess() >= 2) {
            task = 'create';
            frmEditor.getForm().reset();
            itemGridDs.removeAll();
            grnCur = { code: 'IDR', base: grnCur.base, rate: 1 };
            grnCur.code = grnCur.base;
            applyCurrencyHeaders();
            poDs.load(); // Reload PO outstanding
            updateGrnTotalLabel();
            fldPoHdr.setDisabled(false);
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;GRN Baru';
        }
    };

    document.getElementById('editData').onclick = function () {
        task = '';
        if (!dataRecord) {
            Ext.Msg.alert('Peringatan', 'Pilih data GRN yang ingin diedit.');
            return;
        }

        if (getFormAccess() >= 3) {
            frmEditor.getForm().reset();
            task = 'update';

            if (!poDs.getById(dataRecord.po_id)) {
                var PoRecord = Ext.data.Record.create(poField);
                poDs.add(new PoRecord({
                    id: dataRecord.po_id,
                    ref_no: dataRecord.po_ref_no + ' (sudah selesai diterima)',
                    supplier_id: dataRecord.supplier_id,
                    supplier_name: dataRecord.supplier_name,
                    currency_code: dataRecord.currency_code,
                    exch_rate: dataRecord.exch_rate
                }, dataRecord.po_id));
            }

            frmEditor.getForm().load({
                url: '../php-crud/grnCRD.php',
                params: { id: dataRecord.id, task: 'read_edit' },
                success: function (form, action) {
                    fldPoHdr.setDisabled(true);
                    itemGridDs.load({
                        params: {
                            po_id: dataRecord.po_id,
                            grn_id: dataRecord.id
                        },
                        callback: function () {
                            updateGrnTotalLabel();
                        }
                    });
                }
            });

            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Edit GRN';
        } else {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk mengedit.');
        }
    };

    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) {
            Ext.Msg.alert('Peringatan', 'Pilih data GRN yang ingin dihapus.');
            return;
        }
        if (getFormAccess() >= 4) {
            Ext.Msg.confirm('Konfirmasi Hapus', 'Hapus GRN \'' + dataRecord.ref_no + '\'? Jurnal & status outstanding PO akan dibatalkan otomatis.', function (btn) {
                if (btn === 'yes') {
                    Ext.Ajax.request({
                        url: '../php-crud/grnCRD.php',
                        method: 'POST',
                        params: { task: 'delete', id: dataRecord.id },
                        success: function (response) {
                            var res = Ext.util.JSON.decode(response.responseText);
                            Ext.Msg.alert('Informasi', res.message);
                            grnDtlDiv.style.display = 'none';
                            mainDs.load();
                        },
                        failure: function () {
                            Ext.Msg.alert('Gagal', 'Gagal menghubungi server.');
                        }
                    });
                }
            });
        } else {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses menghapus data.');
        }
    };

    document.getElementById('saveEdit').onclick = function () {
        var poHdrId = fldPoHdr.getValue();
        if (!poHdrId) {
            Ext.Msg.alert('Peringatan', 'Pilih Purchase Order terlebih dahulu.');
            return;
        }

        var arrItems = [];
        itemGridDs.each(function (r) {
            var qty = parseFloat(r.get('qty_input')) || 0;
            if (qty > 0) {
                arrItems.push({
                    po_dtl_id: r.get('po_dtl_id'),
                    item_id: r.get('item_id'),
                    item_name: r.get('item_name'),
                    qty_received: qty,
                    unit_cost: parseFloat(r.get('unit_cost')) || 0,
                    remark: ''
                });
            }
        });

        if (arrItems.length === 0) {
            Ext.Msg.alert('Peringatan', 'Minimal 1 item dengan qty diterima > 0.');
            return;
        }

        var currentId = (task === 'update' && dataRecord) ? dataRecord.id : null;
        var supplierId = fldSupplierIdHidden.getValue();
        if (!supplierId && task === 'update' && dataRecord) {
            supplierId = dataRecord.supplier_id;
        }

        frmEditor.getForm().submit({
            url: '../php-crud/grnCRD.php',
            method: 'POST',
            params: {
                task: task,
                id: currentId,
                supplier_id: supplierId,
                arrItems: JSON.stringify(arrItems)
            },
            waitTitle: 'Menyimpan',
            waitMsg: 'Memproses GRN & auto-jurnal...',
            success: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                Ext.Msg.alert('Sukses', res.message);
                grnDtlDiv.style.display = 'none';
                mainDs.load();
                document.getElementById('editorWdw').style.display = 'none';
            },
            failure: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                Ext.Msg.alert('Gagal', res.message || 'Terjadi kesalahan sistem.');
            }
        });
    };

    document.getElementById('editorWdw').style.display = 'none';
    document.getElementById('grnDtlContainer').style.display = 'none';
});