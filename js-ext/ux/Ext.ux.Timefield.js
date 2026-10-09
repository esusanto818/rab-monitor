/*!
 * Ext JS Library 3.1.0
 * Copyright(c) 2006-2009 Ext JS, LLC
 * licensing@extjs.com
 * http://www.extjs.com/license
 */
Ext.ns('Ext.ux');

/**
 * @class Ext.ux.form.SpinnerField
 * @extends Ext.form.NumberField
 * Creates a field utilizing Ext.ux.Spinner
 * @xtype spinnerfield
 */
/**
 * @modified by Mitchell Simoens
 * This modification was to allow the Ext.ux.form.SpinnerField to allow
 * a string. To accomplish, all I had to do was change the extended component.
 * Also change the Extension name to not create confusion.
 */
Ext.ux.TimeFieldPicker = Ext.extend(Ext.form.TextField, {
    actionMode: 'wrap',
    deferHeight: true,
    autoSize: Ext.emptyFn,
    onBlur: Ext.emptyFn,
    task: Ext.emptyFn,
    caretPos: null,
    adjustSize: Ext.BoxComponent.prototype.adjustSize,
    updaterTask: new Ext.util.DelayedTask(function() {
    	updateTime();
    }),
    /*
     * 
     * clockCfg is from curlybracket's example
     * 
     */
    clockCfg: {
		outerArcState: {
			strokeStyle:		'#B5B8C8',
			lineWidth:			1,
			alpha:				0.8
		},
		hourIndicatorState: {
			strokeStyle:		'#15428B',
			lineWidth:			3
		}
	},

	constructor: function(config) {
		var spinnerConfig = Ext.copyTo({}, config, 'incrementValue,alternateIncrementValue,accelerate,defaultValue,triggerClass,splitterClass');

		var spl = this.spinner = new Ext.ux.Spinner(spinnerConfig);
		
		var now = new Date();
		
		config.time = now.getTime();
		config.enableKeyEvents = true;
		
		/*
     	* 
     	* This creates an instance of curlybracket's clock
     	* If you do not specify clockRenderTo, it will not create a clock
     	* IE and Safari seem not to support this yet. Go FF & Chrome!
     	* 
     	*/
		if (!Ext.isIE && !Ext.isSafari) {
		  	if (config.clockRenderTo) {
		  		this.clock = new Ext.ux.Clock({
		  			renderTo: config.clockRenderTo,
		  			date: now,
		  			clockCfg: Ext.apply(this.clockCfg, {
		  				runTask: false
		  			})
		  		});
		  	}
		  }

		var plugins = config.plugins
			? (Ext.isArray(config.plugins)
				? config.plugins.push(spl)
				: [config.plugins, spl])
			: spl;

		Ext.ux.TimeFieldPicker.superclass.constructor.call(this, Ext.apply(config, {plugins: plugins}));

		this.on("render", this.doRender, this);
	},
	
	doRender: function() {
		this.el.on("blur", this.doBlur, this);
		this.el.on("mouseup", this.doMouseUp, this);
		
		this.start();
	},
	
	doFocus: function() {
		this.caretPos = this.getCaretPos();
	},
	
	doBlur: function() {
		this.caretPos = null;
	},
	
	doMouseUp: function() {
		this.caretPos = this.getCaretPos();
	},
	
	updateTime: function() {
		var d = new Date(this.time+1000);
		this.time = d.getTime();
		this.setValue(d.getHours()+":"+d.getMinutes()+":"+d.getSeconds());
	},
	
	stop: function() {
		Ext.TaskMgr.stop(this.task);
	},
	
	start: function() {
		this.task = Ext.TaskMgr.start({
			run:		this.updateTime,
			scope:		this,
			interval:	1000
		});
	},
	
	isAM: function() {
		var hour = new Date(this.time).getHours();
		return (hour < 12) ? true: false;
	},
	
	isPM: function() {
		var hour = new Date(this.time).getHours();
		return (hour >= 12) ? true: false;
	},
	
	getValue: function(format) {
		if (!format) {
			if (this.twentyFour) {
				format = "H:i:s";
			} else {
				format = "h:i:s a";
			}
		}
		return new Date(this.time).format(format);
	},
	
	setValue : function(v) {
		this.fireEvent("beforeupdate");
		var d = new Date(this.time);
		if (this.clock) {
			d = new Date(d.getMonth() + " " + d.getDate() + " " + d.getYear() + " " + v);
			this.clock.setDate(d);
		}
		v = d.format((this.twentyFour) ? "H:i:s" : "h:i:s a");
		if(this.emptyText && this.el && !Ext.isEmpty(v)){
			this.el.removeClass(this.emptyClass);
		}
		Ext.form.TextField.superclass.setValue.apply(this, arguments);
		this.applyEmptyText();
		this.autoSize();
		if (this.caretPos) {
			this.setCaretTo(this.caretPos);
		}
		this.fireEvent("update");
		return this;
	},
	
	setCaretTo: function(pos) {
		var obj = document.getElementById(this.id); 
		if(obj.createTextRange) { 
			var range = obj.createTextRange(); 
			range.move("character", pos); 
			range.select(); 
		} else if(obj.selectionStart) { 
			obj.focus(); 
			obj.setSelectionRange(pos, pos); 
		} 
	},
    
    getCaretPos: function() {
    	var el = document.getElementById(this.id);
    	   var rng, ii=-1;
		if(typeof el.selectionStart=="number") {
			ii=el.selectionStart;
		} else if (document.selection && el.createTextRange){
			rng=document.selection.createRange();
			rng.collapse(true);
			rng.moveStart("character", -el.value.length);
			ii=rng.text.length;
		}
		return ii;
    },

    // private
    getResizeEl: function(){
        return this.wrap;
    },

    // private
    getPositionEl: function(){
        return this.wrap;
    },

    // private
    alignErrorIcon: function(){
        if (this.wrap) {
            this.errorIcon.alignTo(this.wrap, 'tl-tr', [2, 0]);
        }
    },

    validateBlur: function(){
        return true;
    }
});

Ext.reg('timefieldpicker', Ext.ux.TimeFieldPicker);

//backwards compat
Ext.TimeFieldPicker = Ext.ux.TimeFieldPicker;
