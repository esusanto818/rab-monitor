Ext.ux.Canvas=function(config) {
	Ext.ux.Canvas.superclass.constructor.call(this, config);
};
Ext.extend(Ext.ux.Canvas, Ext.BoxComponent, {
	width: 300,
	height: 150,
	autoEl: 'canvas',
	ctx: null,
	// private
	afterRender: function() {
		this.ctx=new Curly.Canvas(this.el.dom);
		this.ctx.setDimensions([this.width, this.height]);
		
		Ext.ux.Canvas.superclass.afterRender.apply(this, arguments);
	}
});

Ext.reg('canvas', Ext.ux.Canvas);