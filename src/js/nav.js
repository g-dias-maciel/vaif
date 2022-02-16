$(document).ready( function() {
	
	$('.mobile-menu-toggle').on('click', function (){
		
		var visible = $('#main-menu').attr('data-visible');
		
		if (visible === 'true'){
			$('#main-menu').attr('data-visible', 'false');
			$('.mobile-menu-toggle').attr('aria-expanded', 'false');
		}else{
			$('#main-menu').attr('data-visible', 'true');
			$('.mobile-menu-toggle').attr('aria-expanded', 'true');
		}
		
	});
	
});



