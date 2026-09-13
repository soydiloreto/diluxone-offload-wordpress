jQuery(document).ready(function($) {
	function formatBytes(bytes) {
		if (!bytes || bytes === 0) return '0 B';
		var k = 1024;
		var sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
		var i = Math.floor(Math.log(bytes) / Math.log(k));
		return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
	}

	$('#refresh-stats-btn').on('click', function() {
		var $button = $(this);
		var $loading = $('#stats-loading');
		var $content = $('#stats-content');

		$button.prop('disabled', true);
		$button.find('.dashicons').addClass('spin');
		$loading.show();
		$content.hide();

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_refresh_stats',
				nonce: diluxOneOffloadAdmin.nonce
			},
			timeout: 30000,
			success: function(response) {
				if (response.success) {
					var d = response.data;
					$('#stat-file-count').text(parseInt(d.fileCount || 0).toLocaleString());

					// Hide pie chart if no files
					if (parseInt(d.fileCount || 0) === 0) {
						$('#stat-pie-section').hide();
					}

					if (d.storageLimitBytes) {
						var pct = Math.min((d.storageUsedBytes / d.storageLimitBytes) * 100, 100).toFixed(1);
						$('#stat-storage-bar').css('width', pct + '%');
						$('#stat-storage-detail').text(formatBytes(d.storageUsedBytes) + ' / ' + formatBytes(d.storageLimitBytes) + ' (' + pct + '%)');
					} else {
						$('#stat-storage-detail').text(formatBytes(d.storageUsedBytes));
					}

					if (d.bandwidthLimitBytes) {
						var bwPct = Math.min((d.bandwidthUsedBytes / d.bandwidthLimitBytes) * 100, 100).toFixed(1);
						$('#stat-bandwidth-bar').css('width', bwPct + '%');
						$('#stat-bandwidth-detail').text(formatBytes(d.bandwidthUsedBytes) + ' / ' + formatBytes(d.bandwidthLimitBytes) + ' (' + bwPct + '%)');
					} else if (d.bandwidthUsedBytes !== null) {
						$('#stat-bandwidth-detail').text(d.bandwidthUsedBytes > 0 ? formatBytes(d.bandwidthUsedBytes) : 'Not available');
					}

					if (d.plan !== null && d.plan !== undefined) {
						$('#stat-plan').text(d.plan);
					}

					if (d.quotaExceeded) {
						$('#quota-exceeded-warning').show();
					} else {
						$('#quota-exceeded-warning').hide();
					}

					// Update pie chart
					if (d.filesByType) {
						var ft = d.filesByType;
						var total = (ft.images || 0) + (ft.videos || 0) + (ft.audio || 0) + (ft.other || 0);
						if (total > 0) {
							var pImages = ((ft.images || 0) / total * 100).toFixed(1);
							var pVideos = ((ft.videos || 0) / total * 100).toFixed(1);
							var pAudio = ((ft.audio || 0) / total * 100).toFixed(1);
							var pOther = (100 - pImages - pVideos - pAudio).toFixed(1);
							var s1 = parseFloat(pImages);
							var s2 = s1 + parseFloat(pVideos);
							var s3 = s2 + parseFloat(pAudio);
							var $pie = $('#stat-pie-section');
							if ($pie.length) {
								$pie.find('.diluxone-offload-pie').css('background', 'conic-gradient(#2271b1 0% ' + s1 + '%, #d63638 ' + s1 + '% ' + s2 + '%, #dba617 ' + s2 + '% ' + s3 + '%, #8c8f94 ' + s3 + '% 100%)');
								var $legends = $pie.find('.diluxone-offload-legend-item');
								var labels = [
									DiluxOneOffloadOverview.i18n.images,
									DiluxOneOffloadOverview.i18n.videos,
									DiluxOneOffloadOverview.i18n.audio,
									DiluxOneOffloadOverview.i18n.other
								];
								var counts = [ft.images || 0, ft.videos || 0, ft.audio || 0, ft.other || 0];
								var pcts = [pImages, pVideos, pAudio, pOther];
								$legends.each(function(i) {
									var $dot = $(this).find('.diluxone-offload-legend-dot').clone();
									$(this).empty().append($dot).append(document.createTextNode(' ' + labels[i] + ' ' + parseInt(counts[i]).toLocaleString() + ' (' + pcts[i] + '%)'));
								});
							} else {
								// Pie chart section doesn't exist yet — build it
								var pieHtml = '<div class="diluxone-offload-pie-container" id="stat-pie-section">';
								pieHtml += '<div class="diluxone-offload-pie" style="background: conic-gradient(#2271b1 0% ' + s1 + '%, #d63638 ' + s1 + '% ' + s2 + '%, #dba617 ' + s2 + '% ' + s3 + '%, #8c8f94 ' + s3 + '% 100%);"></div>';
								pieHtml += '<div class="diluxone-offload-pie-legend">';
								var colors = ['#2271b1', '#d63638', '#dba617', '#8c8f94'];
								var labels2 = [
									DiluxOneOffloadOverview.i18n.images,
									DiluxOneOffloadOverview.i18n.videos,
									DiluxOneOffloadOverview.i18n.audio,
									DiluxOneOffloadOverview.i18n.other
								];
								var counts2 = [ft.images || 0, ft.videos || 0, ft.audio || 0, ft.other || 0];
								var pcts2 = [pImages, pVideos, pAudio, pOther];
								for (var i = 0; i < 4; i++) {
									pieHtml += '<div class="diluxone-offload-legend-item"><span class="diluxone-offload-legend-dot" style="background: ' + colors[i] + ';"></span> ' + labels2[i] + ' ' + parseInt(counts2[i]).toLocaleString() + ' (' + pcts2[i] + '%)</div>';
								}
								pieHtml += '</div></div>';
								$('.diluxone-offload-files-count').after(pieHtml);
							}
						}
					}

					$('#stat-last-updated').text(DiluxOneOffloadOverview.i18n.last_updated + ' ' + DiluxOneOffloadOverview.i18n.just_now);
				} else {
					// Remove stale pie chart and show ERROR state
					$('#stat-pie-section').remove();
					var errorHtml = '<div class="diluxone-offload-overview-bars"><div class="diluxone-offload-bar-section"><div class="diluxone-offload-bar-header"><span class="diluxone-offload-bar-title">' + DiluxOneOffloadOverview.i18n.storage + '</span><span class="diluxone-offload-bar-value" id="stat-storage-detail" style="color: #d63638; font-weight: 600;">ERROR</span></div></div></div>';
					errorHtml += '<div class="diluxone-offload-files-section"><div class="diluxone-offload-files-grid"><div class="diluxone-offload-files-count"><span class="diluxone-offload-stat-label">' + DiluxOneOffloadOverview.i18n.total_files + '</span><div id="stat-file-count" class="diluxone-offload-stat-value" style="color: #d63638; font-size: 16px;">' + DiluxOneOffloadOverview.i18n.error_please_update_your_credentials + '</div></div></div></div>';
					errorHtml += '<p class="description" style="color: #d63638; margin-top: 10px;">' + (response.data.message || 'Unknown error') + '</p>';
					$('#stats-content').html(errorHtml);
				}
			},
			error: function(xhr, status, error) {
				var msg = status === 'timeout' ? DiluxOneOffloadOverview.i18n.request_timed_out_try_again_later : 'Network error: ' + error;
				var errorHtml = '<div class="diluxone-offload-overview-bars"><div class="diluxone-offload-bar-section"><div class="diluxone-offload-bar-header"><span class="diluxone-offload-bar-title">' + DiluxOneOffloadOverview.i18n.storage + '</span><span class="diluxone-offload-bar-value" style="color: #d63638; font-weight: 600;">ERROR</span></div></div></div>';
				errorHtml += '<div class="diluxone-offload-files-section"><div class="diluxone-offload-files-grid"><div class="diluxone-offload-files-count"><span class="diluxone-offload-stat-label">' + DiluxOneOffloadOverview.i18n.total_files + '</span><div class="diluxone-offload-stat-value" style="color: #d63638; font-size: 16px;">' + DiluxOneOffloadOverview.i18n.error_please_update_your_credentials + '</div></div></div></div>';
				errorHtml += '<p class="description" style="color: #d63638; margin-top: 10px;">' + msg + '</p>';
				$('#stats-content').html(errorHtml);
			},
			complete: function() {
				$button.prop('disabled', false);
				$button.find('.dashicons').removeClass('spin');
				$loading.hide();
				$content.show();
			}
		});
	});
});
