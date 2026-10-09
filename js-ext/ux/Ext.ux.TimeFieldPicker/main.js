Ext.onReady(function() {
	Ext.QuickTips.init();
	
	new Ext.FormPanel({
		renderTo: "testTimeField",
		items: [
			new Ext.ux.TimeFieldPicker({
				fieldLabel: "12 Hour",
				id: "testfield",
				clockRenderTo: "testTimeFieldClock"
			}),
			new Ext.ux.TimeFieldPicker({
				fieldLabel: "24 Hour",
				id: "testfield1",
				twentyFour: true,
				clockRenderTo: "testTimeFieldClock"
			}),
			new Ext.Button({
				text: "Is AM? - 12 Hour Clock",
				handler: function() {
					alert(Ext.getCmp("testfield").isAM());
				}
			}),
			new Ext.Button({
				text: "Is AM? - 24 Hour Clock",
				handler: function() {
					alert(Ext.getCmp("testfield1").isAM());
				}
			}),
			new Ext.Button({
				text: "Is PM? - 12 Hour Clock",
				handler: function() {
					alert(Ext.getCmp("testfield").isPM());
				}
			}),
			new Ext.Button({
				text: "Is PM? - 24 Hour Clock",
				handler: function() {
					alert(Ext.getCmp("testfield1").isPM());
				}
			}),
			new Ext.Button({
				text: "Stop - 24 Hour Clock",
				handler: function() {
					Ext.getCmp("testfield1").stop();
				}
			}),
			new Ext.Button({
				text: "Start - 24 Hour Clock",
				handler: function() {
					Ext.getCmp("testfield1").start();
				}
			})
		]
	});
	
	new Ext.Panel({
		renderTo: "testTimeFieldValues",
		title: "Time Format Values (only changed at first load)",
		width: 800,
		layout: "form",
		defaultType: "textfield",
		labelWidth: 300,
		items: [{
			fieldLabel: "a (Lowercase am/pm)",
			name: "format-a",
			anchor: "100%",
			value: Ext.getCmp("testfield").getValue("a")
		},{
			fieldLabel: "A (Uppercase am/pm)",
			name: "format-A",
			anchor: "100%",
			value: Ext.getCmp("testfield").getValue("A")
		},{
			fieldLabel: "g (12-Hour format of hours without leading zero)",
			name: "format-g",
			anchor: "100%",
			value: Ext.getCmp("testfield").getValue("g")
		},{
			fieldLabel: "G (-Hour format of hours without leading zero)",
			name: "format-G",
			anchor: "100%",
			value: Ext.getCmp("testfield").getValue("G")
		},{
			fieldLabel: "h (12-Hour format of hours with leading zero)",
			name: "format-h",
			anchor: "100%",
			value: Ext.getCmp("testfield").getValue("h")
		},{
			fieldLabel: "H (24-Hour format of hours with leading zero)",
			name: "format-H",
			anchor: "100%",
			value: Ext.getCmp("testfield").getValue("H")
		},{
			fieldLabel: "i (Minutes with leading zero)",
			name: "format-i",
			anchor: "100%",
			value: Ext.getCmp("testfield").getValue("i")
		},{
			fieldLabel: "s (Seconds with leading zero)",
			name: "format-s",
			anchor: "100%",
			value: Ext.getCmp("testfield").getValue("s")
		},{
			fieldLabel: "u (Microseconds)",
			name: "format-u",
			anchor: "100%",
			value: Ext.getCmp("testfield").getValue("u")
		},{
			fieldLabel: "O (Difference to Greenwich time in hours)",
			name: "format-O",
			anchor: "100%",
			value: Ext.getCmp("testfield").getValue("O")
		},{
			fieldLabel: "P (Difference to Greenwich time with colon between hours and minutes)",
			name: "format-P",
			anchor: "100%",
			value: Ext.getCmp("testfield").getValue("P")
		},{
			fieldLabel: "T (Timezone abbreviation)",
			name: "format-T",
			anchor: "100%",
			value: Ext.getCmp("testfield").getValue("T")
		},{
			fieldLabel: "Z (Timezone offset in seconds)",
			name: "format-Z",
			anchor: "100%",
			value: Ext.getCmp("testfield").getValue("Z")
		},{
			fieldLabel: "c (ISO 8601 date)",
			name: "format-c",
			anchor: "100%",
			value: Ext.getCmp("testfield").getValue("c")
		},{
			fieldLabel: "U (Seconds since the Unix Epoch)",
			name: "format-U",
			anchor: "100%",
			value: Ext.getCmp("testfield").getValue("U")
		}]
	});
});