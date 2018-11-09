(function($) {
	$(document).ready(function() {
		jQuery('table#field-form-jeux-values tbody tr, table#field-form-operation-values tbody tr, table#field-form-recrutement-values tbody tr').each( function( cmp ) {
			if (jQuery(this).find('.form-select').val() != 'select') {
				textarea = jQuery(this).closest(".draggable").find('textarea')
				textarea.closest('.form-type-textarea').hide()
			}
		} );
		$( "body" ).delegate( ".form-select", "change", function() {
			textarea = jQuery(this).closest(".draggable").find('textarea')
			if (jQuery(this).val() == "select") {
				textarea.closest('.form-type-textarea').show()
			} else {
				textarea.closest('.form-type-textarea').hide()
			}
		})
		/*jQuery('.field-add-more-submit').mousedown(function(event) {
			console.log(event.target.id)
			if (event.target.id == 'edit-field-form-operation-add-more') {
				if (jQuery(this).find('.form-select').val() != 'select') {
					textarea = jQuery(this).closest(".draggable").find('textarea')
					console.log(textarea)
					if (textarea.val() == '') {
						textarea.closest('.form-type-textarea').hide()
					}
				}
			}
		});*/
	})
})(jQuery)