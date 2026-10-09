<script type="text/javascript">
	Ext.onReady(function() {
		Ext.QuickTips.init();
    var UserCurrId = '<?php echo $_SESSION["user_id"] ?>';
    document.getElementById("mainHeaderText").innerHTML = "Change <em>Password</em> ";
		document.getElementById('saveEdit').onclick = function(event) {
			Ext.getCmp('frmEditor').getForm().submit({
				method:'POST',
				params: {task: 'update'},
				waitTitle:'Connecting',
				waitMsg:'Sending data...',
				success:function(form,action){
					var objResponse=Ext.util.JSON.decode(action.response.responseText);
					var messagesStatus=objResponse.status;
					var messages=objResponse.message;
					/*document.getElementById("popWdwMainHeaderText").innerHTML=messagesStatus;*/
					document.getElementById("popWdwTxt").innerHTML=messages;
					document.getElementById('popWdw').style.display='block';

					window.close();
				},
				failure:function(form, action){
					var objResponse=Ext.util.JSON.decode(action.response.responseText);
					var messagesStatus=objResponse.status;
					var messages=objResponse.message;
					/*document.getElementById("popWdwMainHeaderText").innerHTML=messagesStatus;*/
					document.getElementById("popWdwTxt").innerHTML=messages;
					document.getElementById('popWdw').style.display='block';
				}
			});
		};

		var myMask = new Ext.LoadMask(Ext.getBody(), {msg:"Please wait..."});
		var employeeFields =[
			{name: 'id', type:'numeric'},
			{name:'login_name'},
			{name:'employee_id'},
			{name: 'name', type:'string', convert : function(v, rec) {return rec.employee_name;}},
			{name:'employee_name'},
			{name:'department_name'},
			{name:'password'},
			{name:'confirm_password'}
		];
		//Editor Form
    var frmEditor = new stdForm({
      url: '../php-crud/change.pass.php',
      id: 'frmEditor',
      reader: new Ext.data.JsonReader({root : 'data',fields: employeeFields}),
      renderTo: 'editorFormMain',
      items: [
        {columnWidth:1, items:RowSpace},
        {columnWidth: 0.4, items: new myTextField({fieldLabel:'Name', id:'name', name:'name', readOnly: true})},
        {columnWidth: 0.4, items: new myTextField({fieldLabel:'Department', id:'department_name', name:'department_name', readOnly: true})},
        {columnWidth: 0.2, items:RowSpace},
        {columnWidth: 0.33, items: new myTextField({fieldLabel:'Login Name', id:'login_name', name:'login_name',autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
        {columnWidth: 0.33, items: new myTextField({
          fieldLabel: 'Password', id: 'newpwd', name: 'password', inputType: 'password',
          autoCreate: {tag: "input", maxlength: 100, type: "text", size: "100", autocomplete: "off"},
          minLength: 4, maxLength: 50, minLengthText: 'Password must be at least 4 characters long.', maskRe: /^([a-zA-Z0-9])/,
          validator: function(value){
            if(Ext.getCmp('confpwd').getValue() == value && Ext.getCmp('confpwd').getValue()!=='' && value!=='') {
              var proyekalidIconClass = document.getElementById('newpwd');
              if (proyekalidIconClass.parentElement.lastChild.className=='x-form-proyekalid-icon') {
                  proyekalidIconClass.parentElement.lastChild.style.visibility = 'hidden';
                  return true;
                }
            } else {
              return 'Passwords not match';
            }
          }
        })},
        {columnWidth: 0.33, items: new myTextField({
          fieldLabel: 'Conf. Password', id: 'confpwd', name: 'confirm_password', inputType: 'password',
          autoCreate: {tag: "input", maxlength: 100, type: "text", size: "100", autocomplete: "off"},
          minLength: 4, maxLength: 50, minLengthText: 'Password must be at least 4 characters long.', maskRe: /^([a-zA-Z0-9])/,
          vtype: 'password', initialPassField: 'newpwd',
          validator: function(value){
            if(Ext.getCmp('newpwd').getValue() == value && Ext.getCmp('newpwd').getValue()!=='' && value!=='') {
              var proyekalidIconClass = document.getElementById('confpwd');
              if (proyekalidIconClass.parentElement.lastChild.className=='x-form-proyekalid-icon') {
                proyekalidIconClass.parentElement.lastChild.style.visibility = 'hidden';
                return true;
              }
            } else {
              return 'Passwords not match';
            }
          }
        })},
        {columnWidth:1, items:FieldSpaceTopLabel},
        {items: new myTextField({name: 'employee_id',id:'employee_id', hidden: true})}
      ]
    });
    frmEditor.on('clientvalidation', function(f, valid) {if (!valid) {saveEditDisable();} else {saveEditEnable();}});

    Ext.getCmp('frmEditor').getForm().load({url:'../php-crud/change.pass.php', params:{id: UserCurrId, task: 'read_edit'}, success:function(form, action) {}});

    document.getElementById('mainHeader').style.display='none';
    document.getElementById('editorWdw').style.display='block';
    document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;Change Password";
    function editWdwClose(){window.close();}
	});
</script>
