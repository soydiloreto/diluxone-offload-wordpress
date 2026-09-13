/**
 * DiluxOne Cloud Storage Admin JavaScript
 */
jQuery(document).ready(function($) {

    // Re-disable Save button if credentials are changed (only if button was initially disabled)
    var initiallyDisabled = $('#submit').prop('disabled');
    if (initiallyDisabled) {
        $('#account_name, #account_key, #container_name, #custom_domain').on('input change', function() {
            // Disable save button when credentials change
            $('#submit').prop('disabled', true);

            // Reset message to warning
            $('#test-status-message')
                .html('⚠️ You must test the connection successfully before saving credentials.')
                .css({
                    'color': '#d63638',
                    'font-weight': '600'
                });
        });
    }

    // Test Connection functionality
    $('#test-connection').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $result = $('#connection-result');
        
        // Get form data
        var formData = {
            action: 'diluxone_offload_test_connection',
            nonce: diluxOneOffloadAdmin.nonce,
            account_name: $('#account_name').val(),
            account_key: $('#account_key').val(),
            container_name: $('#container_name').val(),
            custom_domain: $('#custom_domain').val()
        };
        
        // Validate required fields
        if (!formData.account_name || !formData.account_key || !formData.container_name) {
            showTestResult('error', 'Please fill in all required fields (Account Name, Account Key, and Container Name).');
            return;
        }
        
        // Update button state
        $button.prop('disabled', true)
               .addClass('testing')
               .text('Testing Connection...');
        
        // Hide previous results
        $result.hide();
        
        // Make AJAX request
        $.ajax({
            url: diluxOneOffloadAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            timeout: 30000, // 30 seconds timeout
            success: function(response) {
                if (response.success) {
                    showTestResult('success', response.data.message, response.data.details);

                    // Enable the Save Configuration button on successful test
                    $('#submit').prop('disabled', false);

                    // Change warning message to success message
                    $('#test-status-message')
                        .html('✓ Credentials work correctly. You can now save the configuration.')
                        .css({
                            'color': '#46b450',
                            'font-weight': '600'
                        });
                } else {
                    showTestResult('error', response.data.message || 'Connection test failed.', response.data.details);

                    // Keep Save button disabled on failed test
                    $('#submit').prop('disabled', true);

                    // Show warning message again
                    $('#test-status-message')
                        .html('⚠️ You must test the connection successfully before saving credentials.')
                        .css({
                            'color': '#d63638',
                            'font-weight': '600'
                        });
                }
            },
            error: function(xhr, status, error) {
                var message = 'Connection test failed: ';
                if (status === 'timeout') {
                    message += 'Request timed out. Please check your credentials and try again.';
                } else if (xhr.status === 502) {
                    message += 'Server error (502 Bad Gateway). Please try again later.';
                } else if (xhr.status === 0) {
                    message += 'Network error. Please check your internet connection.';
                } else {
                    message += error || 'Unknown error occurred.';
                }
                showTestResult('error', message);
            },
            complete: function() {
                // Reset button state
                $button.prop('disabled', false)
                       .removeClass('testing')
                       .text('Test Connection');
            }
        });
    });
    
    /**
     * Show test result message
     */
    function showTestResult(type, message, details) {
        var $result = $('#connection-result');
        
        $result.removeClass('success error warning')
               .addClass(type)
               .html('<strong>' + message + '</strong>')
               .show();
        
        if (details && typeof details === 'object') {
            var detailsHtml = '<div style="margin-top: 10px; font-size: 0.9em;">';
            $.each(details, function(key, value) {
                detailsHtml += '<div><strong>' + key + ':</strong> ' + value + '</div>';
            });
            detailsHtml += '</div>';
            $result.append(detailsHtml);
        }
        
        // Auto-hide success messages after 5 seconds
        if (type === 'success') {
            setTimeout(function() {
                $result.fadeOut();
            }, 5000);
        }
    }
    
    // Migration Tools functionality
    $('.migration-action').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var action = $button.data('action');
        var $progressContainer = $button.closest('.migration-tool').find('.migration-progress-container');
        
        if (!action) {
            alert('Invalid migration action.');
            return;
        }
        
        // Confirm destructive actions
        if (action.includes('delete') || action.includes('remove')) {
            if (!confirm('Are you sure you want to perform this action? This cannot be undone.')) {
                return;
            }
        }
        
        // Update button state
        $button.prop('disabled', true).text('Processing...');
        
        // Show progress bar if available
        if ($progressContainer.length) {
            $progressContainer.show();
            updateMigrationProgress(0);
        }
        
        // Make AJAX request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'diluxone_offload_migration_action',
                nonce: diluxOneOffloadAdmin.nonce,
                migration_action: action
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.redirect) {
                        window.location.href = response.data.redirect;
                    } else {
                        alert('Action completed successfully: ' + response.data.message);
                        // Refresh the page to show updated stats
                        location.reload();
                    }
                } else {
                    alert('Action failed: ' + (response.data.message || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                alert('Action failed: ' + error);
            },
            complete: function() {
                $button.prop('disabled', false).text($button.data('original-text') || 'Start');
                if ($progressContainer.length) {
                    $progressContainer.hide();
                }
            }
        });
    });
    
    /**
     * Update migration progress bar
     */
    function updateMigrationProgress(percentage) {
        $('.migration-progress-bar').css('width', percentage + '%');
    }
    
    // Activity Log filters
    $('#activity-filters-form').on('submit', function(e) {
        e.preventDefault();
        var url = new URL(window.location);
        var formData = new FormData(this);
        
        // Update URL parameters
        for (let [key, value] of formData.entries()) {
            if (value) {
                url.searchParams.set(key, value);
            } else {
                url.searchParams.delete(key);
            }
        }
        
        // Reset page to 1 when filtering
        url.searchParams.delete('paged');
        
        window.location.href = url.toString();
    });
    
    // Clear activity filters
    $('#clear-filters').on('click', function(e) {
        e.preventDefault();
        var url = new URL(window.location);
        
        // Remove filter parameters
        url.searchParams.delete('activity_type');
        url.searchParams.delete('date_from');
        url.searchParams.delete('date_to');
        url.searchParams.delete('paged');
        
        window.location.href = url.toString();
    });
    
    // Status page - Test individual checks
    $('.test-check').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var checkType = $button.data('check');
        var $statusCheck = $button.closest('.status-check');
        
        $button.prop('disabled', true).text('Testing...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'diluxone_offload_test_check',
                nonce: diluxOneOffloadAdmin.nonce,
                check_type: checkType
            },
            success: function(response) {
                if (response.success) {
                    // Update the check status
                    $statusCheck.removeClass('passed warning failed')
                                .addClass(response.data.status);
                    $statusCheck.find('.check-status')
                                .removeClass('passed warning failed')
                                .addClass(response.data.status)
                                .text(response.data.status_text);
                    $statusCheck.find('.check-description').text(response.data.message);
                } else {
                    alert('Test failed: ' + (response.data.message || 'Unknown error'));
                }
            },
            error: function() {
                alert('Test failed: Network error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Test Now');
            }
        });
    });
    
    // Auto-refresh status checks every 5 minutes
    if ($('.diluxone-offload-status').length && diluxOneOffloadAdmin.autoRefresh) {
        setInterval(function() {
            location.reload();
        }, 300000); // 5 minutes
    }
    
    // Tooltips for help text
    $('.help-tip').on('mouseover', function() {
        $(this).next('.help-text').show();
    }).on('mouseout', function() {
        $(this).next('.help-text').hide();
    });
    
    // Form validation
    $('form[data-validate]').on('submit', function(e) {
        var isValid = true;
        var $form = $(this);
        
        // Clear previous errors
        $form.find('.field-error').remove();
        
        // Validate required fields
        $form.find('[required]').each(function() {
            var $field = $(this);
            var value = $field.val().trim();
            
            if (!value) {
                isValid = false;
                $field.after('<div class="field-error" style="color: #d63638; font-size: 0.9em; margin-top: 5px;">This field is required.</div>');
            }
        });
        
        // Validate email fields
        $form.find('[type="email"]').each(function() {
            var $field = $(this);
            var value = $field.val().trim();
            
            if (value && !isValidEmail(value)) {
                isValid = false;
                $field.after('<div class="field-error" style="color: #d63638; font-size: 0.9em; margin-top: 5px;">Please enter a valid email address.</div>');
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            $('html, body').animate({
                scrollTop: $form.find('.field-error').first().offset().top - 100
            }, 500);
        }
    });
    
    /**
     * Validate email format
     */
    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }
    
    // Initialize any existing functionality
    if (typeof initializeDiluxOneOffload === 'function') {
        initializeDiluxOneOffload();
    }
});