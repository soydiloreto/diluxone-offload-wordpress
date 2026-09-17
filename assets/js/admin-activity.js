jQuery(document).ready(function($) {
	// Show/hide error details
	$('.show-error-details').on('click', function() {
		var $details = $(this).next('.error-details');
		$details.toggle();
		$(this).text($details.is(':visible') ? 
			DiluxOneOffloadActivity.i18n.hide_details : 
			DiluxOneOffloadActivity.i18n.show_details
		);
	});
	
	// Show/hide metadata
	$('.show-metadata').on('click', function() {
		var $metadata = $(this).next('.activity-metadata');
		$metadata.toggle();
		$(this).text($metadata.is(':visible') ? 
			DiluxOneOffloadActivity.i18n.hide : 
			DiluxOneOffloadActivity.i18n.view
		);
	});
	
	// Show full file path
	$('.show-full-path').on('click', function() {
		var $filePath = $(this).prev('.file-path');
		var fullPath = $filePath.attr('title');
		var isExpanded = $filePath.text() === fullPath;
		
		if (isExpanded) {
			$filePath.text(fullPath.split('/').pop());
			$(this).text(DiluxOneOffloadActivity.i18n.full_path);
		} else {
			$filePath.text(fullPath);
			$(this).text(DiluxOneOffloadActivity.i18n.short_name);
		}
	});
	
	// Refresh activity
	$('#refresh-activity').on('click', function() {
		location.reload();
	});
	
	// Auto-refresh every 30 seconds
	setInterval(function() {
		if (document.visibilityState === 'visible') {
			// Only refresh statistics, not the full page
			$.post(diluxOneOffloadAdmin.ajaxUrl, {
				action: 'diluxone_offload_refresh_stats',
				nonce: diluxOneOffloadAdmin.nonce
			}, function(response) {
				if (response.success) {
					// Update statistics cards
					$('.stats-cards .stat-number').each(function(index) {
						var keys = ['total_today', 'total_week', 'total_month', 'errors_count'];
						if (response.stats[keys[index]] !== undefined) {
							$(this).text(response.stats[keys[index]].toLocaleString());
						}
					});
				}
			});
		}
	}, 30000);
});
