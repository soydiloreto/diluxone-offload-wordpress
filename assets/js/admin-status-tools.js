jQuery(document).ready(function($) {
	// Prepare config data for export
	var configData = DiluxOneOffloadStatus.data.config_data_json_pretty_print;

	// Export configuration
	$('#export-config').on('click', function() {
		var $button = $(this);

		// Create JSON data
		var exportData = {
			exported_at: new Date().toISOString(),
			wordpress_version: DiluxOneOffloadStatus.data.get_bloginfo_version,
			plugin_version: DiluxOneOffloadStatus.data.admin_get_plugin_version,
			site_url: DiluxOneOffloadStatus.data.get_site_url,
			configuration: configData
		};

		// Convert to JSON string
		var jsonString = JSON.stringify(exportData, null, 2);

		// Create download
		var blob = new Blob([jsonString], { type: 'application/json' });
		var url = URL.createObjectURL(blob);
		var link = document.createElement('a');
		link.href = url;
		link.download = 'diluxone-offload-config-' + new Date().toISOString().slice(0, 10) + '.json';
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		URL.revokeObjectURL(url);

		// Visual feedback
		var originalHTML = $button.html();
		$button.html('<span class="dashicons dashicons-yes"></span> ' + DiluxOneOffloadStatus.i18n.exported)
			.addClass('button-success')
			.prop('disabled', true);

		setTimeout(function() {
			$button.html(originalHTML)
				.removeClass('button-success')
				.prop('disabled', false);
		}, 2000);
	});

	// Clear import textarea
	$('#clear-import').on('click', function() {
		$('#import-config-data').val('');
		$('#import-result').hide();
	});

	// Import configuration
	$('#import-config').on('click', function() {
		var $button = $(this);
		var $textarea = $('#import-config-data');
		var $result = $('#import-result');
		var jsonData = $textarea.val().trim();

		if (!jsonData) {
			$result.html('<span class="dashicons dashicons-warning"></span> ' + DiluxOneOffloadStatus.i18n.please_paste_configuration_json_first)
				.removeClass('result-success').addClass('result-error').show();
			return;
		}

		// Validate JSON
		var importData;
		try {
			importData = JSON.parse(jsonData);
		} catch (e) {
			$result.html('<span class="dashicons dashicons-warning"></span> ' + DiluxOneOffloadStatus.i18n.invalid_json_format + ' ' + e.message)
				.removeClass('result-success').addClass('result-error').show();
			return;
		}

		// Extract configuration
		var config = importData.configuration || importData;

		// Confirm before importing
		if (!confirm(DiluxOneOffloadStatus.i18n.warning_this_will_overwrite_your_current)) {
			return;
		}

		// Disable button
		var originalHTML = $button.html();
		$button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + DiluxOneOffloadStatus.i18n.importing);

		// Send to server
		$.ajax({
			url: diluxOneOffloadAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_import_config',
				nonce: diluxOneOffloadAdmin.nonce,
				config: JSON.stringify(config)
			},
			success: function(response) {
				if (response.success) {
					$result.html('<span class="dashicons dashicons-yes"></span> ' + DiluxOneOffloadStatus.i18n.configuration_imported_successfully_reloading_page)
						.removeClass('result-error').addClass('result-success').show();

					// Reload page after 2 seconds
					setTimeout(function() {
						location.reload();
					}, 2000);
				} else {
					$result.html('<span class="dashicons dashicons-warning"></span> ' + (response.data || DiluxOneOffloadStatus.i18n.import_failed))
						.removeClass('result-success').addClass('result-error').show();
					$button.prop('disabled', false).html(originalHTML);
				}
			},
			error: function() {
				$result.html('<span class="dashicons dashicons-warning"></span> ' + DiluxOneOffloadStatus.i18n.request_failed_please_try_again)
					.removeClass('result-success').addClass('result-error').show();
				$button.prop('disabled', false).html(originalHTML);
			}
		});
	});
});
