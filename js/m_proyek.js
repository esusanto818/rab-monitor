/*
  Document      : m_proyek.js
  Description   : ExtJS Controller untuk pengelolaan Master Proyek
  Backend       : /proyek/php-crud/m.proyekCRD.php
  Perubahan     : - [BARU] field Budget, Nilai Kontrak, Progress di form
                  - [BARU] handler failure & exception store (pesan error eksplisit)
                  - [FIX PIC] daftar PIC kini dari m_employee (diatur di backend)
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    if (document.getElementById("mainHeaderText")) {
        document.getElementById("mainHeaderText").innerHTML = "Master Data <em>Proyek & Kontrak</em>";
    }

    var CRD = '../php-crud/m.proyekCRD.php';
    var task = '';
    var dataRecord = null;

    // [BARU] listener exception untuk semua store
    function storeException(proxy, type, action, options, response) {
        var msg = 'Gagal memuat data dari server.';
        try {
            var r = Ext.util.JSON.decode(response.responseText);
            if (r && r.message) msg = r.message;
        } catch (e) { msg += ' (respons bukan JSON, cek error_log PHP)'; }
        Ext.Msg.alert('Gagal', msg);
    }

    // 1. Stores
    var clientDs = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_clients' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [
            { name: 'id' }, { name: 'name' }
        ]),
        listeners: { exception: storeException }
    });
    clientDs.load();

    var picDs = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_pics' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [
            { name: 'id' }, { name: 'name' }
        ]),
        listeners: { exception: storeException }
    });
    picDs.load();

    var locationDs = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_locations' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [
            { name: 'id' }, { name: 'name' }
        ]),
        listeners: { exception: storeException }
    });
    locationDs.load();

    var statusDs = new Ext.data.SimpleStore({
        fields: ['id', 'name'],
        data: [
            ['Belum Mulai', 'Belum Mulai'],
            ['Berjalan', 'Berjalan'],
            ['Tertunda', 'Tertunda'],
            ['Selesai', 'Selesai']
        ]
    });

    var mainFields = [
        { name: 'id' }, { name: 'name' }, { name: 'client_id' }, { name: 'client_name' },
        { name: 'pic_id' }, { name: 'pic_name' }, { name: 'location_id' }, { name: 'location_name' }, { name: 'location' },
        { name: 'start_date', type: 'date', dateFormat: 'Y-m-d' },
        { name: 'end_date', type: 'date', dateFormat: 'Y-m-d' },
        { name: 'budget', type: 'float' }, { name: 'contract_value', type: 'float' },   // [BARU] contract_value
        { name: 'spent', type: 'float' },
        { name: 'progress', type: 'int' }, { name: 'status' }, { name: 'notes' }
    ];

    mainDs = new Ext.data.Store({
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'read' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, mainFields),
        listeners: { exception: storeException }
    });
    mainDs.load();

    // 2. Main Grid
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: 450,
        columns: [
            { header: 'Nama Proyek', dataIndex: 'name', width: 200, renderer: CellWrap },
            { header: 'Klien', dataIndex: 'client_name', width: 160, renderer: CellWrap },
            { header: 'PIC / PM', dataIndex: 'pic_name', width: 140, renderer: CellWrap },
            { header: 'Lokasi', dataIndex: 'location_name', width: 140, renderer: CellWrap },
            { header: 'Tgl Mulai', dataIndex: 'start_date', width: 90, renderer: Ext.util.Format.dateRenderer('d/m/Y') },
            { header: 'Tgl Selesai', dataIndex: 'end_date', width: 90, renderer: Ext.util.Format.dateRenderer('d/m/Y') },
            {
                header: 'Plafon Budget', dataIndex: 'budget', width: 130, align: 'right',
                renderer: function (v) { return 'Rp ' + Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            {   // [BARU] kolom nilai kontrak
                header: 'Nilai Kontrak', dataIndex: 'contract_value', width: 130, align: 'right',
                renderer: function (v) { return 'Rp ' + Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            { header: 'Progress (%)', dataIndex: 'progress', width: 90, align: 'center', renderer: function(v){ return (v || 0) + '%'; } },
            { header: 'Status', dataIndex: 'status', width: 100, align: 'center' }
        ],
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) {
                    dataRecord = rec.data;
                    mainBtnEnable('#deleteData');
                    editDataEnable();
                }
            }
        }
    });

    // 3. Form Input/Edit
    var fldId = new myTextField({ name: 'id', id: 'id', hidden: true });
    var fldName = new myTextField({ fieldLabel: 'Nama Proyek', id: 'name', name: 'name', allowBlank: false, width: 220 });
    var fldClient = new myComboField({ fieldLabel: 'Klien', id: 'client_id', name: 'client_id', hiddenName: 'client_id', store: clientDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true, width: 220 });
    var fldPic = new myComboField({ fieldLabel: 'PIC Proyek', id: 'pic_id', name: 'pic_id', hiddenName: 'pic_id', store: picDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true, width: 220 });
    var fldLocation = new myComboField({ fieldLabel: 'Lokasi', id: 'location_id', name: 'location_id', hiddenName: 'location_id', store: locationDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true, width: 220 });
    var fldStartDate = new Ext.form.DateField({ fieldLabel: 'Tgl Mulai', id: 'start_date', name: 'start_date', format: 'Y-m-d', width: 140 });
    var fldEndDate = new Ext.form.DateField({ fieldLabel: 'Tgl Selesai', id: 'end_date', name: 'end_date', format: 'Y-m-d', width: 140 });
    var fldStatus = new myComboField({ fieldLabel: 'Status', id: 'status', name: 'status', hiddenName: 'status', store: statusDs, displayField: 'name', valueField: 'id', value: 'Berjalan' });
    // [BARU] field angka yang sebelumnya tidak ada di form
    var fldBudget = new myNumberField({ fieldLabel: 'Plafon Budget', id: 'budget', name: 'budget', decimalPrecision: 2, value: 0, width: 140 });
    var fldContract = new myNumberField({ fieldLabel: 'Nilai Kontrak', id: 'contract_value', name: 'contract_value', decimalPrecision: 2, value: 0, width: 140 });
    var fldProgress = new myNumberField({ fieldLabel: 'Progress (%)', id: 'progress', name: 'progress', decimalPrecision: 0, minValue: 0, maxValue: 100, value: 0, width: 80 });
    var fldNotes = new Ext.form.TextArea({
        fieldLabel: 'Catatan',
        id: 'notes',
        name: 'notes',
        anchor: '100%',
        height: 50 });

    var frmEditor = new stdForm({
        id: 'frmEditor',
        renderTo: 'editorFormMain',
        labelWidth: 100,
        defaults: { xtype: 'container', layout: 'form', columnWidth: 0.5, baseCls: 'x-plain' },
        items: [
            { items: [fldId, fldName, fldClient, fldPic, fldLocation, fldNotes] },
            { items: [
                fldStartDate,
                fldEndDate,
                fldStatus,
                fldBudget,      // [BARU]
                fldContract,    // [BARU]
                fldProgress,    // [BARU]
                { columnWidth: 1, items: FieldSpace }
            ] }
        ]
    });

    // 4. Action Handlers
    document.getElementById('addData').onclick = function () {
        task = 'create';
        frmEditor.getForm().reset();
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Tambah Proyek Baru';
    };

    document.getElementById('editData').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih proyek terlebih dahulu.'); return; }
        task = 'update';
        frmEditor.getForm().reset();
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Edit Master Proyek';
        frmEditor.getForm().load({
            url: CRD,
            params: { id: dataRecord.id, task: 'read_one' },
            success: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                var d = res.data || {};
                fldClient.setValue(d.client_id);
                fldPic.setValue(d.pic_id);
                fldLocation.setValue(d.location_id);
                fldStatus.setValue(d.status);
                fldBudget.setValue(d.budget);               // [BARU]
                fldContract.setValue(d.contract_value);     // [BARU]
                fldProgress.setValue(d.progress);           // [BARU]
            },
            failure: function () {
                Ext.Msg.alert('Gagal', 'Gagal memuat data proyek.');
            }
        });
    };

    document.getElementById('saveEdit').onclick = function () {
        if (!frmEditor.getForm().isValid()) return;
        frmEditor.getForm().submit({
            url: CRD,
            method: 'POST',
            params: { task: task },
            success: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                document.getElementById('popWdwTxt').innerHTML = res.message;
                document.getElementById('popWdw').style.display = 'block';
                mainDs.load({ params: { task: 'read' } });
                document.getElementById('editorWdw').style.display = 'none';
            },
            failure: function (form, action) {   // [BARU] tampilkan pesan error dari backend
                var msg = (action.result && action.result.message) ? action.result.message : 'Gagal menyimpan data (cek error_log PHP).';
                Ext.Msg.alert('Gagal', msg);
            }
        });
    };

    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih proyek terlebih dahulu.'); return; }
        Ext.Msg.confirm('Konfirmasi', 'Hapus proyek ini?', function (btn) {
            if (btn === 'yes') {
                Ext.Ajax.request({
                    url: CRD,
                    params: { task: 'delete', id: dataRecord.id },
                    success: function (response) {
                        var res;
                        try { res = Ext.util.JSON.decode(response.responseText); }
                        catch (e) { Ext.Msg.alert('Gagal', 'Respons server tidak valid (cek error_log PHP).'); return; }
                        document.getElementById('popWdwTxt').innerHTML = res.message;
                        document.getElementById('popWdw').style.display = 'block';
                        if (res.success) { dataRecord = null; }
                        mainDs.load({ params: { task: 'read' } });
                    },
                    failure: function () { Ext.Msg.alert('Gagal', 'Gagal menghubungi server.'); }
                });
            }
        });
    };

    document.getElementById('popOK').onclick = function () {
        document.getElementById('popWdw').style.display = 'none';
        if (document.getElementById('popCancel')) {
            document.getElementById('popCancel').style.display = 'none';
        }
    };

    document.getElementById('editorWdw').style.display = 'none';

});