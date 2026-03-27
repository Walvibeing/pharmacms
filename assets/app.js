/* ============================================
   PharmaCMS — Monday.com Style JavaScript
   ============================================ */

$(document).ready(function() {

    // Mobile sidebar toggle
    $('#mobileMenuToggle').on('click', function() {
        $('.sidebar').addClass('open');
        $('#sidebarOverlay').addClass('active');
    });
    $('#sidebarOverlay').on('click', function() {
        $('.sidebar').removeClass('open');
        $(this).removeClass('active');
    });

    // ---- Alerts auto-dismiss with smooth animation ----
    setTimeout(function() {
        $('.alert:not(.alert-emergency)').each(function() {
            var el = $(this);
            el.css({ transition: 'all 400ms cubic-bezier(0.25, 0.1, 0.25, 1)', opacity: 0, transform: 'translateY(-8px)' });
            setTimeout(function() { el.remove(); }, 400);
        });
    }, 15000);

    // ---- Tabs ----
    $(document).on('click', '.tab-btn', function() {
        var target = $(this).data('tab');
        var $tabs = $(this).closest('.tabs, .panel-tabs');
        $tabs.find('.tab-btn, .panel-tab').removeClass('active').attr('aria-selected', 'false').attr('tabindex', '-1');
        $(this).addClass('active').attr('aria-selected', 'true').attr('tabindex', '0');
        // Handle both .tab-panel siblings and .panel-tab-content inside panel body
        $tabs.siblings('.tab-panel').removeClass('active').attr('hidden', true);
        $('#' + target).addClass('active').removeAttr('hidden');
        // Also handle panel-tab-content pattern
        $(this).closest('.side-panel-header, .side-panel').find('.panel-tab-content').hide();
        $(this).closest('.side-panel-header, .side-panel').find('#' + target + 'Content, [data-tab-content="' + target + '"]').show();
    });

    $(document).on('click', '.panel-tab', function() {
        var target = $(this).data('tab');
        var $tabs = $(this).closest('.panel-tabs');
        $tabs.find('.panel-tab').removeClass('active').attr('aria-selected', 'false').attr('tabindex', '-1');
        $(this).addClass('active').attr('aria-selected', 'true').attr('tabindex', '0');
    });

    // Arrow key navigation for tab controls
    $(document).on('keydown', '.tab-btn, .panel-tab', function(e) {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        e.preventDefault();
        var $tabs = $(this).closest('.tabs, .panel-tabs').find('.tab-btn, .panel-tab');
        var idx = $tabs.index(this);
        if (e.key === 'ArrowRight') idx = (idx + 1) % $tabs.length;
        if (e.key === 'ArrowLeft') idx = (idx - 1 + $tabs.length) % $tabs.length;
        $tabs.eq(idx).focus().trigger('click');
    });

    // ---- Side Panel (replaces modals) ----
    $(document).on('click', '[data-panel]', function() {
        var panelId = $(this).data('panel');
        openSidePanel(panelId);
    });

    $(document).on('click', '.side-panel-close', function() {
        var panel = $(this).closest('.side-panel');
        closeSidePanel(panel.attr('id'));
    });

    $(document).on('click', '.side-panel-overlay', function() {
        // Find the panel associated with this overlay
        var panel = $(this).next('.side-panel');
        if (panel.length) {
            closeSidePanel(panel.attr('id'));
        } else {
            // Fallback: close all open panels
            closeAllSidePanels();
        }
    });

    // ---- Legacy Modal Support (backward compat) ----
    $(document).on('click', '[data-modal]', function() {
        var target = $(this).data('modal');
        $('#' + target).addClass('active');
    });

    $(document).on('click', '.modal-close, .modal-overlay', function(e) {
        if (e.target === this) {
            $(this).closest('.modal-overlay').removeClass('active');
        }
    });

    // ---- Escape key closes panels and modals ----
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            // Close side panels first
            var openPanel = $('.side-panel.active').last();
            if (openPanel.length) {
                closeSidePanel(openPanel.attr('id'));
                return;
            }
            // Fallback to modals
            $('.modal-overlay.active').removeClass('active');
        }
    });

    // ---- Focus trap for side panels ----
    $(document).on('keydown', function(e) {
        if (e.key !== 'Tab') return;
        var $activePanel = $('.side-panel.active').last();
        if (!$activePanel.length) return;

        var $focusable = $activePanel.find('button, [href], input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible');
        if (!$focusable.length) return;

        var $first = $focusable.first();
        var $last = $focusable.last();

        if (e.shiftKey) {
            if ($(document.activeElement).is($first) || !$activePanel.has(document.activeElement).length) {
                e.preventDefault();
                $last.focus();
            }
        } else {
            if ($(document.activeElement).is($last) || !$activePanel.has(document.activeElement).length) {
                e.preventDefault();
                $first.focus();
            }
        }
    });

    // ---- Toggle switch ----
    $(document).on('change', '.toggle-switch input', function() {
        var label = $(this).closest('.form-group').find('.toggle-label');
        label.text(this.checked ? 'Active' : 'Inactive');
    });

    // ---- Confirm delete (styled dialog) ----
    $(document).on('click', '.btn-delete-confirm', function(e) {
        e.preventDefault();
        var $form = $(this).closest('form');
        showConfirm({
            title: 'Delete this item?',
            message: 'This action cannot be undone.',
            confirmText: 'Delete',
            confirmClass: 'btn-danger',
            onConfirm: function() {
                $form[0].submit();
            }
        });
    });

    // ---- Copy to clipboard ----
    $(document).on('click', '.btn-copy', function() {
        var text = $(this).data('copy');
        var btn = $(this);
        var originalText = btn.text();
        navigator.clipboard.writeText(text).then(function() {
            btn.text('Copied!');
            showToast('Copied to clipboard!');
            setTimeout(function() { btn.text(originalText); }, 2000);
        }).catch(function() {
            showToast('Failed to copy to clipboard', 'error');
        });
    });

    // ---- File upload drag and drop (staged flow) ----
    var uploadZone = $('#uploadZone');
    // Pending files array — the staging area before upload
    window._uploadPendingFiles = [];

    if (uploadZone.length) {
        uploadZone.on('dragover', function(e) {
            e.preventDefault();
            $(this).addClass('dragover');
        });
        uploadZone.on('dragleave drop', function(e) {
            e.preventDefault();
            $(this).removeClass('dragover');
        });
        uploadZone.on('drop', function(e) {
            var files = e.originalEvent.dataTransfer.files;
            if (files.length) {
                stageFiles(files);
            }
        });
        uploadZone.on('click', function() {
            $('#fileInput').click();
        });
        $('#fileInput').on('change', function() {
            if (this.files.length) {
                stageFiles(this.files);
            }
            // Reset input so re-selecting the same file triggers change
            this.value = '';
        });

        // "Upload X files" button
        $(document).on('click', '#uploadStartBtn', function() {
            startUpload();
        });

        // "Clear All" button
        $(document).on('click', '#uploadClearAllBtn', function() {
            clearStagedFiles();
        });

        // Remove individual file
        $(document).on('click', '.upload-file-remove', function() {
            var idx = $(this).data('idx');
            window._uploadPendingFiles.splice(idx, 1);
            renderFileList();
        });

        // "All Locations" toggle in upload panel
        $(document).on('change', '#uploadAllLocations', function() {
            $('#uploadLocationCheckboxes').toggle(!this.checked);
        });

        // "All Locations" toggle in preview panel
        $(document).on('change', '#previewAllLocations', function() {
            $('#previewLocationCheckboxes').toggle(!this.checked);
        });

        // "Upload More" button
        $(document).on('click', '#uploadMoreBtn', function() {
            resetUploadPanel();
        });

        // "Done" button
        $(document).on('click', '#uploadDoneBtn', function() {
            resetUploadPanel();
            closeSidePanel('uploadPanel', true);
        });
    }

    // ---- Clickable rows ----
    $(document).on('click', 'tr.clickable', function() {
        var href = $(this).data('href');
        if (href) window.location.href = href;
    });

    // Keyboard accessibility: Enter/Space triggers click on rows with onclick or role="button"
    $(document).on('keydown', 'tr[role="button"]', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            $(this).trigger('click');
        }
    });

    // ---- Mobile sidebar toggle ----
    $(document).on('click', '#sidebarToggle', function() {
        $('#sidebar').toggleClass('open');
    });

    // Close sidebar on overlay click (mobile)
    $(document).on('click', function(e) {
        if ($(window).width() <= 768) {
            if (!$(e.target).closest('#sidebar, #sidebarToggle').length) {
                $('#sidebar').removeClass('open');
            }
        }
    });

    // ---- Smooth form focus effects ----
    $(document).on('focus', '.form-control', function() {
        $(this).closest('.form-group').addClass('focused');
    }).on('blur', '.form-control', function() {
        $(this).closest('.form-group').removeClass('focused');
    });

    // ---- Accessibility: aria-label on side panel close buttons ----
    $('.side-panel-close').attr('aria-label', 'Close');

    // ---- Track dirty forms in panels ----
    $(document).on('input change', '.side-panel.active input, .side-panel.active select, .side-panel.active textarea', function() {
        $(this).closest('.side-panel').data('dirty', true);
    });

    // ---- Flash alert close buttons ----
    $('.alert[class*="alert-success"], .alert[class*="alert-error"], .alert[class*="alert-danger"], .alert[class*="alert-warning"]').each(function() {
        if (!$(this).find('.alert-dismiss').length) {
            $(this).css('position', 'relative').prepend('<button class="alert-dismiss" style="position:absolute;top:8px;right:12px;background:none;border:none;font-size:1.1rem;cursor:pointer;opacity:0.5;color:inherit" aria-label="Dismiss">&times;</button>');
        }
    });

    $(document).on('click', '.alert-dismiss', function() {
        $(this).closest('.alert').fadeOut(300, function() { $(this).remove(); });
    });

    // Initialize ARIA on tab controls
    $('.tabs, .panel-tabs').each(function() {
        $(this).attr('role', 'tablist');
        $(this).find('.tab-btn, .panel-tab').each(function() {
            $(this).attr('role', 'tab');
            var isActive = $(this).hasClass('active');
            $(this).attr('aria-selected', isActive ? 'true' : 'false');
            $(this).attr('tabindex', isActive ? '0' : '-1');
        });
    });
    $('.tab-panel').each(function() {
        $(this).attr('role', 'tabpanel');
        if (!$(this).hasClass('active')) $(this).attr('hidden', true);
    });
});

