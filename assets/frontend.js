/**
 * Vishavjeet Age Consent Banner - Frontend JavaScript
 * Author: Vishavjeet Choubey
 * URI: https://vishavjeet.in/
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        // Store original button text
        var $confirmBtn = $('#wpvjacb-confirm-btn');
        var originalText = $confirmBtn.attr('data-original-text') || $confirmBtn.text();
        
        // Confirm age button
        $confirmBtn.on('click', function() {
            var $btn = $(this);
            
            // Disable buttons during verification
            $('#wpvjacb-buttons button').prop('disabled', true);
            $btn.text('Verifying...');
            
            // Send AJAX request
            $.ajax({
                url: wpvjacbData.ajax_url,
                type: 'POST',
                data: {
                    action: 'vjacb_verify_age',
                    nonce: wpvjacbData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Show success state
                        $btn.text('Verified! Redirecting...');
                        
                        // Fade out overlay and reload page
                        setTimeout(function() {
                            $('#wpvjacb-overlay').fadeOut(400, function() {
                                window.location.reload();
                            });
                        }, 500);
                    } else {
                        // Show error message
                        var errorMsg = response.data && response.data.message ? 
                            response.data.message : 
                            'Verification failed. Please try again.';
                        
                        alert(errorMsg);
                        
                        // Re-enable buttons
                        $('#wpvjacb-buttons button').prop('disabled', false);
                        $btn.text(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('wpVJACB Verification Error:', error);
                    alert('An error occurred during verification. Please refresh the page and try again.');
                    
                    // Re-enable buttons
                    $('#wpvjacb-buttons button').prop('disabled', false);
                    $btn.text(originalText);
                }
            });
        });
        
        // Cancel button (underage)
        $('#wpvjacb-cancel-btn').on('click', function() {
            // Hide buttons with fade effect
            $('#wpvjacb-buttons').fadeOut(300, function() {
                // Show underage message
                $('#wpvjacb-underage-message').fadeIn(300);
            });
        });
        
        // Prevent right-click on overlay
        $('#wpvjacb-overlay').on('contextmenu', function(e) {
            e.preventDefault();
            return false;
        });
        
        // Prevent common developer tool keyboard shortcuts
        $(document).on('keydown', function(e) {
            // Prevent F12 (Dev Tools)
            if (e.keyCode === 123) {
                e.preventDefault();
                return false;
            }
            
            // Prevent Ctrl+Shift+I (Dev Tools)
            if (e.ctrlKey && e.shiftKey && e.keyCode === 73) {
                e.preventDefault();
                return false;
            }
            
            // Prevent Ctrl+Shift+J (Console)
            if (e.ctrlKey && e.shiftKey && e.keyCode === 74) {
                e.preventDefault();
                return false;
            }
            
            // Prevent Ctrl+U (View Source)
            if (e.ctrlKey && e.keyCode === 85) {
                e.preventDefault();
                return false;
            }
            
            // Prevent Ctrl+Shift+C (Inspect Element)
            if (e.ctrlKey && e.shiftKey && e.keyCode === 67) {
                e.preventDefault();
                return false;
            }
        });
        
        // Prevent browser back button bypass
        history.pushState(null, null, location.href);
        window.onpopstate = function() {
            history.go(1);
        };
        
    });
    
})(jQuery);