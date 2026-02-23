/**
 * Email Lists Table Management
 * Handles dynamic loading and interaction with email lists table
 */
(function ($) {
    'use strict';

    var config = window.gdcEmailConfig || {};
    var currentListId = null;

    /**
     * Load and populate lists table from REST API
     */
    function loadListsTable() {
        var $table = $('#gdc-email-lists-table');
        var $tbody = $table.find('tbody');

        if (!$table.length) {
            return; // Table not on page
        }

        // Show loading state
        $tbody.html('<tr><td colspan="5" style="text-align:center; padding: 40px;"><div class="spinner is-active" style="float:none; margin: 0 auto;"></div> Loading lists...</td></tr>');

        console.log('[GDC Email List] Loading table from:', config.listsEndpoint || config.root + 'em/v1/email-lists');
        // Fetch lists from API
        $.ajax({
            url: config.listsEndpoint || config.root + 'em/v1/email-lists',
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
            }
        })
            .done(function (response) {
                console.log('[GDC Email List] Table data received:', response);
                var lists = response && response.lists ? response.lists : (Array.isArray(response) ? response : []);

                if (!lists || lists.length === 0) {
                    $tbody.html('<tr><td colspan="5" style="text-align:center; padding: 40px; color: #64748b;">No lists found. Click "Add New List" to create one.</td></tr>');
                    return;
                }

                // Clear and populate table
                $tbody.empty();
                $.each(lists, function (i, list) {
                    var createdDate = list.created_at ? new Date(list.created_at).toLocaleDateString() : 'N/A';
                    var description = list.description || '<span style="color: #94a3b8;">No description</span>';
                    var subscriberCount = list.subscriber_count || 0;

                    var $row = $('<tr>');
                    $row.append('<td><strong>' + escapeHtml(list.name) + '</strong></td>');
                    $row.append('<td>' + description + '</td>');
                    $row.append('<td>' + subscriberCount + '</td>');
                    $row.append('<td>' + createdDate + '</td>');

                    // Actions column
                    var $actions = $('<td></td>');
                    var $viewBtn = $('<button class="button button-small gdc-list-view-btn">View</button>').data('listId', list.id);
                    var $deleteBtn = $('<button class="button button-small button-link-delete gdc-list-delete-btn" style="margin-left: 5px;">Delete</button>')
                        .data('listId', list.id)
                        .data('listName', list.name);

                    $actions.append($viewBtn).append($deleteBtn);
                    $row.append($actions);

                    $tbody.append($row);
                });
            })
            .fail(function (xhr) {
                var msg = 'Failed to load lists.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                $tbody.html('<tr><td colspan="5" style="text-align:center; padding: 40px; color: #ef4444;">' + escapeHtml(msg) + '</td></tr>');
            });
    }

    /**
     * Helper to escape HTML
     */
    function escapeHtml(text) {
        return $('<div/>').text(text).html();
    }

    /**
     * Delete list with confirmation
     */
    function deleteList(listId, listName) {
        if (!confirm('Are you sure you want to delete the list "' + listName + '"?\n\nThis will remove all subscriber associations with this list.')) {
            return;
        }

        $.ajax({
            url: (config.listsEndpoint || config.root + 'em/v1/email-lists') + '/' + listId,
            method: 'DELETE',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
            }
        })
            .done(function () {
                // Refresh table
                loadListsTable();
            })
            .fail(function (xhr) {
                var msg = 'Failed to delete list.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                alert(msg);
            });
    }

    /**
     * View list details
     */
    function viewList(listId) {
        currentListId = listId;

        // Fetch list details
        $.ajax({
            url: (config.listsEndpoint || config.root + 'em/v1/email-lists') + '/' + listId,
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
            }
        })
            .done(function (response) {
                var list = response && response.list ? response.list : response;

                // Populate modal with list details
                $('#gdc-view-list-title').text(list.name);
                $('#gdc-view-list-name').text(list.name);
                $('#gdc-view-list-description').text(list.description || 'No description');
                $('#gdc-view-list-count').text(list.subscriber_count || 0);
                $('#gdc-view-list-created').text(list.created_at ? new Date(list.created_at).toLocaleString() : 'N/A');

                // Auto-enrollment info
                var autoEnrollText = 'Disabled';
                if (list.auto_enroll_rules && list.auto_enroll_rules.enabled) {
                    autoEnrollText = 'Enabled';
                }
                $('#gdc-view-list-auto-enroll').text(autoEnrollText);

                // Show modal
                $('.gdc-email-view-list-modal').addClass('open').attr('aria-hidden', 'false');

                // Load subscribers for this list
                loadListSubscribers(listId);
            })
            .fail(function (xhr) {
                var msg = 'Failed to load list details.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                alert(msg);
            });
    }

    /**
     * Load subscribers for a list
     */
    function loadListSubscribers(listId) {
        var $tbody = $('#gdc-view-list-subscribers-table tbody');
        $tbody.html('<tr><td colspan="5" style="text-align:center; padding: 20px;"><div class="spinner is-active" style="float:none; margin: 0 auto;"></div> Loading subscribers...</td></tr>');

        $.ajax({
            url: (config.listsEndpoint || config.root + 'em/v1/email-lists') + '/' + listId + '/subscribers',
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
            }
        })
            .done(function (response) {
                var subscribers = response && response.subscribers ? response.subscribers : (Array.isArray(response) ? response : []);

                if (!subscribers || subscribers.length === 0) {
                    $tbody.html('<tr><td colspan="5" style="text-align:center; padding: 20px; color: #64748b;">No subscribers yet.</td></tr>');
                    return;
                }

                $tbody.empty();
                $.each(subscribers, function (i, sub) {
                    var $row = $('<tr>');
                    $row.append('<td>' + escapeHtml(sub.email) + '</td>');
                    $row.append('<td>' + escapeHtml(sub.first_name || '-') + '</td>');
                    $row.append('<td>' + escapeHtml(sub.last_name || '-') + '</td>');
                    $row.append('<td><span style="color: ' + (sub.status === 'subscribed' ? '#22c55e' : '#64748b') + ';">' + sub.status + '</span></td>');

                    var $removeBtn = $('<button class="button button-small button-link-delete gdc-remove-subscriber-btn">Remove</button>')
                        .data('subscriberId', sub.id)
                        .data('email', sub.email);
                    $row.append($('<td>').append($removeBtn));

                    $tbody.append($row);
                });
            })
            .fail(function () {
                $tbody.html('<tr><td colspan="5" style="text-align:center; padding: 20px; color: #ef4444;">Failed to load subscribers.</td></tr>');
            });
    }

    /**
     * Close view list modal
     */
    function closeViewListModal() {
        $('.gdc-email-view-list-modal').removeClass('open').attr('aria-hidden', 'true');
        currentListId = null;
    }

    /**
     * Create modal HTML
     */
    function createViewListModal() {
        var html = `
<div class="gdc-email-view-list-modal" aria-hidden="true">
  <div class="gdc-email-add-modal__container" style="max-width: 900px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
      <h3 id="gdc-view-list-title">View List</h3>
      <button type="button" class="button" data-action="close-view">✕ Close</button>
    </div>
    
    <!-- Tabs -->
    <div class="gdc-list-tabs" style="border-bottom: 2px solid #e2e8f0; margin-bottom: 20px;">
      <button class="gdc-list-tab active" data-tab="details" style="padding: 10px 20px; border: none; background: none; cursor: pointer; border-bottom: 2px solid #6366f1; color: #6366f1; font-weight: 600;">Details</button>
      <button class="gdc-list-tab" data-tab="subscribers" style="padding: 10px 20px; border: none; background: none; cursor: pointer; border-bottom: 2px solid transparent; color: #64748b;">Subscribers</button>
    </div>
    
    <!-- Details Tab -->
    <div class="gdc-list-tab-content" data-content="details">
      <div class="gdc-form-field">
        <label>Name</label>
        <p id="gdc-view-list-name" style="font-weight: 600;">-</p>
      </div>
      <div class="gdc-form-field">
        <label>Description</label>
        <p id="gdc-view-list-description" style="color: #64748b;">-</p>
      </div>
      <div class="gdc-form-field">
        <label>Total Subscribers</label>
        <p id="gdc-view-list-count" style="font-weight: 600; font-size: 24px; color: #6366f1;">0</p>
      </div>
      <div class="gdc-form-field">
        <label>Created</label>
        <p id="gdc-view-list-created">-</p>
      </div>
      <div class="gdc-form-field">
        <label>Auto-Enrollment</label>
        <p id="gdc-view-list-auto-enroll">-</p>
      </div>
    </div>
    
    <!-- Subscribers Tab -->
    <div class="gdc-list-tab-content" data-content="subscribers" style="display: none;">
      <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
        <input type="search" id="gdc-view-list-search-sub" placeholder="Search subscribers..." style="flex: 1; margin-right: 10px;">
        <button type="button" class="button button-primary" id="gdc-view-list-add-sub">+ Add Subscriber</button>
        <button type="button" class="button" id="gdc-view-list-upload-csv" style="margin-left: 5px;">📤 Upload CSV</button>
      </div>
      <div id="gdc-view-list-subscribers-wrap" style="max-height: 400px; overflow-y: auto;">
        <table class="widefat striped" id="gdc-view-list-subscribers-table">
          <thead>
            <tr>
              <th>Email</th>
              <th>First Name</th>
              <th>Last Name</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>
`;
        $('body').append(html);
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // View list button
        $(document).on('click', '.gdc-list-view-btn', function () {
            var listId = $(this).data('listId');
            viewList(listId);
        });

        // Delete list button
        $(document).on('click', '.gdc-list-delete-btn', function () {
            var listId = $(this).data('listId');
            var listName = $(this).data('listName');
            deleteList(listId, listName);
        });

        // Close view modal
        $(document).on('click', '[data-action="close-view"]', closeViewListModal);
        $(document).on('click', '.gdc-email-view-list-modal', function (e) {
            if (e.target === e.currentTarget) {
                closeViewListModal();
            }
        });

        // Engagement subtab switching (Lists, Onboarding Emails, Newsletters)
        $(document).on('click', '.gdc-subtab', function () {
            var subtab = $(this).data('subtab');

            // Update subtab buttons
            $('.gdc-subtab').removeClass('active').css({
                'border-bottom': '2px solid transparent',
                'color': '#64748b',
                'font-weight': '500'
            });
            $(this).addClass('active').css({
                'border-bottom': '2px solid #6366f1',
                'color': '#6366f1',
                'font-weight': '600'
            });

            // Show corresponding content
            $('.gdc-subtab-panel').attr('hidden', true).hide();
            $('.gdc-subtab-panel[data-subpanel="' + subtab + '"]').removeAttr('hidden').show();
        });

        // Tab switching within list view modal
        $(document).on('click', '.gdc-list-tab', function () {
            var tab = $(this).data('tab');

            // Update tab buttons
            $('.gdc-list-tab').removeClass('active').css({
                'border-bottom': '2px solid transparent',
                'color': '#64748b',
                'font-weight': '400'
            });
            $(this).addClass('active').css({
                'border-bottom': '2px solid #6366f1',
                'color': '#6366f1',
                'font-weight': '600'
            });

            // Show corresponding content
            $('.gdc-list-tab-content').hide();
            $('.gdc-list-tab-content[data-content="' + tab + '"]').show();
        });

        // Add subscriber button
        $(document).on('click', '#gdc-view-list-add-sub', function () {
            if ($('#gdc-add-subscriber-form').length) {
                $('#gdc-add-subscriber-form').slideToggle();
                return;
            }
            var $form = $(`<div id="gdc-add-subscriber-form" style="background:#f8fafc;padding:15px;border-radius:6px;margin-bottom:15px;border:1px solid #e2e8f0"><h4 style="margin:0 0 10px 0">Add New Subscriber</h4><div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px"><input type="email" id="gdc-new-sub-email" placeholder="Email *" required style="padding:8px"><input type="text" id="gdc-new-sub-fname" placeholder="First Name" style="padding:8px"><input type="text" id="gdc-new-sub-lname" placeholder="Last Name" style="padding:8px"></div><div style="display:flex;gap:10px"><button type="button" class="button button-primary" id="gdc-submit-new-sub">Add</button><button type="button" class="button" id="gdc-cancel-new-sub">Cancel</button><span id="gdc-add-sub-status" style="margin-left:10px;display:none"></span></div></div>`);
            $('#gdc-view-list-subscribers-wrap').before($form);
        });

        $(document).on('click', '#gdc-cancel-new-sub', function () {
            $('#gdc-add-subscriber-form').slideUp(function () { $(this).remove(); });
        });

        $(document).on('click', '#gdc-submit-new-sub', function () {
            var email = $('#gdc-new-sub-email').val().trim();
            var $status = $('#gdc-add-sub-status'), $btn = $(this);
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { $status.text('Valid email required').css('color', '#ef4444').show(); return; }
            $btn.prop('disabled', true).text('Adding...'); $status.text('Adding...').css('color', '#22d3ee').show();
            $.ajax({
                url: config.subscribersEndpoint || config.root + 'em/v1/email-subscribers',
                method: 'POST',
                contentType: 'application/json',
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', config.nonce || ''); },
                data: JSON.stringify({
                    email: email,
                    first_name: $('#gdc-new-sub-fname').val().trim(),
                    last_name: $('#gdc-new-sub-lname').val().trim(),
                    status: 'subscribed'
                })
            })
                .done(function (sub) {
                    $.ajax({
                        url: (config.listsEndpoint || config.root + 'em/v1/email-lists') + '/' + currentListId + '/subscribers',
                        method: 'POST',
                        contentType: 'application/json',
                        beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', config.nonce || ''); },
                        data: JSON.stringify({ subscriber_id: sub.id })
                    })
                        .done(function () {
                            $status.text('Added!').css('color', '#22c55e').show(); $('#gdc-new-sub-email,#gdc-new-sub-fname,#gdc-new-sub-lname').val('');
                            setTimeout(function () { loadListSubscribers(currentListId); loadListsTable(); $('#gdc-add-subscriber-form').slideUp(function () { $('#gdc-add-subscriber-form').remove(); }); }, 1000);
                        })
                        .fail(function (xhr) {
                            var errorMsg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed';
                            $status.text(errorMsg).css('color', '#ef4444').show(); $btn.prop('disabled', false).text('Add');
                        });
                })
                .fail(function (xhr) {
                    var errorMsg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed';
                    $status.text(errorMsg).css('color', '#ef4444').show(); $btn.prop('disabled', false).text('Add');
                });
        });

        // Upload CSV button
        $(document).on('click', '#gdc-view-list-upload-csv', function () {
            var $fileInput = $('<input type="file" accept=".csv" style="display:none;">');
            $fileInput.on('change', function (e) {
                var file = e.target.files[0];
                if (!file || !file.name.endsWith('.csv')) { alert('Please select a CSV file.'); return; }
                var $status = $('<div id="gdc-csv-upload-status" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:30px;border-radius:8px;box-shadow:0 4px 6px rgba(0,0,0,0.1);z-index:10000;text-align:center;"><div class="spinner is-active" style="float:none;margin:0 auto 15px;"></div><p style="margin:0;font-weight:600;color:#6366f1;">Uploading and processing CSV...</p></div>');
                $('body').append($status);
                var reader = new FileReader();
                reader.onload = function (event) {
                    var lines = event.target.result.split('\n').filter(function (l) { return l.trim(); });
                    if (lines.length < 2) { $status.remove(); alert('CSV must contain header + data rows.'); return; }
                    var headers = lines[0].toLowerCase().split(',').map(function (h) { return h.trim(); });
                    var emailIdx = headers.findIndex(function (h) { return h.includes('email'); });
                    var fnameIdx = headers.findIndex(function (h) { return h.includes('first'); });
                    var lnameIdx = headers.findIndex(function (h) { return h.includes('last'); });
                    if (emailIdx === -1) { $status.remove(); alert('CSV must contain an "email" column.'); return; }
                    var subscribers = [];
                    for (var i = 1; i < lines.length; i++) {
                        var cols = lines[i].split(',').map(function (c) { return c.trim().replace(/^"|"$/g, ''); });
                        var email = cols[emailIdx];
                        if (email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                            subscribers.push({ email: email, first_name: fnameIdx !== -1 ? cols[fnameIdx] : '', last_name: lnameIdx !== -1 ? cols[lnameIdx] : '' });
                        }
                    }
                    if (subscribers.length === 0) { $status.remove(); alert('No valid emails found.'); return; }
                    $status.find('p').text('Adding ' + subscribers.length + ' subscribers...');
                    $.ajax({
                        url: (config.listsEndpoint || config.root + 'em/v1/email-lists') + '/' + currentListId + '/subscribers/import',
                        method: 'POST',
                        contentType: 'application/json',
                        beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', config.nonce || ''); },
                        data: JSON.stringify({ subscribers: subscribers })
                    })
                        .done(function (result) {
                            $status.find('.spinner').removeClass('is-active');
                            $status.find('p').html('<span style="color:#22c55e">✓ Imported ' + (result.added || subscribers.length) + '!</span>');
                            setTimeout(function () { $status.remove(); loadListSubscribers(currentListId); loadListsTable(); }, 2000);
                        })
                        .fail(function (xhr) {
                            $status.find('.spinner').removeClass('is-active');
                            var errorMsg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Import failed';
                            $status.find('p').html('<span style="color:#ef4444">✗ ' + errorMsg + '</span><br><button class="button" onclick="$(\'#gdc-csv-upload-status\').remove()" style="margin-top:10px">Close</button>');
                        });
                };
                reader.readAsText(file);
            });
            $fileInput.click();
        });

        // Remove subscriber button
        $(document).on('click', '.gdc-remove-subscriber-btn', function () {
            var subscriberId = $(this).data('subscriberId'), email = $(this).data('email');
            if (!confirm('Remove "' + email + '" from this list?')) return;
            $.ajax({
                url: (config.listsEndpoint || config.root + 'em/v1/email-lists') + '/' + currentListId + '/subscribers/' + subscriberId,
                method: 'DELETE',
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', config.nonce || ''); }
            })
                .done(function () { loadListSubscribers(currentListId); loadListsTable(); })
                .fail(function (xhr) {
                    var errorMsg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to remove subscriber.';
                    alert(errorMsg);
                });
        });
    }

    /**
     * Initialize
     */
    function init() {
        createViewListModal();
        bindEvents();
        loadListsTable();
    }

    // Expose globally for external refresh
    window.gdcRefreshListsTable = loadListsTable;

    // Initialize on DOM ready
    $(document).ready(function () {
        // Only init on email page with lists table
        if ($('#gdc-email-lists-table').length) {
            init();
        }
    });

})(jQuery);