// ---- Global HTML escape utility (XSS prevention) ----
window.escapeHtml = function(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
};

// ---- Side Panel helpers (global) ----
function openSidePanel(panelId) {
    var panel = $('#' + panelId);
    var overlay = $('#' + panelId + 'Overlay');
    overlay.addClass('active');
    panel.addClass('active');
    // ARIA attributes for accessibility
    panel.attr({
        'role': 'dialog',
        'aria-modal': 'true'
    });
    // Focus first input in the panel after animation
    setTimeout(function() {
        panel.find('input:visible, select:visible, textarea:visible').first().focus();
    }, 180);
    // Prevent body scroll
    $('body').css('overflow', 'hidden');
}

function closeSidePanel(panelId, force) {
    var $panel = $('#' + panelId);
    var overlay = $('#' + panelId + 'Overlay');

    function doClose() {
        $panel.data('dirty', false);
        $panel.removeClass('active');
        overlay.removeClass('active');
        $panel.removeAttr('role aria-modal');
        if (!$('.side-panel.active').length) {
            $('body').css('overflow', '');
        }
    }

    // Check for unsaved changes unless force-closing
    if (!force && $panel.data('dirty')) {
        showConfirm({
            title: 'Unsaved changes',
            message: 'You have unsaved changes. Discard them?',
            confirmText: 'Discard',
            confirmClass: 'btn-danger',
            onConfirm: doClose
        });
        return;
    }
    doClose();
}

