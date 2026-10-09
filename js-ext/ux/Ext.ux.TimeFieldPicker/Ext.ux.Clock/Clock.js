/**
 * @class Ext.ux.Clock
 * @extends Ext.ux.Canvas
 */
/**
 * @constructor
 * @param Object
 */
Ext.ux.Clock=function(config) {
	if(config.size) {
		this.size=config.size;
	}
	config.width=config.height=this.size;
	Ext.ux.Clock.superclass.constructor.call(this, config);
}
Ext.extend(Ext.ux.Clock, Ext.ux.Canvas, {
	/**
	 * @var Object Configurations for the clock controller
	 */
	clockCfg: {},
	/**
	 * @var Ext.ux.ClockController The controller instance for the clock
	 */
	clock: null,
	/**
	 * @var integer Size for this component
	 */
	size: 60,
	// private
	afterRender: function() {
		Ext.ux.Clock.superclass.afterRender.apply(this, arguments);
		
		var r=this.size/2;
		var cfg=Ext.apply({
			canvas: this.ctx,
			x: r,
			y: r,
			size: r-1
		}, this.clockCfg);
		
		if(this.date!==null) {
			cfg.date=this.date;
		}
		
		this.clock=new Ext.ux.ClockController(cfg);
	},
	/**
	 * Returns the current date.
	 * 
	 * @return {Object}
	 */
	getDate: function() {
		return this.clock.date;
	},
	/**
	 * Sets the date to render.
	 * 
	 * @return void
	 * @param {Object}
	 */
	setDate: function(date) {
		this.clock.date=date;
		if(!this.clock.runTask && this.rendered) {
			this.clock.render();
		}
	}
});

Ext.reg('clock', Ext.ux.Clock);