jQuery(document).ready(function($) {
	// Chart data (passed from PHP)
	var chartData = DiluxOneOffloadActivity.data.chart_data;
	
	// Initialize chart
	if (typeof Chart !== 'undefined' && document.getElementById('activity-chart')) {
		var ctx = document.getElementById('activity-chart').getContext('2d');
		new Chart(ctx, {
			type: 'line',
			data: {
				labels: chartData.labels,
				datasets: [{
					label: DiluxOneOffloadActivity.i18n.uploads,
					data: chartData.uploads,
					borderColor: '#0073aa',
					backgroundColor: 'rgba(0, 115, 170, 0.1)',
					fill: true
				}, {
					label: DiluxOneOffloadActivity.i18n.deletions,
					data: chartData.deletions,
					borderColor: '#dc3545',
					backgroundColor: 'rgba(220, 53, 69, 0.1)',
					fill: true
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				scales: {
					y: {
						beginAtZero: true
					}
				},
				plugins: {
					legend: {
						position: 'top',
					},
					title: {
						display: false
					}
				}
			}
		});
	}
	
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
	
	// Export activity log
	$('#export-activity').on('click', function() {
		var $button = $(this);
		$button.prop('disabled', true).find('.dashicons').removeClass('dashicons-download').addClass('dashicons-update');
		
		$.post(diluxOneOffloadAdmin.ajaxUrl, {
			action: 'diluxone_offload_export_activity',
			nonce: diluxOneOffloadAdmin.nonce,
			activity_type: DiluxOneOffloadActivity.data.activity_type,
			date_from: DiluxOneOffloadActivity.data.date_from,
			date_to: DiluxOneOffloadActivity.data.date_to
		}, function(response) {
			if (response.success) {
				// Create download link
				var link = document.createElement('a');
				link.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(response.csv);
				link.download = 'diluxone-offload-activity-log-' + new Date().toISOString().substr(0, 10) + '.csv';
				link.click();
			} else {
				alert(DiluxOneOffloadActivity.i18n.export_failed + ' ' + response.error);
			}
		}).always(function() {
			$button.prop('disabled', false).find('.dashicons').removeClass('dashicons-update').addClass('dashicons-download');
		});
	});
	
	// Clear old logs
	$('#clear-old-logs').on('click', function() {
		if (!confirm(DiluxOneOffloadActivity.i18n.are_you_sure_you_want_to)) {
			return;
		}
		
		var $button = $(this);
		$button.prop('disabled', true).find('.dashicons').removeClass('dashicons-trash').addClass('dashicons-update');
		
		$.post(diluxOneOffloadAdmin.ajaxUrl, {
			action: 'diluxone_offload_clear_old_logs',
			nonce: diluxOneOffloadAdmin.nonce
		}, function(response) {
			if (response.success) {
				location.reload();
			} else {
				alert(DiluxOneOffloadActivity.i18n.clear_failed + ' ' + response.error);
			}
		}).always(function() {
			$button.prop('disabled', false).find('.dashicons').removeClass('dashicons-update').addClass('dashicons-trash');
		});
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