function closeAllSidePanels() {
    var panels = $('.side-panel.active');
    // Instant-close panels (no slide animation) but leave overlays alone
    // so there's no white flash when switching between panels
    panels.css('transition', 'none');
    panels.each(function() {
        $(this).data('dirty', false);
        $(this).removeAttr('role aria-modal');
    });
    panels.removeClass('active');
    // Overlays: just remove class normally (they'll be replaced by the new panel's overlay)
    $('.side-panel-overlay.active').removeClass('active');
    $('body').css('overflow', '');
    // Re-enable panel transitions on next frame
    requestAnimationFrame(function() {
        $('.side-panel').css('transition', '');
    });
}

// ---- Toast notification (Monday.com style) ----
window.showToast = function(message, type) {
    type = type || 'success';
    var icon = type === 'success'
        ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>'
        : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';

    var $toast = $('<div class="toast toast-' + type + '">' + icon + ' ' + escapeHtml(message) + '</div>');
    var $container = $('#toastContainer');
    if (!$container.length) {
        $container = $('<div id="toastContainer" role="status" aria-live="polite" aria-atomic="false"></div>');
        $('body').append($container);
    }
    $container.append($toast);

    // Stack toasts — offset each new toast above existing ones
    var offset = 24;
    $('.toast').not($toast).each(function() {
        offset += $(this).outerHeight() + 8;
    });
    $toast.css('bottom', offset + 'px');

    setTimeout(function() { $toast.addClass('show'); }, 10);
    setTimeout(function() {
        $toast.removeClass('show');
        setTimeout(function() { $toast.remove(); }, 400);
    }, 6000);
};

// ---- Panel loading spinner helper ----
window.panelLoadingHtml = function(message) {
    return '<div class="panel-loading"><div class="spinner"></div><span>' + escapeHtml(message || 'Loading...') + '</span></div>';
};

// ---- File upload: staging & helpers ----

