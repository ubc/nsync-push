var Nsync_Post_To = {
	
	onReady : function() {
		
		var conf = {
			over: Nsync_Post_To.show,    
     		timeout: 500, // number = milliseconds delay before onMouseOut    
     		out: Nsync_Post_To.hide 
		}
		jQuery("#shell-site-to-post").hoverIntent( conf );
		jQuery("#site-to-post input").change(Nsync_Post_To.update)
		Nsync_Post_To.update();
	},
	
	show : function() {
		jQuery("#site-to-post").slideDown('fast');
	},
	hide : function() {
		jQuery("#site-to-post").slideUp('fast');
	},
	update : function() {
		var html = new Array(); 
		jQuery("#site-to-post input:checked").each(function() {
			html.push( jQuery(this).attr('alt') );
		});
		if( html.length == 0){
			jQuery("#site-display").html( "(select a site)" );
		} else {
			jQuery("#site-display").html( "<strong>"+html.join(', ')+"</strong>" );
		}
		
	
	}
}
jQuery( document ).ready( Nsync_Post_To.onReady );