// Format bytes to human-readable string
function formatFileSize(bytes) {
    if (bytes === 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB'];
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
}

// Get a display-friendly type label
function fileTypeBadge(file) {
    var type = file.type || '';
    if (type.indexOf('image/') === 0) {
        return '<span class="badge badge-info">Image</span>';
    } else if (type.indexOf('video/') === 0) {
        return '<span class="badge badge-primary">Video</span>';
    }
    return '<span class="badge">' + type + '</span>';
}

// Add files to the pending staging list (does NOT upload)
// Validates type, size, and total count before staging each file.
function stageFiles(fileList) {
    var allowedTypes = ['image/jpeg', 'image/png', 'video/mp4'];
    var maxSize = 500 * 1024 * 1024; // 500 MB
    var maxCount = 20;

    for (var i = 0; i < fileList.length; i++) {
        var file = fileList[i];

        // Check total count limit
        if (window._uploadPendingFiles.length >= maxCount) {
            showToast(file.name + ': Upload queue is full (max ' + maxCount + ' files).', 'error');
            break;
        }

        // Check file type
        if (allowedTypes.indexOf(file.type) === -1) {
            showToast(file.name + ': Unsupported file type. Use JPEG, PNG, or MP4.', 'error');
            continue;
        }

        // Check file size
        if (file.size > maxSize) {
            showToast(file.name + ': File exceeds 500 MB limit.', 'error');
            continue;
        }

        window._uploadPendingFiles.push(file);
    }
    renderFileList();
}

// Clear all staged files and reset UI
function clearStagedFiles() {
    window._uploadPendingFiles = [];
    renderFileList();
}

// Reset the upload panel back to its initial state
function resetUploadPanel() {
    window._uploadPendingFiles = [];
    $('#uploadSelectState').show();
    $('#uploadProgressState').hide();
    $('#uploadSuccessState').hide();
    $('#uploadFileList').hide();
    $('#uploadStartBtn').hide();
    $('#uploadFileListItems').empty();
    $('#uploadFileCountBadge').hide().text('');
    $('#uploadProgressBar').css('width', '0%');
    $('#uploadProgressText').text('0%');
    // Mark panel as not dirty so closing doesn't prompt
    $('#uploadPanel').data('dirty', false);
}

// Render the staged file list with thumbnails
function renderFileList() {
    var files = window._uploadPendingFiles;
    var $list = $('#uploadFileListItems');
    var $container = $('#uploadFileList');
    var $btn = $('#uploadStartBtn');
    var $badge = $('#uploadFileCountBadge');

    $list.empty();

    if (files.length === 0) {
        $container.hide();
        $btn.hide();
        $badge.hide().text('');
        return;
    }

    // Show file count badge in panel header
    $badge.text(files.length).show();

    for (var i = 0; i < files.length; i++) {
        var file = files[i];
        var isImage = file.type && file.type.indexOf('image/') === 0;
        var thumbHtml = '';

        if (isImage) {
            // Create a data-idx placeholder; actual thumbnail will be set via FileReader below
            thumbHtml = '<div class="upload-file-thumb" data-thumb-idx="' + i + '">' +
                '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>' +
                '</div>';
        } else {
            thumbHtml = '<div class="upload-file-thumb upload-file-thumb-video">' +
                '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5"><polygon points="5,3 19,12 5,21"/></svg>' +
                '</div>';
        }

        var rowHtml = '<div class="upload-file-row">' +
            thumbHtml +
            '<div class="upload-file-info">' +
                '<div class="upload-file-name" title="' + file.name + '">' + file.name + '</div>' +
                '<div class="upload-file-meta">' + formatFileSize(file.size) + ' &middot; ' + fileTypeBadge(file) + '</div>' +
            '</div>' +
            '<button type="button" class="upload-file-remove" data-idx="' + i + '" title="Remove file">' +
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
            '</button>' +
            '</div>';
        $list.append(rowHtml);

        // Generate image thumbnail via FileReader
        if (isImage) {
            (function(file, idx) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var $thumb = $list.find('[data-thumb-idx="' + idx + '"]');
                    $thumb.html('<img src="' + e.target.result + '" alt="">');
                };
                reader.readAsDataURL(file);
            })(file, i);
        }
    }

    $container.show();
    $btn.show().text('Upload ' + files.length + ' file' + (files.length > 1 ? 's' : ''));
}

// Start the actual upload
function startUpload() {
    var files = window._uploadPendingFiles;
    if (!files.length) return;

    var formData = new FormData();

    for (var i = 0; i < files.length; i++) {
        formData.append('files[]', files[i]);
    }

    // Multi-location support
    if ($('#uploadAllLocations').length && $('#uploadAllLocations').is(':checked')) {
        // All Locations — don't send location_id (server treats as company-wide)
    } else {
        var selectedLocs = [];
        $('.upload-loc-check:checked').each(function() {
            selectedLocs.push($(this).val());
        });
        if (selectedLocs.length > 0) {
            for (var li = 0; li < selectedLocs.length; li++) {
                formData.append('location_ids[]', selectedLocs[li]);
            }
        }
    }
    formData.append('csrf_token', $('meta[name="csrf-token"]').attr('content') || $('[name="csrf_token"]').val());

    var fileCount = files.length;

    // Switch to progress state
    $('#uploadSelectState').hide();
    $('#uploadProgressState').show();
    $('#uploadProgressLabel').text('Uploading ' + fileCount + ' file' + (fileCount > 1 ? 's' : '') + '...');
    $('#uploadProgressBar').css('width', '0%');
    $('#uploadProgressText').text('0%');

    $.ajax({
        url: BASE_URL + 'media/upload.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        xhr: function() {
            var xhr = $.ajaxSettings.xhr();
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    var pct = Math.round((e.loaded / e.total) * 100);
                    $('#uploadProgressBar').css('width', pct + '%');
                    $('#uploadProgressText').text(pct + '%');
                    if (pct >= 100) {
                        $('#uploadProgressLabel').text('Processing...');
                    }
                }
            };
            return xhr;
        },
        success: function(response) {
            $('#uploadProgressState').hide();
            if (response.success) {
                // Show success state
                $('#uploadSuccessMessage').text(fileCount + ' file' + (fileCount > 1 ? 's' : '') + ' uploaded successfully!');
                $('#uploadSuccessDetail').text(response.message || '');
                $('#uploadSuccessState').show();
                $('#uploadFileCountBadge').hide();
                // Refresh the media grid without a full page reload
                if (typeof refreshMediaGrid === 'function') {
                    refreshMediaGrid();
                }
            } else {
                showToast(response.message || 'Upload failed.', 'error');
                // Go back to select state so user can retry
                $('#uploadSelectState').show();
            }
            window._uploadPendingFiles = [];
        },
        error: function() {
            $('#uploadProgressState').hide();
            showToast('Upload failed. Please try again.', 'error');
            // Go back to select state so user can retry
            $('#uploadSelectState').show();
        }
    });
}

// ---- Styled Confirm Dialog ----
window.showConfirm = function(opts) {
    opts = opts || {};
    var title = opts.title || 'Are you sure?';
    var message = opts.message || '';
    var confirmText = opts.confirmText || 'Confirm';
    var cancelText = opts.cancelText || 'Cancel';
    var confirmClass = opts.confirmClass || 'btn-primary';

    // Remove any existing confirm dialog
    $('#confirmDialog').remove();

    var isDanger = confirmClass.indexOf('danger') !== -1;
    var iconBg = isDanger ? 'var(--monday-red-light)' : 'var(--monday-blue-light, #cce0ff)';
    var iconColor = isDanger ? 'var(--monday-red)' : 'var(--monday-blue)';
    var iconSvg = isDanger
        ? '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="' + iconColor + '" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
        : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="' + iconColor + '" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';

    var html = '<div id="confirmDialog" style="position:fixed;inset:0;z-index:10001;display:flex;align-items:center;justify-content:center;padding:32px">' +
        '<div class="confirm-backdrop" style="position:absolute;inset:0;background:rgba(24,27,52,0.45)"></div>' +
        '<div class="confirm-box" style="position:relative;background:var(--surface-primary);border-radius:var(--radius-xl);padding:28px 32px 24px;max-width:400px;width:100%;box-shadow:0 20px 48px rgba(24,27,52,0.18);animation:modalSlide 200ms cubic-bezier(0.25,0.1,0.25,1)">' +
            '<div style="display:flex;align-items:flex-start;gap:14px;margin-bottom:20px">' +
                '<div style="width:40px;height:40px;border-radius:var(--radius-full);background:' + iconBg + ';display:flex;align-items:center;justify-content:center;flex-shrink:0">' +
                    iconSvg +
                '</div>' +
                '<div>' +
                    '<div style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:4px">' + escapeHtml(title) + '</div>' +
                    (message ? '<div style="font-size:0.85rem;color:var(--text-secondary);line-height:1.5">' + escapeHtml(message) + '</div>' : '') +
                '</div>' +
            '</div>' +
            '<div style="display:flex;gap:8px;justify-content:flex-end">' +
                '<button class="btn btn-outline confirm-cancel">' + escapeHtml(cancelText) + '</button>' +
                '<button class="btn ' + confirmClass + ' confirm-ok">' + escapeHtml(confirmText) + '</button>' +
            '</div>' +
        '</div>' +
    '</div>';

    $('body').append(html);

    // Store the element that had focus before dialog opened
    var $previousFocus = $(document.activeElement);

    // ARIA attributes for accessibility
    $('#confirmDialog .confirm-box').attr({
        'role': 'alertdialog',
        'aria-modal': 'true',
        'aria-labelledby': 'confirmDialogTitle'
    });
    $('#confirmDialog .confirm-box').find('[style*="font-weight:700"]').first().attr('id', 'confirmDialogTitle');

    // Focus the cancel button by default (safer)
    setTimeout(function() { $('#confirmDialog .confirm-cancel').focus(); }, 50);

    // Event handlers
    $('#confirmDialog .confirm-cancel, #confirmDialog .confirm-backdrop').on('click', function() {
        $('#confirmDialog').remove();
        if ($previousFocus && $previousFocus.length) $previousFocus.focus();
        if (opts.onCancel) opts.onCancel();
    });

    $('#confirmDialog .confirm-ok').on('click', function() {
        $('#confirmDialog').remove();
        if ($previousFocus && $previousFocus.length) $previousFocus.focus();
        if (opts.onConfirm) opts.onConfirm();
    });

    // Escape key closes
    $(document).one('keydown.confirmDialog', function(e) {
        if (e.key === 'Escape') {
            $('#confirmDialog').remove();
            if ($previousFocus && $previousFocus.length) $previousFocus.focus();
            if (opts.onCancel) opts.onCancel();
        }
    });

    // Focus trap within confirm dialog
    $('#confirmDialog').on('keydown', function(e) {
        if (e.key !== 'Tab') return;
        var $focusable = $(this).find('button:visible');
        var $first = $focusable.first();
        var $last = $focusable.last();
        if (e.shiftKey) {
            if ($(document.activeElement).is($first)) { e.preventDefault(); $last.focus(); }
        } else {
            if ($(document.activeElement).is($last)) { e.preventDefault(); $first.focus(); }
        }
    });
};

// ---- AJAX helper ----
function ajaxPost(url, data, onSuccess) {
    data.csrf_token = $('meta[name="csrf-token"]').attr('content') || $('[name="csrf_token"]').val();
    $.ajax({
        url: url,
        type: 'POST',
        data: data,
        dataType: 'json',
        success: function(resp) {
            if (resp.success) {
                if (onSuccess) onSuccess(resp);
            } else {
                showToast(resp.message || 'An error occurred.', 'error');
            }
        },
        error: function() {
            showToast('Request failed. Please try again.', 'error');
        }
    });
}

// ---- Reusable client-side table filter ----
window.filterTable = function(opts) {
    var searchVal = (opts.search || '').toLowerCase();
    var filters = opts.filters || {};
    var $rows = $(opts.rowSelector || 'table tbody tr');
    var emptySelector = opts.emptySelector || null;
    var visibleCount = 0;

    $rows.each(function() {
        var $row = $(this);
        // Skip empty state rows
        if ($row.find('.empty-state').length || $row.attr('id') === 'noFilterResults') {
            $row.remove();
            return;
        }

        var show = true;

        // Text search across specified data attributes
        if (searchVal && opts.searchFields) {
            var matchSearch = false;
            for (var i = 0; i < opts.searchFields.length; i++) {
                var val = ($row.data(opts.searchFields[i]) || '').toString().toLowerCase();
                if (val.indexOf(searchVal) > -1) { matchSearch = true; break; }
            }
            if (!matchSearch) show = false;
        }

        // Dropdown filters: match data-attribute === filter value
        if (show) {
            for (var key in filters) {
                if (filters[key] && ($row.data(key) || '').toString() !== filters[key]) {
                    show = false;
                    break;
                }
            }
        }

        if (show) {
            $row.show();
            visibleCount++;
        } else {
            $row.hide();
        }
    });

    // Show empty state if no results
    if (visibleCount === 0 && opts.emptyMessage) {
        var colspan = opts.colspan || $rows.first().find('td').length || 6;
        $(opts.tableBody || 'table tbody').append(
            '<tr id="noFilterResults"><td colspan="' + colspan + '" class="text-center text-muted" style="padding:2rem">' + escapeHtml(opts.emptyMessage) + '</td></tr>'
        );
    }

    return visibleCount;
};

// Debounce utility
window.debounce = function(fn, delay) {
    var timer;
    return function() {
        var context = this, args = arguments;
        clearTimeout(timer);
        timer = setTimeout(function() { fn.apply(context, args); }, delay);
    };
};

// ---- Loading skeleton helper ----
window.showTableSkeleton = function(selector, rows, cols) {
    // Replaces tbody content with skeleton rows
    // rows default 5, cols default from existing thead th count
    var $tbody = $(selector);
    if (!$tbody.length) return;
    cols = cols || $tbody.closest('table').find('thead th').length || 4;
    rows = rows || 5;
    var html = '';
    for (var r = 0; r < rows; r++) {
        html += '<tr class="skeleton-row">';
        for (var c = 0; c < cols; c++) {
            html += '<td><div class="skeleton skeleton-text" style="width:' + (60 + Math.random() * 30) + '%"></div></td>';
        }
        html += '</tr>';
    }
    $tbody.html(html);
};

// ---- AJAX retry wrapper ----
window.ajaxWithRetry = function(opts, maxRetries) {
    maxRetries = maxRetries || 2;
    var attempts = 0;

    function tryRequest() {
        attempts++;
        return $.ajax(opts).fail(function(xhr, status, err) {
            if (attempts <= maxRetries && (status === 'timeout' || xhr.status === 0 || xhr.status >= 500)) {
                // Wait with exponential backoff, then retry
                var delay = Math.min(1000 * Math.pow(2, attempts - 1), 5000);
                setTimeout(tryRequest, delay);
            } else if (opts.error) {
                opts.error(xhr, status, err);
            }
        });
    }

    return tryRequest();
};

// ---- Table pagination utility ----
// Usage: var pager = new TablePaginator({ tableBody: '#myTbody', pager: '#myPager', perPage: 25 });
// Call pager.refresh() after filterTable runs.
window.TablePaginator = function(opts) {
    this.tableBody = opts.tableBody;
    this.pager = opts.pager;
    this.perPage = opts.perPage || 25;
    this.currentPage = 1;
};

TablePaginator.prototype.refresh = function() {
    var $rows = $(this.tableBody).children('tr').not('.skeleton-row, #noFilterResults');
    var $visible = $rows.filter(function() { return $(this).css('display') !== 'none'; });

    // If filtering already reduced results below perPage, no need to paginate
    var total = $visible.length;
    var totalPages = Math.ceil(total / this.perPage) || 1;
    if (this.currentPage > totalPages) this.currentPage = totalPages;
    var start = (this.currentPage - 1) * this.perPage;
    var end = start + this.perPage;

    // Temporarily show all filtered-visible rows, then hide ones outside page range
    var idx = 0;
    $visible.each(function() {
        if (idx >= start && idx < end) {
            $(this).show();
        } else {
            $(this).addClass('paginated-hidden').hide();
        }
        idx++;
    });

    this._render(totalPages, total);
};

TablePaginator.prototype.goTo = function(page) {
    // First show all paginated-hidden rows so filter state is restored
    $(this.tableBody).children('tr.paginated-hidden').removeClass('paginated-hidden').show();
    this.currentPage = page;
    this.refresh();
};

TablePaginator.prototype._render = function(totalPages, totalItems) {
    var $el = $(this.pager);
    if (totalPages <= 1) { $el.html(''); return; }
    var self = this;
    var p = this.currentPage;
    var html = '<div class="pagination">';
    html += '<button class="pagination-btn"' + (p === 1 ? ' disabled' : '') + ' data-page="' + (p - 1) + '">&lsaquo; Prev</button>';
    for (var i = 1; i <= totalPages; i++) {
        if (totalPages > 7) {
            if (i === 1 || i === totalPages || (i >= p - 1 && i <= p + 1)) {
                html += '<button class="pagination-btn' + (i === p ? ' active' : '') + '" data-page="' + i + '">' + i + '</button>';
            } else if (i === p - 2 || i === p + 2) {
                html += '<span class="pagination-dots">&hellip;</span>';
            }
        } else {
            html += '<button class="pagination-btn' + (i === p ? ' active' : '') + '" data-page="' + i + '">' + i + '</button>';
        }
    }
    html += '<button class="pagination-btn"' + (p === totalPages ? ' disabled' : '') + ' data-page="' + (p + 1) + '">Next &rsaquo;</button>';
    html += '<span class="pagination-info">' + totalItems + ' rows</span>';
    html += '</div>';
    $el.html(html);
    $el.find('.pagination-btn').on('click', function() {
        var pg = parseInt($(this).data('page'));
        if (!isNaN(pg)) self.goTo(pg);
    });
};

// ---- Sidebar collapse ----
$(function() {
    var $sidebar = $('#sidebar');
    var $btn = $('#sidebarCollapseBtn');
    var KEY = 'sidebarCollapsed';

    // Restore state
    if (localStorage.getItem(KEY) === '1') {
        $sidebar.addClass('collapsed');
    }

    $btn.on('click', function() {
        $sidebar.toggleClass('collapsed');
        localStorage.setItem(KEY, $sidebar.hasClass('collapsed') ? '1' : '0');
    });
});

// ---- Keyboard accessible location cards ----
$(function() {
    $('.location-card').each(function() {
        var $card = $(this);
        if ($card.attr('onclick')) {
            $card.attr('role', 'link').attr('tabindex', '0');
            $card.on('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        }
    });
});

// ---- Keyboard accessible table rows with onclick ----
$(function() {
    $('tr[onclick]').each(function() {
        var $row = $(this);
        if (!$row.attr('role')) {
            $row.attr('role', 'button').attr('tabindex', '0');
        }
    });
});

// ---- Help & Support Panel ----
window.showHelpPanel = function() {
    // Remove existing help panel
    $('#helpPanel, #helpPanelOverlay').remove();

    var html = '<div class="side-panel-overlay active" id="helpPanelOverlay"></div>' +
        '<div class="side-panel active" id="helpPanel" role="dialog" aria-modal="true" aria-label="Help and Support">' +
            '<div class="side-panel-header">' +
                '<h2>Help & Support</h2>' +
                '<button class="side-panel-close" aria-label="Close">&times;</button>' +
            '</div>' +
            '<div class="side-panel-body" style="padding:24px">' +
                '<h3 style="font-size:1rem;font-weight:700;margin-bottom:16px">How PharmaCMS Works</h3>' +
                '<div style="display:flex;flex-direction:column;gap:16px">' +
                    '<div style="display:flex;gap:12px;align-items:flex-start">' +
                        '<div style="width:28px;height:28px;border-radius:50%;background:var(--monday-blue-light);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700;font-size:0.75rem;color:var(--monday-blue)">1</div>' +
                        '<div><strong>Add Locations</strong><p class="text-sm text-muted" style="margin-top:2px">Register your pharmacy locations in the system.</p></div>' +
                    '</div>' +
                    '<div style="display:flex;gap:12px;align-items:flex-start">' +
                        '<div style="width:28px;height:28px;border-radius:50%;background:var(--monday-blue-light);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700;font-size:0.75rem;color:var(--monday-blue)">2</div>' +
                        '<div><strong>Register Screens</strong><p class="text-sm text-muted" style="margin-top:2px">Add each display/TV at your locations as a screen.</p></div>' +
                    '</div>' +
                    '<div style="display:flex;gap:12px;align-items:flex-start">' +
                        '<div style="width:28px;height:28px;border-radius:50%;background:var(--monday-blue-light);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700;font-size:0.75rem;color:var(--monday-blue)">3</div>' +
                        '<div><strong>Upload Media</strong><p class="text-sm text-muted" style="margin-top:2px">Upload images and videos for your displays (JPEG, PNG, MP4).</p></div>' +
                    '</div>' +
                    '<div style="display:flex;gap:12px;align-items:flex-start">' +
                        '<div style="width:28px;height:28px;border-radius:50%;background:var(--monday-blue-light);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700;font-size:0.75rem;color:var(--monday-blue)">4</div>' +
                        '<div><strong>Create Content Rotations</strong><p class="text-sm text-muted" style="margin-top:2px">Organize media into rotations that cycle on your screens.</p></div>' +
                    '</div>' +
                    '<div style="display:flex;gap:12px;align-items:flex-start">' +
                        '<div style="width:28px;height:28px;border-radius:50%;background:var(--monday-blue-light);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700;font-size:0.75rem;color:var(--monday-blue)">5</div>' +
                        '<div><strong>Assign to Screens</strong><p class="text-sm text-muted" style="margin-top:2px">Set which content rotation plays on each screen.</p></div>' +
                    '</div>' +
                '</div>' +
                '<div style="margin-top:24px;padding-top:16px;border-top:1px solid var(--border-light)">' +
                    '<h3 style="font-size:0.95rem;font-weight:700;margin-bottom:12px">Common Terms</h3>' +
                    '<div style="display:flex;flex-direction:column;gap:8px">' +
                        '<div><strong class="text-sm">Screen</strong> <span class="text-sm text-muted">— A TV or display device at one of your locations</span></div>' +
                        '<div><strong class="text-sm">Content Rotation</strong> <span class="text-sm text-muted">— A sequence of images/videos that cycle on a screen</span></div>' +
                        '<div><strong class="text-sm">Media</strong> <span class="text-sm text-muted">— Images and videos uploaded to the system</span></div>' +
                        '<div><strong class="text-sm">Emergency Broadcast</strong> <span class="text-sm text-muted">— An urgent message that overrides all screen content</span></div>' +
                    '</div>' +
                '</div>' +
                '<div style="margin-top:24px;padding-top:16px;border-top:1px solid var(--border-light)">' +
                    '<h3 style="font-size:0.95rem;font-weight:700;margin-bottom:8px">User Roles</h3>' +
                    '<div style="display:flex;flex-direction:column;gap:8px">' +
                        '<div><strong class="text-sm">Administrator</strong> <span class="text-sm text-muted">— Full access: manage locations, screens, users, and emergency broadcasts</span></div>' +
                        '<div><strong class="text-sm">Location Manager</strong> <span class="text-sm text-muted">— Manage media and screens at assigned locations</span></div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>';

    $('body').append(html);
    $('body').css('overflow', 'hidden');

    // Focus trap and close handlers
    setTimeout(function() { $('#helpPanel .side-panel-close').focus(); }, 180);

    $('#helpPanelOverlay, #helpPanel .side-panel-close').on('click', function() {
        $('#helpPanel, #helpPanelOverlay').remove();
        $('body').css('overflow', '');
    });
};
