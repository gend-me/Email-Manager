/**
 * Email AI Popup - JavaScript
 * AI-integrated email editor with Vertex AI chat for copy generation
 */
(function ($) {
    'use strict';

    // State
    var state = {
        currentEmail: null,
        chatHistory: [],
        isGenerating: false,
        recipients: [],
        sendMode: 'immediate', // 'immediate' or 'schedule'
        scheduleDate: null,
        scheduleTime: '09:00',
        // Campaign Architect workflow state
        workflow: {
            currentStep: 'strategy', // strategy | subject_lines | body_copy | visual_assets | theme_send
            campaignType: null,
            audienceType: null,
            subjectOptions: [],
            selectedSubject: null,
            bodyBulletPoints: [],
            visualStyle: null,
            layoutChoice: null,
            theme: null,
            buttonStyle: null
        }
    };

    // DOM refs
    var $modal = null;
    var $addModal = null;
    var $chatThread = null;
    var $chatInput = null;
    var $subjectField = null;
    var $bodyField = null;
    var $preheaderField = null;

    // Config from WordPress
    var config = window.GDC_EMAIL_AI_CONFIG || {};

    // Conversation state tracking
    var conversationState = {
        phase: 'initial',           // initial, subject, body, styling, review
        draftSubject: null,          // Current draft subject
        draftBody: null,             // Current draft body (plain text)
        draftHtml: null,             // Current draft HTML
        lastUserMessage: null,       // Last message from user
        lastIntent: null,            // Detected intent of last message
        pendingOptions: {
            subjects: [],            // Array of subject line options
            bodies: [],              // Array of body content options
            selectedIndex: null      // Which option user selected (global tracking)
        },
        chatHistory: []              // Full conversation history for context
    };

    /**
     * Initialize the popup system
     */
    function init() {
        if ($('.gdc-email-ai-modal').length) {
            return; // Already initialized
        }

        // Create popup HTML
        createPopupHTML();

        // Cache refs
        $modal = $('.gdc-email-ai-modal');
        $addModal = $('.gdc-email-add-modal');
        $chatThread = $('.gdc-email-ai-modal__chat-thread');
        $chatInput = $('.gdc-email-ai-modal__chat-input textarea');
        $subjectField = $('#gdc-email-ai-subject');
        $bodyField = $('#gdc-email-ai-body');
        $preheaderField = $('#gdc-email-ai-preheader');

        // Bind events
        bindEvents();

        // Initial chat greeting
        addChatMessage('assistant', 'Hi! I\'m Leo, your AI email assistant. I can help you:\n\n• Write compelling email copy\n• Generate or revise images\n• Create a complete HTML email with your brand styling\n\nWhat would you like to create today?');
    }

    /**
     * Create popup HTML and inject into page
     */
    function createPopupHTML() {
        var leoIcon = config.leoIcon || '';
        var logoUrl = config.siteLogo || '';

        var html = `
<!-- Email AI Editor Modal -->
<div class="gdc-email-ai-modal" aria-hidden="true">
  <div class="gdc-email-ai-modal__container">
    <div class="gdc-email-ai-modal__header">
      <div class="gdc-email-ai-modal__header-icon">✉</div>
      <div class="gdc-email-ai-modal__header-text">
        <h3>Email Editor</h3>
        <p class="gdc-email-ai-modal__email-label">New Email</p>
      </div>
      <button type="button" class="gdc-email-ai-modal__close">✕ Close</button>
    </div>
    <div class="gdc-email-ai-modal__body">
      <!-- Left: Email Editor -->
      <div class="gdc-email-ai-modal__editor">
        <div class="gdc-email-ai-modal__editor-header">
          <h4>Email Content</h4>
        </div>
        <div class="gdc-email-ai-modal__editor-form">
          <!-- Recipients (for Proposals) -->
          <div class="gdc-email-ai-field gdc-email-ai-recipients" style="display:none;">
            <label>Recipients</label>
            <div class="gdc-email-ai-recipients-wrap">
              <div class="gdc-email-ai-recipients-tags" id="gdc-email-ai-recipients-tags"></div>
              <input type="text" id="gdc-email-ai-recipient-search" placeholder="Search members or enter email..." autocomplete="off">
              <div class="gdc-email-ai-recipients-dropdown" id="gdc-email-ai-recipients-dropdown" style="display:none;"></div>
            </div>
          </div>
          <div class="gdc-email-ai-field">
            <label for="gdc-email-ai-subject">Subject Line</label>
            <input type="text" id="gdc-email-ai-subject" placeholder="Enter email subject...">
          </div>
          <div class="gdc-email-ai-field">
            <label for="gdc-email-ai-preheader">Preheader Text</label>
            <input type="text" id="gdc-email-ai-preheader" placeholder="Preview text shown in inbox...">
          </div>
          <div class="gdc-email-ai-field gdc-email-ai-field--body">
            <style>
              /* Custom Editor Styles */
              .gdc-email-editor-wrapper { border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; display: flex; flex-direction: column; height: 400px; background: #fff; }
              .dark-mode .gdc-email-editor-wrapper { border-color: #334155; background: #1e293b; }
              
              .gdc-email-editor-tabs { display: flex; background: #f1f5f9; border-bottom: 1px solid #e2e8f0; }
              .dark-mode .gdc-email-editor-tabs { background: #0f172a; border-color: #334155; }
              
              .gdc-editor-tab { padding: 8px 16px; border: none; background: transparent; cursor: pointer; font-size: 13px; font-weight: 500; color: #64748b; border-right: 1px solid transparent; }
              .gdc-editor-tab:hover { background: rgba(0,0,0,0.02); color: #334155; }
              .gdc-editor-tab.active { background: #fff; color: #3b82f6; border-right: 1px solid #e2e8f0; font-weight: 600; }
              .dark-mode .gdc-editor-tab { color: #94a3b8; }
              .dark-mode .gdc-editor-tab:hover { background: rgba(255,255,255,0.02); color: #e2e8f0; }
              .dark-mode .gdc-editor-tab.active { background: #1e293b; color: #60a5fa; border-right: 1px solid #334155; }

              .gdc-email-editor-toolbar { padding: 8px; border-bottom: 1px solid #e2e8f0; display: flex; gap: 4px; flex-wrap: wrap; background: #fff; }
              .dark-mode .gdc-email-editor-toolbar { border-color: #334155; background: #1e293b; color: #fff; }
              
              .gdc-editor-btn { width: 28px; height: 28px; border: 1px solid transparent; background: transparent; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #475569; }
              .dark-mode .gdc-editor-btn { color: #cbd5e1; }
              .gdc-editor-btn:hover { background: #f1f5f9; color: #1e293b; }
              .dark-mode .gdc-editor-btn:hover { background: #334155; color: #fff; }
              
              .gdc-email-editor-content { flex: 1; position: relative; overflow: hidden; }
              
              #gdc-email-visual-editor { width: 100%; height: 100%; padding: 16px; overflow-y: auto; outline: none; font-family: 'Segoe UI', sans-serif; font-size: 14px; line-height: 1.5; color: #000; }
              .dark-mode #gdc-email-visual-editor { color: #e2e8f0; }
              
              /* Code textarea covers the visual editor when active */
              #gdc-email-ai-body { width: 100%; height: 100%; padding: 16px; border: none; resize: none; font-family: 'Consolas', monospace; font-size: 13px; line-height: 1.5; background: #1e1e1e; color: #d4d4d4; display: none; }
              #gdc-email-ai-body.active { display: block; }
              .hidden-editor { display: none !important; }
            </style>
            
            <label for="gdc-email-ai-body">Email Body</label>
            <div class="gdc-email-editor-wrapper">
              <div class="gdc-email-editor-tabs">
                <button type="button" class="gdc-editor-tab active" data-mode="visual">Visual</button>
                <button type="button" class="gdc-editor-tab" data-mode="code">Code</button>
              </div>
              
              <!-- Toolbar (Visual Mode Only) -->
              <div class="gdc-email-editor-toolbar" id="gdc-editor-toolbar">
                <button type="button" class="gdc-editor-btn" data-cmd="bold" title="Bold"><b>B</b></button>
                <button type="button" class="gdc-editor-btn" data-cmd="italic" title="Italic"><i>I</i></button>
                <button type="button" class="gdc-editor-btn" data-cmd="underline" title="Underline"><u>U</u></button>
                <div style="width: 1px; height: 20px; background: #e2e8f0; margin: 0 4px;"></div>
                <button type="button" class="gdc-editor-btn" data-cmd="justifyLeft" title="Align Left"><span class="dashicons dashicons-editor-alignleft"></span></button>
                <button type="button" class="gdc-editor-btn" data-cmd="justifyCenter" title="Align Center"><span class="dashicons dashicons-editor-aligncenter"></span></button>
                <button type="button" class="gdc-editor-btn" data-cmd="justifyRight" title="Align Right"><span class="dashicons dashicons-editor-alignright"></span></button>
                <div style="width: 1px; height: 20px; background: #e2e8f0; margin: 0 4px;"></div>
                 <button type="button" class="gdc-editor-btn" data-cmd="insertUnorderedList" title="Bullet List"><span class="dashicons dashicons-editor-ul"></span></button>
                 <button type="button" class="gdc-editor-btn" data-cmd="insertOrderedList" title="Numbered List"><span class="dashicons dashicons-editor-ol"></span></button>
                 <div style="width: 1px; height: 20px; background: #e2e8f0; margin: 0 4px;"></div>
                 <button type="button" class="gdc-editor-btn" data-cmd="createLink" title="Link"><span class="dashicons dashicons-admin-links"></span></button>
                 <button type="button" class="gdc-editor-btn" data-cmd="unlink" title="Unlink"><span class="dashicons dashicons-editor-unlink"></span></button>
              </div>
              
              <div class="gdc-email-editor-content">
                <!-- Visual Area -->
                <div id="gdc-email-visual-editor" contenteditable="true" spellcheck="false"></div>
                <!-- Code Area -->
                <textarea id="gdc-email-ai-body" class="gdc-email-ai-wp-editor" placeholder="Enter or paste your email HTML content..."></textarea>
              </div>
            </div>
            <div style="font-size:11px; color:#94a3b8; margin-top:4px; text-align:right;">
             Generated code will appear in the <strong>Code</strong> tab.
            </div>
          </div>
          <div class="gdc-email-ai-field gdc-email-ai-test-email">
            <label for="gdc-email-ai-test-address">Send Test Email</label>
            <div class="gdc-email-ai-test-row">
              <input type="email" id="gdc-email-ai-test-address" placeholder="Enter email address for test...">
              <button type="button" class="gdc-email-ai-btn gdc-email-ai-btn--secondary gdc-email-ai-send-test">Send Test</button>
            </div>
            <div class="gdc-email-ai-test-status" style="display:none;"></div>
          </div>
          <!-- Send Options -->
          <div class="gdc-email-ai-field gdc-email-ai-send-options" style="display:none;">
            <label>Send Options</label>
            <div class="gdc-email-ai-send-toggle">
              <button type="button" class="gdc-email-ai-toggle-btn active" data-mode="immediate">Send Immediately</button>
              <button type="button" class="gdc-email-ai-toggle-btn" data-mode="schedule">Schedule</button>
            </div>
            <div class="gdc-email-ai-schedule-picker" style="display:none;">
              <div class="gdc-email-ai-schedule-row">
                <input type="date" id="gdc-email-ai-schedule-date" class="gdc-email-ai-date-input">
                <input type="time" id="gdc-email-ai-schedule-time" class="gdc-email-ai-time-input" value="09:00">
              </div>
              <div class="gdc-email-ai-schedule-quick">
                <button type="button" class="gdc-email-ai-quick-time" data-hours="1">In 1 hour</button>
                <button type="button" class="gdc-email-ai-quick-time" data-hours="24">Tomorrow</button>
                <button type="button" class="gdc-email-ai-quick-time" data-hours="168">Next week</button>
              </div>
            </div>
          </div>
        </div>
        <div class="gdc-email-ai-modal__editor-actions">
          <button type="button" class="gdc-email-ai-btn gdc-email-ai-btn--secondary gdc-email-ai-save">Save Draft</button>
          <button type="button" class="gdc-email-ai-btn gdc-email-ai-btn--secondary gdc-email-ai-preview">Preview</button>
          <button type="button" class="gdc-email-ai-btn gdc-email-ai-btn--primary gdc-email-ai-send">Send Email</button>
        </div>
      </div>
      
      <!-- Right: AI Chat -->
      <div class="gdc-email-ai-modal__chat">
        <div class="gdc-email-ai-modal__chat-header">
          <div class="gdc-email-ai-modal__chat-avatar" style="${leoIcon ? 'background-image: url(' + leoIcon + ')' : ''}"></div>
          <div class="gdc-email-ai-modal__chat-title">
            <h4>Build with Leo</h4>
            <p>AI Email Assistant</p>
          </div>
        </div>
        <div class="gdc-email-ai-modal__chat-thread"></div>
        <div class="gdc-email-ai-modal__chat-input">
          <textarea placeholder="Describe what you want to create..." rows="1"></textarea>
          <button type="button" class="gdc-email-ai-modal__chat-send">Send</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Add New Email Type Selector -->
<div class="gdc-email-add-modal" aria-hidden="true">
  <div class="gdc-email-add-modal__container">
    <h3>Add New Email</h3>
    <div class="gdc-email-add-types">
      <button type="button" class="gdc-email-add-type" data-type="timed">
        <span class="gdc-email-add-type-icon">⏰</span>
        <div><strong>Timed Email</strong><span>Newsletter or nurture journey</span></div>
      </button>
      <button type="button" class="gdc-email-add-type" data-type="store">
        <span class="gdc-email-add-type-icon">🛒</span>
        <div><strong>Transaction Email</strong><span>Order or payment event</span></div>
      </button>
      <button type="button" class="gdc-email-add-type" data-type="community">
        <span class="gdc-email-add-type-icon">👥</span>
        <div><strong>Community Email</strong><span>BuddyPress notification</span></div>
      </button>
      <button type="button" class="gdc-email-add-type" data-type="rewards">
        <span class="gdc-email-add-type-icon">🏆</span>
        <div><strong>Rewards Email</strong><span>Balance or reward change</span></div>
      </button>
      <button type="button" class="gdc-email-add-type" data-type="proposals">
        <span class="gdc-email-add-type-icon">📋</span>
        <div><strong>Proposal Email</strong><span>Client proposal notification</span></div>
      </button>
    </div>
    <button type="button" class="gdc-email-add-modal__close">Cancel</button>
  </div>
</div>

<!-- Add New List Modal -->
<div class="gdc-email-list-modal" aria-hidden="true">
  <div class="gdc-email-add-modal__container">
    <h3>Add New List</h3>
    <form id="gdc-add-list-form">
      <div class="gdc-form-field">
        <label for="gdc-list-name">Name *</label>
        <input type="text" id="gdc-list-name" name="name" required placeholder="e.g., Newsletter Subscribers">
      </div>
      <div class="gdc-form-field">
        <label for="gdc-list-description">Description</label>
        <textarea id="gdc-list-description" name="description" rows="3" placeholder="Brief description of this list..."></textarea>
      </div>
      <div class="gdc-form-field">
        <label><input type="checkbox" id="gdc-list-auto-enroll-enable"> Enable Auto-Enrollment</label>
      </div>
      <div id="gdc-list-auto-enroll-options" style="display:none; padding-left: 20px;">
        <div class="gdc-form-field">
          <label>User Roles</label>
          <select id="gdc-list-roles" multiple size="4">
            <option value="subscriber">Subscriber</option>
            <option value="contributor">Contributor</option>
            <option value="author">Author</option>
            <option value="editor">Editor</option>
            <option value="administrator">Administrator</option>
          </select>
        </div>
        <div class="gdc-form-field">
          <label>Member Types</label>
          <select id="gdc-list-member-types" multiple size="4">
            <!-- Dynamically populated -->
          </select>
        </div>
        <div class="gdc-form-field">
          <label>Purchase Products</label>
          <input type="text" id="gdc-list-products-search" placeholder="Search products...">
          <div id="gdc-list-selected-products" style="margin-top: 8px;"></div>
          <input type="hidden" id="gdc-list-products" />
        </div>
      </div>
      <div class="gdc-form-actions">
        <button type="button" class="button" data-action="cancel">Cancel</button>
        <button type="submit" class="button button-primary">Create List</button>
      </div>
      <div id="gdc-list-form-status" style="display:none; margin-top: 10px;"></div>
    </form>
  </div>
</div>
`;
        $('body').append(html);
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // CRITICAL: Unbind any existing handlers from the old inline script
        // so our new popup opens instead of the old iframe-based modal
        $(document).off('click', '.gdc-email-open-editor');
        $(document).off('click', '#gdc-email-scheduled-add');
        $(document).off('click', '#gdc-email-add-list'); // Unbind Elemailer handler



        // Open editor via Edit button
        $(document).on('click', '.gdc-email-open-editor', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var emailData = {};
            try {
                emailData = JSON.parse($(this).attr('data-email') || '{}');
            } catch (err) {
                console.warn('[GDC Email AI] Failed to parse email data', err);
            }

            // Always open custom editor for all types (Raw HTML Mode)
            openEditor(emailData);
            return false;
        });

        // Open Add New modal
        $(document).on('click', '#gdc-email-scheduled-add', function (e) {
            e.preventDefault();
            e.stopPropagation();
            openAddModal();
            return false;
        });

        // Add New List button - open our custom modal
        $(document).on('click', '#gdc-email-add-list', function (e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation(); // Stop Elemailer from handling this
            openListModal();
            return false;
        });

        // Add New Sequence - directly open timed email editor (Custom Popup)
        $(document).on('click', '#gdc-email-add-sequence', function (e) {
            e.preventDefault();
            e.stopPropagation();
            openEditor({
                section: 'timed',
                label: 'New Email Sequence',
                subject: '',
                html: '',
                description: ''
            });
            return false;
        });

        // Create Newsletter - directly open timed email editor (Custom Popup)
        $(document).on('click', '#gdc-email-add-newsletter', function (e) {
            e.preventDefault();
            e.stopPropagation();
            openEditor({
                section: 'timed',
                label: 'New Newsletter',
                subject: '',
                html: '',
                description: ''
            });
            return false;
        });

        // Add New Email (Proposals) - directly open proposals email editor
        $(document).on('click', '#gdc-email-add-proposal', function (e) {
            e.preventDefault();
            e.stopPropagation();
            openEditor({
                section: 'proposals',
                label: 'New Proposal Email',
                subject: '',
                html: '',
                description: ''
            });
            return false;
        });

        // Handle auto-enrollment checkbox toggle
        $(document).on('change', '#gdc-list-auto-enroll-enable', function () {
            if ($(this).is(':checked')) {
                $('#gdc-list-auto-enroll-options').slideDown();
            } else {
                $('#gdc-list-auto-enroll-options').slideUp();
            }
        });

        // Handle list form cancel
        $(document).on('click', '.gdc-email-list-modal [data-action="cancel"]', function (e) {
            e.preventDefault();
            closeListModal();
        });

        // Handle list form submission
        $(document).on('submit', '#gdc-add-list-form', function (e) {
            e.preventDefault();
            submitListForm();
        });

        // Close list modal when clicking outside
        $(document).on('click', '.gdc-email-list-modal', function (e) {
            if (e.target === e.currentTarget) {
                closeListModal();
            }
        });
        // Add new type selected
        $(document).on('click', '.gdc-email-add-type', function (e) {
            e.preventDefault();
            var type = $(this).data('type');
            closeAddModal();
            openEditor({
                section: type,
                label: getTypeLabel(type),
                subject: '',
                html: '',
                description: ''
            });
        });

        // Close modals
        $(document).on('click', '.gdc-email-ai-modal__close, .gdc-email-ai-modal', function (e) {
            if (e.target === e.currentTarget || $(e.target).hasClass('gdc-email-ai-modal__close')) {
                closeEditor();
            }
        });
        $(document).on('click', '.gdc-email-add-modal__close, .gdc-email-add-modal', function (e) {
            if (e.target === e.currentTarget || $(e.target).hasClass('gdc-email-add-modal__close')) {
                closeAddModal();
            }
        });

        // Prevent close when clicking container
        $(document).on('click', '.gdc-email-ai-modal__container, .gdc-email-add-modal__container', function (e) {
            e.stopPropagation();
        });

        // Send chat message
        $(document).on('click', '.gdc-email-ai-modal__chat-send', sendChatMessage);
        $(document).on('keydown', '.gdc-email-ai-modal__chat-input textarea', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendChatMessage();
            }
        });

        // Quick action buttons
        $(document).on('click', '.gdc-email-ai-quick-btn', function (e) {
            e.preventDefault();
            var action = $(this).data('action');
            handleQuickAction(action);
        });

        // Save email
        $(document).on('click', '.gdc-email-ai-save', saveEmail);

        // Preview email
        $(document).on('click', '.gdc-email-ai-preview', previewEmail);

        // Send test email
        $(document).on('click', '.gdc-email-ai-send-test', sendTestEmail);

        // Recipient search
        $(document).on('input', '#gdc-email-ai-recipient-search', debounce(searchRecipients, 300));
        $(document).on('keydown', '#gdc-email-ai-recipient-search', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addManualEmail($(this).val());
            }
        });
        $(document).on('click', '.gdc-email-ai-recipient-item', function (e) {
            e.preventDefault();
            addRecipient($(this).data('email'), $(this).data('name'), $(this).data('avatar'));
        });
        $(document).on('click', '.gdc-email-ai-recipient-remove', function (e) {
            e.preventDefault();
            e.stopPropagation();
            removeRecipient($(this).closest('.gdc-email-ai-recipient-tag').data('email'));
        });
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.gdc-email-ai-recipients-wrap').length) {
                $('#gdc-email-ai-recipients-dropdown').hide();
            }
        });

        // Send mode toggle
        $(document).on('click', '.gdc-email-ai-toggle-btn', function (e) {
            e.preventDefault();
            var mode = $(this).data('mode');
            state.sendMode = mode;
            $('.gdc-email-ai-toggle-btn').removeClass('active');
            $(this).addClass('active');
            if (mode === 'schedule') {
                $('.gdc-email-ai-schedule-picker').show();
                $('.gdc-email-ai-send').text('Schedule Email');
            } else {
                $('.gdc-email-ai-schedule-picker').hide();
                $('.gdc-email-ai-send').text('Send Email');
            }
        });

        // Quick time buttons
        $(document).on('click', '.gdc-email-ai-quick-time', function (e) {
            e.preventDefault();
            var hours = parseInt($(this).data('hours'));
            var date = new Date();
            date.setHours(date.getHours() + hours);
            $('#gdc-email-ai-schedule-date').val(date.toISOString().split('T')[0]);
            $('#gdc-email-ai-schedule-time').val(date.toTimeString().slice(0, 5));
        });

        // Send email
        $(document).on('click', '.gdc-email-ai-send', sendEmail);

        // =========================================================================
        // CAMPAIGN ARCHITECT EVENT HANDLERS
        // =========================================================================

        // Campaign type selection
        $(document).on('click', '.gdc-email-ai-campaign-btn', function (e) {
            e.preventDefault();
            var type = $(this).data('type');
            // Remove the action buttons after selection
            $('.gdc-email-ai-campaign-actions').remove();
            handleCampaignTypeSelection(type);
        });

        // Audience selection
        $(document).on('click', '.gdc-email-ai-audience-btn', function (e) {
            e.preventDefault();
            var audience = $(this).data('audience');
            $('.gdc-email-ai-campaign-actions').remove();
            handleAudienceSelection(audience);
        });

        // Subject line selection
        $(document).on('click', '.gdc-email-ai-subject-btn', function (e) {
            e.preventDefault();
            var index = parseInt($(this).data('index'));
            // Remove all subject line UI elements
            $('.gdc-email-ai-subject-options, .gdc-email-ai-revision-chips, .gdc-email-ai-custom-input').remove();
            handleSubjectLineSelection(index);
        });

        // Subject revision
        $(document).on('click', '.gdc-email-ai-revision-btn', function (e) {
            e.preventDefault();
            var modifier = $(this).data('modifier');
            // Hide revision tools while revising
            $('.gdc-email-ai-revision-chips').hide();
            handleSubjectRevision(modifier);
        });

        // Custom subject
        $(document).on('click', '.gdc-email-ai-custom-subject-btn', function (e) {
            e.preventDefault();
            var text = $(this).siblings('.gdc-email-ai-custom-subject').val();
            if (text && text.trim()) {
                $('.gdc-email-ai-subject-options, .gdc-email-ai-revision-chips, .gdc-email-ai-custom-input').remove();
                handleCustomSubjectLine(text);
            }
        });

        // Theme selection
        $(document).on('click', '.gdc-email-ai-theme-btn', function (e) {
            e.preventDefault();
            var theme = $(this).data('theme');
            applyTheme(theme);
        });

        // Subject line revision
        $(document).on('click', '.gdc-email-ai-revision-btn', function (e) {
            e.preventDefault();
            var modifier = $(this).data('modifier');
            // Remove current options
            $('.gdc-email-ai-subject-options, .gdc-email-ai-revision-chips, .gdc-email-ai-custom-input').remove();
            handleSubjectRevision(modifier);
        });

        // Custom subject line input
        $(document).on('click', '.gdc-email-ai-custom-subject-btn', function (e) {
            e.preventDefault();
            var text = $('.gdc-email-ai-custom-subject').val();
            if (text && text.trim()) {
                $('.gdc-email-ai-subject-options, .gdc-email-ai-revision-chips, .gdc-email-ai-custom-input').remove();
                handleCustomSubjectLine(text);
            }
        });

        $(document).on('keydown', '.gdc-email-ai-custom-subject', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var text = $(this).val();
                if (text && text.trim()) {
                    $('.gdc-email-ai-subject-options, .gdc-email-ai-revision-chips, .gdc-email-ai-custom-input').remove();
                    handleCustomSubjectLine(text);
                }
            }
        });

        // ESC to close
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') {
                if ($addModal.hasClass('open')) {
                    closeAddModal();
                } else if ($modal.hasClass('open')) {
                    closeEditor();
                }
            }
        });

        // =========================================================================
        // CUSTOM EDITOR EVENTS
        // =========================================================================

        // Tab Switching
        $(document).on('click', '.gdc-editor-tab', function (e) {
            e.preventDefault();
            var mode = $(this).data('mode');

            // Toggle Tabs
            $('.gdc-editor-tab').removeClass('active');
            $(this).addClass('active');

            if (mode === 'code') {
                // Sync Visual -> Code
                var html = $('#gdc-email-visual-editor').html();
                $('#gdc-email-ai-body').val(html).show().addClass('active');
                $('#gdc-email-visual-editor').hide();
                $('#gdc-editor-toolbar').hide();
            } else {
                // Sync Code -> Visual
                var code = $('#gdc-email-ai-body').val();
                $('#gdc-email-visual-editor').html(code).show();
                $('#gdc-email-ai-body').hide().removeClass('active');
                $('#gdc-editor-toolbar').show();
            }
        });

        // Toolbar Actions
        $(document).on('click', '.gdc-editor-btn', function (e) {
            e.preventDefault();
            var cmd = $(this).data('cmd');
            document.execCommand(cmd, false, null);
            // Sync immediately
            var html = $('#gdc-email-visual-editor').html();
            $('#gdc-email-ai-body').val(html);
        });

        // Sync Visual -> Code on Input
        $(document).on('input', '#gdc-email-visual-editor', function () {
            var html = $(this).html();
            $('#gdc-email-ai-body').val(html);
        });

        // Sync Code -> Visual on Input
        $(document).on('input', '#gdc-email-ai-body', function () {
            var code = $(this).val();
            $('#gdc-email-visual-editor').html(code);
        });
    }

    /**
     * Open the email editor popup
     */
    function openEditor(emailData) {
        // Ensure initialized (e.g. if called from another plugin page)
        if (!$modal || !$modal.length) {
            init();
        }

        hideOldModals(); // Close any old modals first
        state.currentEmail = emailData || {};
        state.chatHistory = [];
        state.recipients = [];
        state.sendMode = 'immediate';

        // Set form values
        $subjectField.val(emailData.subject || '');
        $preheaderField.val(emailData.preheader || emailData.description || '');

        // Set body value (will be synced to editor)
        var bodyContent = emailData.html || '';
        $bodyField.val(bodyContent);
        $('#gdc-email-visual-editor').html(bodyContent);

        // Reset tabs to Visual
        $('.gdc-editor-tab').removeClass('active');
        $('.gdc-editor-tab[data-mode="visual"]').addClass('active');
        $('#gdc-email-visual-editor').show();
        $('#gdc-email-ai-body').hide().removeClass('active');
        $('#gdc-editor-toolbar').show();

        // Update label
        var label = emailData.label || 'New Email';
        $('.gdc-email-ai-modal__email-label').text(label);

        // Clear test email status
        $('.gdc-email-ai-test-status').hide().text('');
        $('#gdc-email-ai-test-address').val('');

        // Reset recipients UI
        $('#gdc-email-ai-recipients-tags').empty();
        $('#gdc-email-ai-recipient-search').val('');
        $('#gdc-email-ai-recipients-dropdown').hide();

        // Reset schedule UI
        $('.gdc-email-ai-toggle-btn').removeClass('active');
        $('.gdc-email-ai-toggle-btn[data-mode="immediate"]').addClass('active');
        $('.gdc-email-ai-schedule-picker').hide();
        $('.gdc-email-ai-send').text('Send Email');
        var tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        $('#gdc-email-ai-schedule-date').val(tomorrow.toISOString().split('T')[0]);
        $('#gdc-email-ai-schedule-time').val('09:00');

        // Show/hide proposal-specific sections
        var isProposal = (emailData.section || '').toLowerCase() === 'proposals';
        if (isProposal) {
            $('.gdc-email-ai-recipients').show();
            $('.gdc-email-ai-send-options').show();
            $('.gdc-email-ai-send').show();
        } else {
            $('.gdc-email-ai-recipients').hide();
            $('.gdc-email-ai-send-options').hide();
            $('.gdc-email-ai-send').hide();
        }

        // Reset Campaign Architect workflow
        state.workflow = {
            currentStep: 'strategy',
            campaignType: null,
            audienceType: null,
            subjectOptions: [],
            selectedSubject: null,
            bodyBulletPoints: [],
            visualStyle: null,
            layoutChoice: null,
            theme: null,
            buttonStyle: null
        };

        // Clear and reset chat with context-aware greeting
        $chatThread.empty();
        showContextAwareGreeting();

        // Open modal
        $modal.addClass('open').attr('aria-hidden', 'false');
        $('body').addClass('gdc-email-modal-open');

        // Initialize WordPress editor after modal is visible
        // REMOVED: User prefers raw HTML editing
        // setTimeout(function () {
        //    initWpEditor(bodyContent);
        // }, 100);
    }

    /**
     * Initialize WordPress visual editor
     */
    function initWpEditor(content) {
        // User requested Raw HTML editing only. 
        // Disabling TinyMCE initialization.
        $('#gdc-email-ai-body').val(content || '');
    }

    /**
     * Get editor content (handles both tinymce and plain textarea)
     */
    function getEditorContent() {
        var editorId = 'gdc-email-ai-body';
        if (window.tinymce && tinymce.get(editorId)) {
            return tinymce.get(editorId).getContent();
        }
        return $('#' + editorId).val() || '';
    }

    /**
     * Set editor content
     */
    function setEditorContent(content) {
        var editorId = 'gdc-email-ai-body';
        if (window.tinymce && tinymce.get(editorId)) {
            tinymce.get(editorId).setContent(content || '');
        }
        $('#' + editorId).val(content || '');
        // Sync to custom visual editor
        $('#gdc-email-visual-editor').html(content || '');
    }

    /**
     * Hide old/legacy modals that may have been opened by other handlers
     */
    function hideOldModals() {
        // Hide old Add New modal
        $('#gdc-email-scheduled-add-modal').attr('hidden', true);
        // Hide old Edit modal (used for iframe editing)
        $('.gdc-email-edit-modal, .gdc-email-modal').each(function () {
            if (!$(this).hasClass('gdc-email-ai-modal') && !$(this).hasClass('gdc-email-add-modal')) {
                $(this).attr('hidden', true).hide();
            }
        });
        // Also try closing via their class patterns
        $('[class*="gdc-email-modal"]:not(.gdc-email-ai-modal):not(.gdc-email-add-modal)').attr('hidden', true).hide();
    }

    /**
     * Show context-aware greeting based on email type and existing content
     */
    function showContextAwareGreeting() {
        var emailData = state.currentEmail || {};
        var section = emailData.section || 'general';
        var hasSubject = !!$subjectField.val();
        var hasBody = !!getEditorContent();
        var label = emailData.label || 'this email';

        // Build context-aware greeting
        var greeting = "Hi! I'm Leo, your email assistant. ";

        // Identify email type
        var typeContext = '';
        switch (section) {
            case 'store':
                typeContext = "I see you're working on a **WooCommerce transaction email** for " + label + ". ";
                break;
            case 'community':
                typeContext = "I see you're editing a **BuddyPress community notification** for " + label + ". ";
                break;
            case 'rewards':
                typeContext = "I see you're customizing a **rewards/points email** for " + label + ". ";
                break;
            case 'timed':
                typeContext = "I see you're creating a **timed/sequence email** for " + label + ". ";
                break;
            case 'proposals':
                typeContext = "I see you're drafting a **proposal email** for " + label + ". ";
                break;
            default:
                typeContext = "I'm here to help you with **" + label + "**. ";
        }
        greeting += typeContext;

        // Analyze what's complete
        var suggestions = [];
        if (!hasSubject) {
            suggestions.push("• **Write a subject line**");
        }
        if (!hasBody) {
            suggestions.push("• **Generate email body copy**");
        } else {
            suggestions.push("• **Improve existing copy**");
            suggestions.push("• **Add visual elements**");
        }

        if (hasSubject && hasBody) {
            greeting += "Looks like you've got a good start! ";
            suggestions.push("• **Polish and refine**");
            suggestions.push("• **Apply a theme**");
        }

        greeting += "\n\nI can help you:\n" + suggestions.join('\n');

        addChatMessage('assistant', greeting);
    }

    /**
     * Close the email editor popup
     */
    function closeEditor() {
        // Cleanup WordPress editor
        var editorId = 'gdc-email-ai-body';
        if (window.wp && wp.editor && wp.editor.remove) {
            try { wp.editor.remove(editorId); } catch (e) { }
        }
        if (window.tinymce && tinymce.get(editorId)) {
            try { tinymce.get(editorId).remove(); } catch (e) { }
        }

        $modal.removeClass('open').attr('aria-hidden', 'true');
        $('body').removeClass('gdc-email-modal-open');
        state.currentEmail = null;
    }

    /**
     * Send test email
     */
    function sendTestEmail() {
        var testEmail = $('#gdc-email-ai-test-address').val().trim();
        var $status = $('.gdc-email-ai-test-status');
        var $btn = $('.gdc-email-ai-send-test');

        if (!testEmail || !testEmail.includes('@')) {
            $status.text('Please enter a valid email address.').css('color', '#f87171').show();
            return;
        }

        // Get current email data
        var subject = $subjectField.val();
        var body = getEditorContent();

        if (!subject && !body) {
            $status.text('Please add subject and body content first.').css('color', '#f87171').show();
            return;
        }

        // Show loading state
        $btn.prop('disabled', true).text('Sending...');
        $status.text('Sending test email...').css('color', '#22d3ee').show();

        // Send test email via REST API
        $.ajax({
            url: config.testEmailEndpoint || '/wp-json/gdc/v1/send-test-email',
            method: 'POST',
            contentType: 'application/json',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
            },
            data: JSON.stringify({
                to: testEmail,
                subject: subject,
                body: body,
                preheader: $preheaderField.val()
            })
        })
            .done(function (response) {
                $status.text('Test email sent successfully to ' + testEmail + '!').css('color', '#4ade80').show();
            })
            .fail(function (xhr) {
                var msg = 'Failed to send test email.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                $status.text(msg).css('color', '#f87171').show();
            })
            .always(function () {
                $btn.prop('disabled', false).text('Send Test');
            });
    }

    /**
     * Open add new modal
     */
    function openAddModal() {
        hideOldModals();
        $addModal.addClass('open').attr('aria-hidden', 'false');
    }

    /**
     * Close add new modal
     */
    function closeAddModal() {
        $addModal.removeClass('open').attr('aria-hidden', 'true');
    }

    /**
     * Open list modal
     */
    function openListModal() {
        $('.gdc-email-list-modal').addClass('open').attr('aria-hidden', 'false');
        // Reset form
        $('#gdc-add-list-form')[0].reset();
        $('#gdc-list-auto-enroll-options').hide();
        $('#gdc-list-form-status').hide();
        $('#gdc-list-selected-products').empty();
        $('#gdc-list-products').val('');

        // Populate member types
        populateMemberTypes();

        // Initialize product search
        initProductSearch();
    }

    /**
     * Close list modal
     */
    function closeListModal() {
        $('.gdc-email-list-modal').removeClass('open').attr('aria-hidden', 'true');
    }

    /**
     * Populate member types dropdown
     */
    function populateMemberTypes() {
        var $select = $('#gdc-list-member-types');
        $select.html('<option value="">Loading...</option>');

        // Fetch member types from BuddyPress
        $.ajax({
            url: config.root + 'wp/v2/member-types',
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
            }
        })
            .done(function (types) {
                $select.empty();
                if (types && types.length > 0) {
                    $.each(types, function (i, type) {
                        $select.append('<option value="' + type.slug + '">' + type.name + '</option>');
                    });
                } else {
                    $select.append('<option value="">No member types available</option>');
                }
            })
            .fail(function () {
                // Fallback - manual entry
                $select.html('<option value="individual">Individual</option><option value="organization">Organization</option>');
            });
    }

    /**
     * Initialize product search
     */
    function initProductSearch() {
        var searchTimeout;
        var selectedProducts = [];

        $('#gdc-list-products-search').off('input').on('input', function () {
            var query = $(this).val().trim();

            clearTimeout(searchTimeout);

            if (query.length < 2) {
                return;
            }

            searchTimeout = setTimeout(function () {
                searchProducts(query, selectedProducts);
            }, 300);
        });
    }

    /**
     * Search products via AJAX
     */
    function searchProducts(query, selectedProducts) {
        $.ajax({
            url: config.root + 'wc/v3/products',
            method: 'GET',
            data: { search: query, per_page: 10 },
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
            }
        })
            .done(function (products) {
                showProductResults(products, selectedProducts);
            });
    }

    /**
     * Show product search results
     */
    function showProductResults(products, selectedProducts) {
        // Remove existing dropdown
        $('.gdc-product-search-results').remove();

        if (!products || products.length === 0) {
            return;
        }

        var $dropdown = $('<div class="gdc-product-search-results"></div>');

        $.each(products, function (i, product) {
            // Skip already selected
            if (selectedProducts.indexOf(product.id) !== -1) {
                return;
            }

            var imageUrl = product.images && product.images[0] ? product.images[0].src : '';
            var $item = $('<div class="gdc-product-result-item" data-id="' + product.id + '">' +
                (imageUrl ? '<img src="' + imageUrl + '" alt="">' : '<div class="gdc-product-placeholder"></div>') +
                '<span>' + product.name + '</span>' +
                '</div>');

            $item.on('click', function () {
                addSelectedProduct(product.id, product.name, imageUrl, selectedProducts);
                $('.gdc-product-search-results').remove();
                $('#gdc-list-products-search').val('');
            });

            $dropdown.append($item);
        });

        $('#gdc-list-products-search').after($dropdown);
    }

    /**
     * Add selected product
     */
    function addSelectedProduct(id, name, imageUrl, selectedProducts) {
        selectedProducts.push(id);

        var $tag = $('<div class="gdc-product-tag" data-id="' + id + '">' +
            (imageUrl ? '<img src="' + imageUrl + '" alt="">' : '') +
            '<span>' + name + '</span>' +
            '<button type="button" class="gdc-remove-product">✕</button>' +
            '</div>');

        $tag.find('.gdc-remove-product').on('click', function () {
            var idx = selectedProducts.indexOf(id);
            if (idx !== -1) {
                selectedProducts.splice(idx, 1);
            }
            $tag.remove();
            updateProductsField(selectedProducts);
        });

        $('#gdc-list-selected-products').append($tag);
        updateProductsField(selectedProducts);
    }

    /**
     * Update hidden products field
     */
    function updateProductsField(selectedProducts) {
        $('#gdc-list-products').val(selectedProducts.join(','));
    }

    /**
     * Submit list form via AJAX
     */
    function submitListForm() {
        var $form = $('#gdc-add-list-form');
        var $status = $('#gdc-list-form-status');
        var $submitBtn = $form.find('[type="submit"]');

        // Get form values
        var name = $('#gdc-list-name').val().trim();
        var description = $('#gdc-list-description').val().trim();
        var autoEnrollEnabled = $('#gdc-list-auto-enroll-enable').is(':checked');

        // Validate
        if (!name) {
            $status.text('Please enter a list name.').css('color', '#f87171').show();
            return;
        }

        // Build auto-enrollment rules
        var autoEnrollRules = null;
        if (autoEnrollEnabled) {
            var roles = $('#gdc-list-roles').val() || [];
            var memberTypes = $('#gdc-list-member-types').val() || [];
            var productsStr = $('#gdc-list-products').val() || '';
            var products = productsStr ? productsStr.split(',').map(function (s) { return parseInt(s.trim()); }).filter(Boolean) : [];

            autoEnrollRules = {
                enabled: true,
                roles: roles,
                member_types: memberTypes,
                products: products
            };
        }

        // Show loading state
        $submitBtn.prop('disabled', true).text('Creating...');
        $status.text('Creating list...').css('color', '#22d3ee').show();

        console.log('[GDC Email AI] Submitting list:', { name: name, description: description });
        // Submit via AJAX
        $.ajax({
            url: config.listsEndpoint || config.root + 'em/v1/email-lists',
            method: 'POST',
            contentType: 'application/json',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
            },
            data: JSON.stringify({
                name: name,
                description: description,
                auto_enroll_rules: autoEnrollRules
            })
        })
            .done(function (response) {
                console.log('[GDC Email AI] List created successfully:', response);
                $status.text('List created successfully!').css('color', '#4ade80').show();

                // Reload lists table
                setTimeout(function () {
                    closeListModal();
                    // Trigger table refresh if function exists
                    if (typeof window.gdcRefreshListsTable === 'function') {
                        window.gdcRefreshListsTable();
                    } else {
                        // Reload page as fallback
                        location.reload();
                    }
                }, 1000);
            })
            .fail(function (xhr) {
                var msg = 'Failed to create list.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                $status.text(msg).css('color', '#f87171').show();
            })
            .always(function () {
                $submitBtn.prop('disabled', false).text('Create List');
            });
    }

    /**
     * Get type label
     */
    function getTypeLabel(type) {
        var labels = {
            'timed': 'Timed Email',
            'store': 'Transaction Email',
            'community': 'Community Email',
            'rewards': 'Rewards Email',
            'proposals': 'Proposal Email'
        };
        return labels[type] || 'Email';
    }

    /**
     * Add message to chat thread
     */
    function addChatMessage(role, content) {
        var msgClass = 'gdc-email-ai-msg gdc-email-ai-msg--' + role;
        var $msg = $('<div>').addClass(msgClass).html(formatMessage(content));
        $chatThread.append($msg);
        $chatThread.scrollTop($chatThread[0].scrollHeight);

        state.chatHistory.push({ role: role, content: content });
    }

    /**
     * Format message content (simple markdown)
     */
    /**
     * Detect user intent from message
     */
    function detectUserIntent(message) {
        var lower = message.toLowerCase();

        // Styling/HTML request - be MORE specific to avoid false positives
        // Only detect as styling if user explicitly asks for HTML/formatting
        if (lower.match(/\b(html|format|responsive)\b/) ||
            lower.match(/add (html|styling|design|formatting)/) ||
            lower.match(/make it (pretty|formatted)/) ||
            lower.match(/apply (html|styling|design)/) ||
            lower.match(/brand colors?\b/)) {
            return 'styling';
        }

        // Subject line
        if (lower.match(/subject|headline|title/)) {
            return 'subject';
        }

        // Body content
        if (lower.match(/body|content|copy|write|paragraph|message|text/)) {
            return 'body';
        }

        // Images
        if (lower.match(/image|picture|photo|visual|graphic/)) {
            return 'image';
        }

        // Improvement
        if (lower.match(/improve|better|enhance|refine|polish/)) {
            return 'improve';
        }

        // If we're in a specific phase, infer intent
        if (conversationState.phase === 'subject') return 'subject';
        if (conversationState.phase === 'body') return 'body';
        if (conversationState.phase === 'styling') return 'styling';

        return 'general';
    }

    /**
     * Format message text with basic markdown
     */
    function formatMessage(content) {

        if (!content) return '';
        // Convert newlines to <br>
        var html = content.replace(/\n/g, '<br>');
        // Bold
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        // Code
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        return html;
    }

    /**
     * Send chat message to AI
     */
    function sendChatMessage() {
        var message = $chatInput.val().trim();
        if (!message || state.isGenerating) return;

        // Detect user intent
        var intent = detectUserIntent(message);
        conversationState.lastUserMessage = message;
        conversationState.lastIntent = intent;

        // Add user message
        addChatMessage('user', message);
        $chatInput.val('');

        // CAMPAIGN ARCHITECT INTERCEPT
        if (state.workflow) {
            if (state.workflow.currentStep === 'body_copy') {
                generateBodyCopy(message);
                return;
            }
            if (state.workflow.currentStep === 'image_generation') {
                generateImageFromPrompt(message);
                return;
            }
        }

        // Show thinking indicator
        state.isGenerating = true;
        var $thinking = $('<div>').addClass('gdc-email-ai-msg gdc-email-ai-msg--thinking').text('Leo is thinking...');
        $chatThread.append($thinking);
        $chatThread.scrollTop($chatThread[0].scrollHeight);

        // Build context with intent
        var context = buildEmailContext();
        context.user_intent = intent;
        context.conversation_phase = conversationState.phase;
        context.has_draft = {
            subject: !!conversationState.draftSubject,
            body: !!conversationState.draftBody,
            html: !!conversationState.draftHtml
        };

        // Call AI endpoint
        callAiEndpoint(message, context)
            .then(function (response) {
                $thinking.remove();
                handleAiResponse(response, intent);
            })
            .catch(function (error) {
                $thinking.remove();
                addChatMessage('assistant', 'Sorry, I encountered an error. Please try again.');
                console.error('[GDC Email AI] Error:', error);
            })
            .always(function () {
                state.isGenerating = false;
            });
    }

    /**
     * Build email context for AI
     */
    function buildEmailContext() {
        var emailData = state.currentEmail || {};
        return {
            email_context: true,
            email_type: emailData.section || 'general',
            email_label: emailData.label || '',
            current_subject: $subjectField.val(),
            current_body: getEditorContent(),
            current_heading: emailData.heading || '',
            has_existing_content: {
                subject: !!$subjectField.val(),
                body: !!getEditorContent(),
                heading: !!emailData.heading
            },
            brand: {
                site_name: config.siteName || '',
                logo_url: config.siteLogo || '',
                primary_color: config.primaryColor || '#6366f1',
                secondary_color: config.secondaryColor || '#22d3ee'
            }
        };
    }

    /**
     * Call AI endpoint
     */
    function callAiEndpoint(message, context) {
        var endpoint = config.chatEndpoint || '/wp-json/aipa/v1/chat-gemini'; // Force Gemini endpoint for workflow support

        var messages = state.chatHistory.map(function (m) {
            return { role: m.role, content: m.content };
        });
        messages.push({ role: 'user', content: message });

        return $.ajax({
            url: endpoint,
            method: 'POST',
            contentType: 'application/json',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
            },
            data: JSON.stringify({
                message: message, // Gemini endpoint expects 'message' at root
                workflow: 'email_designer', // Enforce email_designer workflow
                enable_tools: true,
                context: context
            })
        });
    }

    /**
     * Handle AI response with intent-based processing
     */
    function handleAiResponse(response, intent) {
        var content = '';

        // Handle different response formats
        if (response && response.choices && response.choices[0]) {
            content = response.choices[0].message ? response.choices[0].message.content : '';
        } else if (response && response.candidates && response.candidates[0]) {
            // Gemini format
            content = response.candidates[0].content ? response.candidates[0].content.parts[0].text : '';
        } else if (response && response.content) {
            content = response.content;
        } else if (typeof response === 'string') {
            content = response;
        }

        if (!content) {
            addChatMessage('assistant', 'I received an empty response. Please try rephrasing your request.');
            return;
        }

        // Add assistant message to chat
        addChatMessage('assistant', content);

        // Process based on detected intent
        try {
            // Handle JSON actions from email_designer
            var actionData = JSON.parse(content);
            if (actionData.action) {
                handleEmailAction(actionData);
                return;
            }
        } catch (e) {
            // Not JSON, treat as text
        }

        processAiResponseByIntent(content, intent);
    }

    /**
     * Process AI response based on user intent
     */
    /**
     * Handle Email Architect Actions
     */
    function handleEmailAction(data) {
        var chatResponse = data.chat_response || 'Updates applied.';
        addChatMessage('assistant', chatResponse);

        if (data.action === 'replace_all') {
            // Convert hierarchy to HTML string
            // Since we are in a text editor, if blocks are passed, we might need to rely on their 'originalContent' or similar
            // But my prompt mostly returns core/html with 'content' attr for email.
            // If complex structure, this might fail unless we parse.
            // Simple fallback: If blocks exist, try to extract content.
            var html = '';
            if (data.blocks && Array.isArray(data.blocks)) {
                data.blocks.forEach(function (b) {
                    if (b.attrs && b.attrs.content) html += b.attrs.content;
                    else if (b.innerHTML) html += b.innerHTML;
                });
            }
            if (html) setEditorContent(html);

        } else if (data.action === 'insert_block') {
            var block = data.block;
            var html = (block.attrs && block.attrs.content) ? block.attrs.content : '';
            if (html) {
                var current = getEditorContent();
                if (data.position === 'bottom') setEditorContent(current + '\n' + html);
                else setEditorContent(html + '\n' + current);
            }
        }
    }

    /**
     * Process response - Legacy Fallback
     */
    function processAiResponseByIntent(content, intent) {
        // FIRST: Check if response actually contains HTML (overrides intent)
        if (content.includes('<!DOCTYPE') || content.includes('<html') || content.match(/```html/i)) {
            handleStylingRequest(content);
            return;
        }

        // Intent: Styling, but only if AI isn't asking clarifying questions
        if (intent === 'styling') {
            var isAskingQuestions = content.match(/\?/) ||
                content.match(/tell me|let me know|clarify|which|what kind/i) ||
                content.match(/option \d/i);
            if (!isAskingQuestions) {
                handleStylingRequest(content);
                return;
            }
        }

        // Intent: Subject line (check for multiple options)
        if (intent === 'subject' || detectMultipleSubjects(content)) {
            handleSubjectOptions(content);
            return;
        }

        // Intent: Body content
        if (intent === 'body' || detectBodyContent(content)) {
            handleBodyContent(content);
            return;
        }

        // Intent: Images
        if (intent === 'image' || content.match(/!\[([^\]]*)\]\(([^)]+)\)/)) {
            handleImageSuggestion(content);
            return;
        }

        // Fallback: try old detection for backward compatibility
        detectAndOfferApplyLegacy(content);
    }

    /**
     * Detect if response contains multiple subject line options
     */
    function detectMultipleSubjects(content) {
        var matches = content.match(/(?:Subject|Option)\s*[:\s#]*\d/gi);
        return matches && matches.length >= 2;
    }

    /**
     * Detect if response contains body content
     */
    function detectBodyContent(content) {
        var bodyMatch = content.match(/Body[:\s]+([\s\S]{50,}?)(?:\n\n|Subject|$)/i);
        return !!bodyMatch;
    }

    /**
     * Handle HTML styling request
     */
    function handleStylingRequest(content) {
        conversationState.phase = 'styling';

        // Extract HTML code
        var htmlCode = extractHtmlFromResponse(content);

        if (htmlCode) {
            conversationState.draftHtml = htmlCode;
            showHtmlPreviewAndApply(htmlCode);
        } else {
            // No HTML found - this is okay, AI might be asking clarifying questions
            console.log('[Email AI] Styling intent detected but no HTML in response');
        }
    }

    /**
     * Extract HTML code from AI response
     */
    function extractHtmlFromResponse(content) {
        // Try code block first
        var codeBlock = content.match(/```html?\n?([\s\S]*?)```/i);
        if (codeBlock) return codeBlock[1].trim();

        // Try raw HTML (DOCTYPE or html tag)
        if (content.includes('<!DOCTYPE') || content.includes('<html')) {
            var start = content.indexOf('<');
            var end = content.lastIndexOf('>') + 1;
            if (start !== -1 && end > start) {
                return content.substring(start, end);
            }
        }

        return null;
    }

    /**
     * Show HTML preview with iframe and apply button
     */
    function showHtmlPreviewAndApply(htmlCode) {
        var $container = $('<div class="gdc-html-preview-container"></div>').css({
            'margin': '16px 0',
            'padding': '16px',
            'background': 'rgba(99, 102, 241, 0.08)',
            'border-radius': '12px',
            'border': '1px solid rgba(99, 102, 241, 0.3)'
        });

        var $title = $('<div style="font-size: 11px; color: #9ca3af; margin-bottom: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">📧 HTML Email Preview</div>');
        var $iframe = $('<iframe style="width:100%; height:450px; border:1px solid rgba(99, 102, 241, 0.2); border-radius:8px; background:#fff; margin-bottom:12px;"></iframe>');

        $container.append($title);
        $container.append($iframe);

        // Load HTML into iframe safely
        $iframe.on('load', function () {
            try {
                var doc = this.contentDocument || this.contentWindow.document;
                doc.open();
                doc.write(htmlCode);
                doc.close();
            } catch (e) {
                console.error('Error loading HTML preview:', e);
            }
        });

        // Apply button
        var $applyBtn = $('<button class="gdc-email-ai-quick-btn" style="width:100%; padding:14px; font-size:14px; font-weight:600;">Apply This HTML to Email Body</button>');
        $applyBtn.on('click', function () {
            setEditorContent(htmlCode);
            $(this).text('✓ HTML Applied to Email').prop('disabled', true).css('background', '#22c55e');

            // Show success feedback
            conversationState.draftHtml = htmlCode;
            conversationState.phase = 'review';
        });

        $container.append($applyBtn);
        $chatThread.append($container);
        $chatThread.scrollTop($chatThread[0].scrollHeight);
    }

    /**
     * Handle multiple subject line options
     */
    function handleSubjectOptions(content) {
        conversationState.phase = 'subject';

        // Extract all subject line options
        // STRICTER REGEX: Only match lines starting with "Subject:" or "Option X: Subject:" 
        // effectively ignoring loose "Option" headers to prevent duplicates and garbage
        var subjectLines = [];
        var matches = content.match(/^[\s\t]*(?:Option\s+\d+[:\.]\s*)?Subject[:\s]+(.+)/gmi);

        if (matches && matches.length > 0) {
            matches.forEach(function (match) {
                // Clean up the match to get just the subject text
                var cleaned = match.replace(/^[\s\t]*(?:Option\s+\d+[:\.]\s*)?Subject[:\s]+/i, '').trim();
                // Remove enclosing quotes if present
                cleaned = cleaned.replace(/^["']|["']$/g, '');

                if (cleaned && cleaned.length > 5 && cleaned.length < 150) {
                    subjectLines.push(cleaned);
                }
            });
        }


        if (subjectLines.length > 0) {
            conversationState.pendingOptions.subjects = subjectLines;
            conversationState.pendingOptions.selectedIndex = null;
            addSelectableOptions('subject', subjectLines); // Re-using existing addSelectableOptions
        }
    }

    /**
     * Handle body content from AI
     */
    function handleBodyContent(content) {
        conversationState.phase = 'body';

        // Extract body content
        var bodyMatch = content.match(/Body[:\s]+([\s\S]{50,}?)(?:\n\n|Subject|$)/i);
        if (bodyMatch) {
            var bodyContent = bodyMatch[1].trim();
            // Remove common artifacts
            bodyContent = bodyContent.replace(/```/g, '').replace(/^["']|["']$/g, '').trim();

            if (bodyContent.length > 30) {
                conversationState.draftBody = bodyContent;
                addApplyButton('body', bodyContent, 'Apply Body Content'); // Re-using existing addApplyButton
            }
        }
    }

    /**
     * Handle image requests - redirect to Design Studio
     */
    function handleImageSuggestion(content) {
        // Show Design Studio button
        var $container = $('<div class="gdc-design-studio-prompt"></div>').css({
            'margin': '16px 0',
            'padding': '16px',
            'background': 'rgba(139, 92, 246, 0.1)',
            'border-radius': '12px',
            'border': '1px solid rgba(139, 92, 246, 0.3)',
            'text-align': 'center'
        });

        var $title = $('<div style="font-size: 13px; color: #a78bfa; margin-bottom: 12px; font-weight: 600;">🎨 IMAGE GENERATION</div>');
        var $desc = $('<p style="font-size: 12px; color: #d1d5db; margin-bottom: 14px;">Create professional images using AI-powered Design Studio with Imagen 3.0</p>');

        var $openBtn = $('<button class="gdc-email-ai-quick-btn" style="width: 100%; background: linear-gradient(135deg, #8b5cf6, #6366f1); padding: 12px; font-weight: 600;">Open Design Studio</button>');
        $openBtn.on('click', function () {
            // Open Design Studio (AI Project Assistant)
            var designStudioUrl = '/wp-admin/admin.php?page=aipa-design-studio';
            window.open(designStudioUrl, '_blank', 'width=1400,height=900');
            $(this).text('Design Studio Opened ✓').css('background', '#22c55e');
        });

        var $helpText = $('<div style="font-size: 11px; color: #9ca3af; margin-top: 10px;">After generating your image, download it and use the media library to insert it into your email.</div>');

        $container.append($title);
        $container.append($desc);
        $container.append($openBtn);
        $container.append($helpText);

        $chatThread.append($container);
        $chatThread.scrollTop($chatThread[0].scrollHeight);
    }

    /**
     * Legacy detection for backward compatibility
     */
    function detectAndOfferApplyLegacy(content) {

        // Check for multiple subject line options (numbered or bulleted)
        var subjectLines = [];
        var subjectMatches = content.match(/(?:Subject|Option)\s*[:\s#]*\d*[:\s]*["']?([^"'\n]{10,120})["']?/gi);
        if (subjectMatches && subjectMatches.length > 0) {
            subjectMatches.forEach(function (match) {
                var cleaned = match.replace(/(?:Subject|Option)\s*[:\s#]*\d*[:\s]*["']?/i, '').replace(/["']$/, '').trim();
                if (cleaned && cleaned.length > 5 && cleaned.length < 150) {
                    subjectLines.push(cleaned);
                }
            });
        }

        // Make subject lines clickable if found
        if (subjectLines.length > 0) {
            addSelectableOptions('subject', subjectLines);
        }

        // Check for body content - look for paragraphs or body sections
        var bodyMatch = content.match(/Body[:\s]+([\s\S]{50,}?)(?:\n\n|Subject|$)/i);
        if (bodyMatch) {
            var bodyContent = bodyMatch[1].trim();
            // Remove common artifacts
            bodyContent = bodyContent.replace(/```/g, '').replace(/^["']|["']$/g, '').trim();
            if (bodyContent.length > 30) {
                addApplyButton('body', bodyContent, 'Apply Body Content');
            }
        }

        // Check for HTML email content (in code blocks or explicit HTML tags)
        if (content.includes('<html') || content.includes('<table') || content.includes('<!DOCTYPE')) {
            var htmlMatch = content.match(/```html?\n?([\s\S]*?)```/i);
            if (htmlMatch) {
                addApplyButton('html', htmlMatch[1].trim(), 'Apply HTML Email');
            } else {
                // Extract raw HTML
                var htmlStart = content.indexOf('<');
                var htmlEnd = content.lastIndexOf('>');
                if (htmlStart !== -1 && htmlEnd !== -1 && htmlEnd > htmlStart) {
                    var rawHtml = content.substring(htmlStart, htmlEnd + 1);
                    if (rawHtml.length > 50) {
                        addApplyButton('html', rawHtml, 'Apply HTML Email');
                    }
                }
            }
        }

        // Check for image suggestions or URLs
        var imageMatch = content.match(/!\[([^\]]*)\]\(([^)]+)\)/); // Markdown image
        if (imageMatch) {
            addImageOption(imageMatch[2], imageMatch[1] || 'AI Generated Image');
        }
    }

    /**
     * Add selectable options (for subject lines with radio buttons)
     */
    function addSelectableOptions(type, options) {
        var $container = $('<div class="gdc-options-container"></div>').css({
            'margin': '12px 0',
            'padding': '12px',
            'background': 'rgba(99, 102, 241, 0.08)',
            'border-radius': '8px',
            'border': '1px solid rgba(99, 102, 241, 0.2)'
        });

        var $title = $('<div style="font-size: 11px; color: #9ca3af; margin-bottom: 8px; font-weight: 600;">SELECT ONE:</div>');
        $container.append($title);

        // Use conversationState for global tracking (not local closure)
        conversationState.pendingOptions.selectedIndex = null;

        options.forEach(function (option, index) {
            var $option = $('<div class="gdc-selectable-option"></div>').css({
                'padding': '10px 12px',
                'margin': '4px 0',
                'background': 'rgba(15, 23, 42, 0.6)',
                'border': '1px solid rgba(99, 102, 241, 0.25)',
                'border-radius': '6px',
                'cursor': 'pointer',
                'transition': 'all 0.2s ease',
                'font-size': '13px',
                'color': '#e5e7eb'
            }).text(option).data('option-index', index).data('option-value', option);

            $option.on('click', function () {
                // Deselect all
                $container.find('.gdc-selectable-option').css({
                    'background': 'rgba(15, 23, 42, 0.6)',
                    'border-color': 'rgba(99, 102, 241, 0.25)'
                });
                // Select this one
                $(this).css({
                    'background': 'rgba(99, 102, 241, 0.3)',
                    'border-color': 'rgba(99, 102, 241, 0.6)'
                });
                // Store in GLOBAL state
                conversationState.pendingOptions.selectedIndex = $(this).data('option-index');
            });

            $option.on('mouseenter', function () {
                if ($(this).data('option-index') !== conversationState.pendingOptions.selectedIndex) {
                    $(this).css('background', 'rgba(99, 102, 241, 0.15)');
                }
            }).on('mouseleave', function () {
                if ($(this).data('option-index') !== conversationState.pendingOptions.selectedIndex) {
                    $(this).css('background', 'rgba(15, 23, 42, 0.6)');
                }
            });

            $container.append($option);
        });

        // Add apply button
        var $applyBtn = $('<button class="gdc-email-ai-quick-btn" style="margin-top: 10px; width: 100%;">Apply Selected Subject</button>');
        $applyBtn.on('click', function () {
            var selectedIndex = conversationState.pendingOptions.selectedIndex;
            if (selectedIndex !== null && options[selectedIndex]) {
                var selectedOption = options[selectedIndex];
                $subjectField.val(selectedOption).addClass('gdc-email-ai-field-updated');
                setTimeout(function () { $subjectField.removeClass('gdc-email-ai-field-updated'); }, 2000);
                $(this).text('Applied ✓').prop('disabled', true).css('background', '#22c55e');

                // Update conversation state
                conversationState.draftSubject = selectedOption;
                conversationState.phase = 'body'; // Move to next phase
            } else {
                $(this).text('Please select an option first').css('background', '#ef4444');
                setTimeout(function () {
                    $applyBtn.text('Apply Selected Subject').css('background', '');
                }, 2000);
            }
        });
        $container.append($applyBtn);

        $chatThread.append($container);
        $chatThread.scrollTop($chatThread[0].scrollHeight);
    }

    /**
     * Add single apply button to chat
     */
    function addApplyButton(type, content, label) {
        var $btn = $('<button></button>')
            .addClass('gdc-email-ai-quick-btn')
            .text(label || (type === 'subject' ? 'Apply Subject' : type === 'body' ? 'Apply Body' : 'Apply HTML'))
            .css({ marginTop: '8px', width: '100%' })
            .on('click', function () {
                if (type === 'subject') {
                    $subjectField.val(content).addClass('gdc-email-ai-field-updated');
                    setTimeout(function () { $subjectField.removeClass('gdc-email-ai-field-updated'); }, 2000);
                } else if (type === 'body') {
                    setEditorContent(content);
                } else if (type === 'html') {
                    setEditorContent(content);
                }
                $(this).text('Applied ✓').prop('disabled', true).css('background', '#22c55e');
            });
        $chatThread.append($btn);
        $chatThread.scrollTop($chatThread[0].scrollHeight);
    }

    /**
     * Add image option
     */
    function addImageOption(imageUrl, description) {
        var $container = $('<div style="margin: 12px 0; padding: 12px; background: rgba(99, 102, 241, 0.08); border-radius: 8px;"></div>');
        var $img = $('<img>').attr('src', imageUrl).css({ 'max-width': '100%', 'border-radius': '6px', 'margin-bottom': '8px' });
        var $btn = $('<button class="gdc-email-ai-quick-btn" style="width: 100%;">Insert Image</button>');

        $btn.on('click', function () {
            var imgTag = '<img src="' + imageUrl + '" alt="' + description + '" style="max-width: 100%; height: auto;">';
            var currentContent = getEditorContent();
            setEditorContent(currentContent + '\n' + imgTag);
            $(this).text('Inserted ✓').prop('disabled', true).css('background', '#22c55e');
        });

        $container.append($('<div style="font-size: 11px; color: #9ca3af; margin-bottom: 8px;">' + description + '</div>'));
        $container.append($img);
        $container.append($btn);
        $chatThread.append($container);
        $chatThread.scrollTop($chatThread[0].scrollHeight);
    }


    /**
     * Handle quick action buttons
     */
    function handleQuickAction(action) {
        // Campaign Architect specific actions
        if (action === 'upload-image') {
            openMediaLibrary();
            return;
        }
        if (action === 'skip-visuals') {
            addChatMessage('user', 'Skip visuals');
            moveToFinalReview();
            return;
        }
        if (action === 'generate-image' && state.workflow && state.workflow.currentStep === 'visual_assets') {
            startImageGeneration();
            return;
        }

        var prompts = {
            'generate-copy': 'Write an engaging email for this ' + getTypeLabel((state.currentEmail && state.currentEmail.section) ? state.currentEmail.section : 'general') + '. Make it compelling and professional.',
            'generate-image': 'The user wants to add images to this email. I will show them a button to open the Design Studio where they can generate professional images using Imagen 3.0.',
            'generate-html': 'Create a complete, responsive HTML email template with inline styles. Use my brand colors: primary ' + (config.primaryColor || '#6366f1') + ' and secondary ' + (config.secondaryColor || '#22d3ee') + '. Include proper HTML structure with DOCTYPE, head, and body tags. Make it mobile-responsive.',
            'improve': 'Please review and improve the current email copy. Here is the current subject: "' + $subjectField.val() + '" and body content.'
        };

        var prompt = prompts[action] || 'Help me with this email.';
        $chatInput.val(prompt);
        sendChatMessage();
    }

    /**
     * Save email
     */
    function saveEmail() {
        var data = {
            section: state.currentEmail ? state.currentEmail.section : 'general',
            subject: $subjectField.val(),
            preheader: $preheaderField.val(),
            html: $bodyField.val(),
            // Pass back original ID if available to identify which row to update
            _id: state.currentEmail ? state.currentEmail._id : null
        };

        // Trigger custom event for external listeners (Sales Team, etc)
        $(document).trigger('gdc_email_ai_save', [data]);

        // Also close the editor
        closeEditor();
    }

    /**
     * Preview email
     */
    function previewEmail() {
        var html = $bodyField.val();
        if (!html) {
            alert('No email content to preview.');
            return;
        }

        var previewWin = window.open('', '_blank', 'width=700,height=600');
        previewWin.document.write(html);
        previewWin.document.close();
    }

    /**
     * Debounce helper
     */
    function debounce(fn, delay) {
        var timer = null;
        return function () {
            var context = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(context, args);
            }, delay);
        };
    }

    /**
     * Search recipients (AJAX)
     */
    function searchRecipients() {
        var query = $('#gdc-email-ai-recipient-search').val().trim();
        var $dropdown = $('#gdc-email-ai-recipients-dropdown');

        if (query.length < 2) {
            $dropdown.hide();
            return;
        }

        $.ajax({
            url: config.searchUsersEndpoint || '/wp-json/gdc/v1/search-users',
            method: 'GET',
            data: { search: query },
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
            }
        })
            .done(function (response) {
                if (response && response.length > 0) {
                    var html = '';
                    response.forEach(function (user) {
                        if (!isRecipientAdded(user.email)) {
                            html += '<div class="gdc-email-ai-recipient-item" data-email="' + user.email + '" data-name="' + user.name + '" data-avatar="' + (user.avatar || '') + '">';
                            html += '<img class="gdc-email-ai-recipient-avatar" src="' + (user.avatar || 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" fill="%23666"/><path d="M12 14c-6 0-8 3-8 5v1h16v-1c0-2-2-5-8-5z" fill="%23666"/></svg>') + '">';
                            html += '<div class="gdc-email-ai-recipient-info"><strong>' + user.name + '</strong><span>' + user.email + '</span></div>';
                            html += '</div>';
                        }
                    });
                    if (html) {
                        $dropdown.html(html).show();
                    } else {
                        $dropdown.hide();
                    }
                } else {
                    $dropdown.hide();
                }
            })
            .fail(function () {
                $dropdown.hide();
            });
    }

    /**
     * Check if recipient already added
     */
    function isRecipientAdded(email) {
        return state.recipients.some(function (r) { return r.email === email; });
    }

    /**
     * Add recipient
     */
    function addRecipient(email, name, avatar) {
        if (!email || isRecipientAdded(email)) return;

        state.recipients.push({ email: email, name: name || email, avatar: avatar || '' });
        renderRecipientTags();
        $('#gdc-email-ai-recipient-search').val('');
        $('#gdc-email-ai-recipients-dropdown').hide();
    }

    /**
     * Add manual email
     */
    function addManualEmail(value) {
        value = value.trim();
        if (!value) return;

        // Check if it's a valid email
        if (value.includes('@')) {
            addRecipient(value, value, '');
        }
    }

    /**
     * Remove recipient
     */
    function removeRecipient(email) {
        state.recipients = state.recipients.filter(function (r) { return r.email !== email; });
        renderRecipientTags();
    }

    /**
     * Render recipient tags
     */
    function renderRecipientTags() {
        var $tags = $('#gdc-email-ai-recipients-tags');
        var html = '';
        state.recipients.forEach(function (r) {
            html += '<div class="gdc-email-ai-recipient-tag" data-email="' + r.email + '">';
            if (r.avatar) {
                html += '<img src="' + r.avatar + '" alt="">';
            }
            html += '<span>' + r.name + '</span>';
            html += '<button type="button" class="gdc-email-ai-recipient-remove">×</button>';
            html += '</div>';
        });
        $tags.html(html);
    }

    /**
     * Send email
     */
    function sendEmail() {
        var recipients = state.recipients.map(function (r) { return r.email; });
        var subject = $subjectField.val();
        var body = getEditorContent();
        var preheader = $preheaderField.val();
        var $btn = $('.gdc-email-ai-send');

        if (recipients.length === 0) {
            alert('Please add at least one recipient.');
            return;
        }

        if (!subject && !body) {
            alert('Please add subject and body content.');
            return;
        }

        var data = {
            recipients: recipients,
            subject: subject,
            body: body,
            preheader: preheader,
            mode: state.sendMode
        };

        if (state.sendMode === 'schedule') {
            var scheduleDate = $('#gdc-email-ai-schedule-date').val();
            var scheduleTime = $('#gdc-email-ai-schedule-time').val();
            if (!scheduleDate || !scheduleTime) {
                alert('Please select a date and time for scheduling.');
                return;
            }
            data.schedule_datetime = scheduleDate + ' ' + scheduleTime;
        }

        $btn.prop('disabled', true).text(state.sendMode === 'schedule' ? 'Scheduling...' : 'Sending...');

        $.ajax({
            url: config.sendEmailEndpoint || '/wp-json/gdc/v1/send-email',
            method: 'POST',
            contentType: 'application/json',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
            },
            data: JSON.stringify(data)
        })
            .done(function (response) {
                var msg = state.sendMode === 'schedule' ? 'Email scheduled successfully!' : 'Email sent successfully!';
                alert(msg);
                closeEditor();
            })
            .fail(function (xhr) {
                var msg = 'Failed to send email.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                alert(msg);
            })
            .always(function () {
                $btn.prop('disabled', false).text(state.sendMode === 'schedule' ? 'Schedule Email' : 'Send Email');
            });
    }

    // =========================================================================
    // CAMPAIGN ARCHITECT WORKFLOW FUNCTIONS
    // =========================================================================

    /**
     * Show the Campaign Architect proactive greeting
     */
    function showCampaignArchitectGreeting() {
        var greeting = "**Let's craft a campaign that gets opened, not archived.** 🚀\n\n" +
            "I'm Leo, your Campaign Architect. To start, what is the **primary goal** of this email?";

        addChatMessage('assistant', greeting);

        // Add campaign type quick action buttons
        var $actions = $('<div class="gdc-email-ai-campaign-actions">' +
            '<button class="gdc-email-ai-campaign-btn" data-type="flash_sale">🔥 Flash Sale</button>' +
            '<button class="gdc-email-ai-campaign-btn" data-type="newsletter">📰 Newsletter</button>' +
            '<button class="gdc-email-ai-campaign-btn" data-type="welcome">👋 Welcome Series</button>' +
            '<button class="gdc-email-ai-campaign-btn" data-type="product_launch">🚀 Product Launch</button>' +
            '<button class="gdc-email-ai-campaign-btn" data-type="event">🎉 Event Invite</button>' +
            '<button class="gdc-email-ai-campaign-btn" data-type="other">💡 Other</button>' +
            '</div>');

        $chatThread.append($actions);
        $chatThread.scrollTop($chatThread[0].scrollHeight);
    }

    /**
     * Handle campaign type selection
     */
    function handleCampaignTypeSelection(type) {
        var typeLabels = {
            'flash_sale': 'Flash Sale',
            'newsletter': 'Newsletter',
            'welcome': 'Welcome Series',
            'product_launch': 'Product Launch',
            'event': 'Event Invite',
            'other': 'Custom Campaign'
        };

        state.workflow.campaignType = type;
        state.workflow.currentStep = 'audience';

        // Add user selection to chat
        addChatMessage('user', typeLabels[type] || type);

        // Leo responds and asks about audience
        var response = "**" + (typeLabels[type] || type) + "** - got it! 🎯\n\n" +
            "Who is receiving this email? This helps me dial in the right tone.";

        addChatMessage('assistant', response);

        // Add audience quick action buttons
        var $actions = $('<div class="gdc-email-ai-campaign-actions">' +
            '<button class="gdc-email-ai-audience-btn" data-audience="vip">👑 VIP Customers</button>' +
            '<button class="gdc-email-ai-audience-btn" data-audience="subscribers">📧 All Subscribers</button>' +
            '<button class="gdc-email-ai-audience-btn" data-audience="new">🆕 New Users</button>' +
            '<button class="gdc-email-ai-audience-btn" data-audience="cold">❄️ Cold Leads</button>' +
            '<button class="gdc-email-ai-audience-btn" data-audience="custom">✨ Custom Segment</button>' +
            '</div>');

        $chatThread.append($actions);
        $chatThread.scrollTop($chatThread[0].scrollHeight);
    }

    /**
     * Handle audience selection and generate subject lines
     */
    function handleAudienceSelection(audience) {
        var audienceLabels = {
            'vip': 'VIP Customers',
            'subscribers': 'All Subscribers',
            'new': 'New Users',
            'cold': 'Cold Leads',
            'custom': 'Custom Segment'
        };

        state.workflow.audienceType = audience;
        state.workflow.currentStep = 'subject_lines';

        // Add user selection to chat
        addChatMessage('user', audienceLabels[audience] || audience);

        // Generate subject lines via AI
        generateSubjectLines();
    }

    /**
     * Generate subject lines using AI
     */
    function generateSubjectLines() {
        var $thinking = $('<div>').addClass('gdc-email-ai-msg gdc-email-ai-msg--thinking').text('Leo is crafting subject lines...');
        $chatThread.append($thinking);
        $chatThread.scrollTop($chatThread[0].scrollHeight);

        state.isGenerating = true;

        var campaignLabels = {
            'flash_sale': 'Flash Sale',
            'newsletter': 'Newsletter',
            'welcome': 'Welcome Series',
            'product_launch': 'Product Launch',
            'event': 'Event Invite',
            'other': 'Campaign'
        };

        var audienceLabels = {
            'vip': 'VIP loyal customers',
            'subscribers': 'email subscribers',
            'new': 'new users',
            'cold': 'cold leads who haven\'t engaged',
            'custom': 'custom audience segment'
        };

        var systemPrompt = "You are Leo, an Expert Email Copywriter and Campaign Architect.\n\n" +
            "Context: The user is creating a " + (campaignLabels[state.workflow.campaignType] || 'email campaign') +
            " for " + (audienceLabels[state.workflow.audienceType] || 'their audience') + ".\n\n" +
            "Task: Generate exactly 3 distinct subject lines using these psychological hooks:\n" +
            "1. Benefit-Driven (What's in it for them?)\n" +
            "2. Curiosity-Driven (Open loop that makes them want to know more)\n" +
            "3. Urgency/Scarcity (FOMO, limited time)\n\n" +
            "CRITICAL: You MUST respond with ONLY a valid JSON object in this exact format, no other text:\n" +
            '{"subject_lines": [{"hook": "Benefit", "text": "..."}, {"hook": "Mystery", "text": "..."}, {"hook": "Urgency", "text": "..."}]}';

        var userMessage = "Generate 3 subject lines for my " +
            (campaignLabels[state.workflow.campaignType] || 'campaign') +
            " targeting " + (audienceLabels[state.workflow.audienceType] || 'my audience');

        callAiEndpoint(userMessage, {
            email_context: true,
            campaign_architect_mode: true,
            workflow_step: 'subject_lines',
            campaign_type: state.workflow.campaignType,
            audience_type: state.workflow.audienceType,
            system_prompt_override: systemPrompt
        })
            .then(function (response) {
                $thinking.remove();
                handleSubjectLineResponse(response);
            })
            .catch(function (error) {
                $thinking.remove();
                // Fallback: Generate sample subject lines locally
                showFallbackSubjectLines();
                console.error('[Campaign Architect] Error generating subject lines:', error);
            })
            .always(function () {
                state.isGenerating = false;
            });
    }

    /**
     * Handle AI response for subject lines
     */
    function handleSubjectLineResponse(response) {
        var content = '';

        // Extract content from various response formats
        if (response && response.choices && response.choices[0]) {
            content = response.choices[0].message ? response.choices[0].message.content : '';
        } else if (response && response.candidates && response.candidates[0]) {
            content = response.candidates[0].content ? response.candidates[0].content.parts[0].text : '';
        } else if (response && response.content) {
            content = response.content;
        } else if (typeof response === 'string') {
            content = response;
        }

        // Try to parse JSON from the response
        var subjectLines = [];
        try {
            // Look for JSON in the response
            var jsonMatch = content.match(/\{[\s\S]*"subject_lines"[\s\S]*\}/);
            if (jsonMatch) {
                var parsed = JSON.parse(jsonMatch[0]);
                if (parsed.subject_lines && Array.isArray(parsed.subject_lines)) {
                    subjectLines = parsed.subject_lines;
                }
            }
        } catch (e) {
            console.warn('[Campaign Architect] Could not parse subject lines JSON:', e);
        }

        if (subjectLines.length > 0) {
            state.workflow.subjectOptions = subjectLines;
            showSubjectLineOptions(subjectLines);
        } else {
            showFallbackSubjectLines();
        }
    }

    /**
     * Show fallback subject lines if AI fails
     */
    function showFallbackSubjectLines() {
        var campaignType = state.workflow.campaignType || 'campaign';
        var fallbackLines = [];

        if (campaignType === 'flash_sale') {
            fallbackLines = [
                { hook: 'Benefit', text: '🎁 Your exclusive discount inside' },
                { hook: 'Mystery', text: 'We\'ve been saving this just for you...' },
                { hook: 'Urgency', text: '⏰ Last chance: Sale ends tonight' }
            ];
        } else if (campaignType === 'newsletter') {
            fallbackLines = [
                { hook: 'Benefit', text: 'This week\'s top insights for you' },
                { hook: 'Mystery', text: 'You won\'t believe what happened...' },
                { hook: 'Urgency', text: 'Don\'t miss these updates 📬' }
            ];
        } else {
            fallbackLines = [
                { hook: 'Benefit', text: 'Something special just for you' },
                { hook: 'Mystery', text: 'You\'re going to want to see this...' },
                { hook: 'Urgency', text: 'Limited time: Don\'t miss out' }
            ];
        }

        state.workflow.subjectOptions = fallbackLines;
        showSubjectLineOptions(fallbackLines);
    }

    /**
     * Show subject line options in chat
     */
    function showSubjectLineOptions(options) {
        var response = "**Here are 3 subject line options** using different psychological hooks. Which one resonates?\n";
        addChatMessage('assistant', response);

        // Add clickable subject line buttons
        var $container = $('<div class="gdc-email-ai-subject-options">');

        options.forEach(function (opt, idx) {
            var $btn = $('<button class="gdc-email-ai-subject-btn" data-index="' + idx + '">' +
                '<span class="gdc-email-ai-subject-hook">' + opt.hook + '</span>' +
                '<span class="gdc-email-ai-subject-text">' + opt.text + '</span>' +
                '</button>');
            $container.append($btn);
        });

        $chatThread.append($container);

        // Add revision chips
        var $revisions = $('<div class="gdc-email-ai-revision-chips">' +
            '<span class="gdc-email-ai-revision-label">Not quite right?</span>' +
            '<button class="gdc-email-ai-revision-btn" data-modifier="funnier">Make it funnier</button>' +
            '<button class="gdc-email-ai-revision-btn" data-modifier="shorter">Make it shorter</button>' +
            '<button class="gdc-email-ai-revision-btn" data-modifier="less_salesy">Less salesy</button>' +
            '</div>');

        $chatThread.append($revisions);

        // Add "Write my own" escape hatch
        var $custom = $('<div class="gdc-email-ai-custom-input">' +
            '<input type="text" placeholder="Or type your own subject line..." class="gdc-email-ai-custom-subject">' +
            '<button class="gdc-email-ai-custom-subject-btn">Use This</button>' +
            '</div>');

        $chatThread.append($custom);
        $chatThread.scrollTop($chatThread[0].scrollHeight);
    }

    /**
     * Handle subject line selection
     */
    function handleSubjectLineSelection(index) {
        var selected = state.workflow.subjectOptions[index];
        if (!selected) return;

        state.workflow.selectedSubject = selected.text;
        state.workflow.currentStep = 'body_copy';

        // Update the form field with highlight
        updateFormField('subject', selected.text);

        // Add confirmation to chat
        addChatMessage('user', 'Selected: "' + selected.text + '"');

        // Leo confirms and moves to body copy
        var response = "**Locked in!** ✅\n\n" +
            "Great choice. Your subject line is now set.\n\n" +
            "Now for the content. I'll write persuasive copy for you. Just list the **3-4 key points** you need to mention.\n\n" +
            "_For example: \"Ends Friday,\" \"Free shipping over $50,\" \"New colors available\"_";

        addChatMessage('assistant', response);
    }

    /**
     * Handle custom subject line input
     */
    function handleCustomSubjectLine(text) {
        if (!text || !text.trim()) return;

        state.workflow.selectedSubject = text.trim();
        state.workflow.currentStep = 'body_copy';

        // Update the form field
        updateFormField('subject', text.trim());

        // Add to chat
        addChatMessage('user', 'Custom: "' + text.trim() + '"');

        var response = "**Love it!** ✨\n\n" +
            "Using your custom subject line. Now for the content.\n\n" +
            "Just list the **3-4 key points** you need to mention.\n\n" +
            "_For example: \"Ends Friday,\" \"Free shipping over $50,\" \"New colors available\"_";

        addChatMessage('assistant', response);
    }

    /**
     * Handle subject line revision request
     */
    function handleSubjectRevision(modifier) {
        var modifierLabels = {
            'funnier': 'Make it funnier',
            'shorter': 'Make it shorter',
            'less_salesy': 'Less salesy'
        };

        addChatMessage('user', modifierLabels[modifier] || modifier);

        var $thinking = $('<div>').addClass('gdc-email-ai-msg gdc-email-ai-msg--thinking').text('Leo is revising...');
        $chatThread.append($thinking);
        $chatThread.scrollTop($chatThread[0].scrollHeight);

        state.isGenerating = true;

        var previousOptions = state.workflow.subjectOptions.map(function (o) { return o.text; }).join(', ');

        var systemPrompt = "You are Leo, a Senior Copywriter.\n\n" +
            "CONTEXT:\n" +
            "- Previous Options: " + previousOptions + "\n" +
            "- User Feedback: " + (modifierLabels[modifier] || modifier) + "\n" +
            "- Campaign Type: " + state.workflow.campaignType + "\n" +
            "- Target Audience: " + state.workflow.audienceType + "\n\n" +
            "INSTRUCTIONS:\n" +
            "1. Generate 3 NEW subject lines that strictly adhere to the user's feedback.\n" +
            "2. Do NOT recycle words from the previous rejected options.\n\n" +
            "MODIFIER LOGIC:\n" +
            "- funnier: Use puns, pop culture references, or playful unexpectedness.\n" +
            "- shorter: Strict 30-character limit. Punchy 2-3 word phrases.\n" +
            "- less_salesy: Remove exclamation points, remove words like 'Buy'/'Sale', focus on value/news.\n\n" +
            "CRITICAL: Respond with ONLY valid JSON in this format:\n" +
            '{"subject_lines": [{"hook": "...", "text": "..."}, {"hook": "...", "text": "..."}, {"hook": "...", "text": "..."}]}';

        callAiEndpoint("Revise the subject lines: " + (modifierLabels[modifier] || modifier), {
            email_context: true,
            campaign_architect_mode: true,
            workflow_step: 'subject_revision',
            modifier: modifier,
            system_prompt_override: systemPrompt
        })
            .then(function (response) {
                $thinking.remove();
                handleSubjectLineResponse(response);
            })
            .catch(function (error) {
                $thinking.remove();
                addChatMessage('assistant', "Let me try a different approach. Here are revised options:");
                showFallbackSubjectLines();
            })
            .always(function () {
                state.isGenerating = false;
            });
    }

    /**
     * Update a form field with highlight animation
     */
    function updateFormField(field, value) {
        var $field;

        switch (field) {
            case 'subject':
                $field = $subjectField;
                break;
            case 'preheader':
                $field = $preheaderField;
                break;
            case 'body':
                setEditorContent(value);
                $field = $('.gdc-email-ai-editor-wrap');
                break;
            default:
                return;
        }

        if ($field && field !== 'body') {
            $field.val(value);
        }

        // Add highlight animation
        if ($field) {
            $field.addClass('gdc-email-ai-field-updated');
            setTimeout(function () {
                $field.removeClass('gdc-email-ai-field-updated');
            }, 2000);
        }
    }

    /**
     * Generate Body Copy
     */
    function generateBodyCopy(keyPoints) {
        var $thinking = $('<div>').addClass('gdc-email-ai-msg gdc-email-ai-msg--thinking').text('Leo is writing your email...');
        $chatThread.append($thinking);
        $chatThread.scrollTop($chatThread[0].scrollHeight);

        state.isGenerating = true;

        var systemPrompt = "You are Leo, an Expert Email Copywriter.\n\n" +
            "CONTEXT:\n" +
            "- Campaign Type: " + (state.workflow.campaignType || 'email') + "\n" +
            "- Audience: " + (state.workflow.audienceType || 'subscribers') + "\n" +
            "- Subject Line: " + (state.workflow.selectedSubject || '') + "\n" +
            "- Key Points to Cover: " + keyPoints + "\n\n" +
            "INSTRUCTIONS:\n" +
            "1. Write the full email body copy in standard HTML format (paragraphs, bolding, lists).\n" +
            "2. Do NOT include <html>, <head>, or <body> tags, just the inner content.\n" +
            "3. Use a tone appropriate for the campaign type (e.g., Urgent for Flash Sale, Informative for Newsletter).\n" +
            "4. Keep paragraphs short (1-2 sentences) for readability.\n" +
            "5. Include a clear Call to Action (CTA) at the end.\n" +
            "6. Use placeholders like [Name] where appropriate.\n\n" +
            "CRITICAL: Return ONLY the HTML content, nothing else.";

        callAiEndpoint("Generate email body copy for: " + keyPoints, {
            email_context: true,
            campaign_architect_mode: true,
            workflow_step: 'body_copy',
            key_points: keyPoints,
            system_prompt_override: systemPrompt
        })
            .then(function (response) {
                $thinking.remove();
                handleBodyCopyResponse(response);
            })
            .catch(function (error) {
                $thinking.remove();
                addChatMessage('assistant', "I had some trouble writing the copy. Can you try giving me the key points again?");
                console.error('[Campaign Architect] Error generating body copy:', error);
            })
            .always(function () {
                state.isGenerating = false;
            });
    }

    /**
     * Handle Body Copy Response
     */
    function handleBodyCopyResponse(response) {
        var content = '';

        if (response && response.choices && response.choices[0]) {
            content = response.choices[0].message ? response.choices[0].message.content : '';
        } else if (response && response.candidates && response.candidates[0]) {
            content = response.candidates[0].content ? response.candidates[0].content.parts[0].text : '';
        } else if (response && response.content) {
            content = response.content;
        } else if (typeof response === 'string') {
            content = response;
        }

        // Clean up markdown code blocks if present
        content = content.replace(/```html/g, '').replace(/```/g, '').trim();

        // Update workflow
        state.workflow.currentStep = 'visual_assets';
        state.workflow.generatedBody = content;

        // Update Editor
        updateFormField('body', content);

        // Assistant Response
        var responseMsg = "**Draft is ready!** 📝\n\n" +
            "I've added the copy to the editor on the left. You can edit it directly there.\n\n" +
            "**What do you think?**\n" +
            "You can ask me to refine it (e.g., 'Make it shorter', 'More urgent') or we can move on to visuals.";

        addChatMessage('assistant', responseMsg);

        // Add visual asset options
        var $actions = $('<div class="gdc-email-ai-campaign-actions">' +
            '<button class="gdc-email-ai-quick-btn" data-action="generate-image">🎨 Generate Image</button>' +
            '<button class="gdc-email-ai-quick-btn" data-action="upload-image">📤 Upload Image</button>' +
            '<button class="gdc-email-ai-quick-btn" data-action="skip-visuals">➡️ Skip Visuals</button>' +
            '</div>');

        $chatThread.append($actions);
        $chatThread.scrollTop($chatThread[0].scrollHeight);
    }

    /**
     * Start Image Generation Chat Flow
     */
    function startImageGeneration() {
        addChatMessage('user', 'Generate an image');
        var response = "**Let's create something unique.** 🎨\n\n" +
            "Describe the image you want, or type 'suggest' and I'll come up with a prompt based on your email copy.";
        addChatMessage('assistant', response);
        state.workflow.currentStep = 'image_generation';
        // Scroll to bottom
        $chatThread.scrollTop($chatThread[0].scrollHeight);
    }

    /**
     * Generate Image from Prompt (Mock for now)
     */
    function generateImageFromPrompt(prompt) {
        var $thinking = $('<div>').addClass('gdc-email-ai-msg gdc-email-ai-msg--thinking').text('Leo is generating your image...');
        $chatThread.append($thinking);
        $chatThread.scrollTop($chatThread[0].scrollHeight);

        state.isGenerating = true;

        // Simulate API delay
        setTimeout(function () {
            $thinking.remove();
            state.isGenerating = false;

            // Placeholder image logic
            var imageUrl = 'https://picsum.photos/600/300?grammar=' + encodeURIComponent(prompt.substring(0, 10));
            // In a real implementation, this would call DALL-E or Stable Diffusion via backend

            var response = "**Here is a concept for you.** 🖼️\n\n" +
                "I've generated an image based on: \"" + prompt + "\"\n\n" +
                "_(Note: This is a placeholder image for demonstration)_\n\n" +
                "Should we **Use This** or **Try Again**?";

            addChatMessage('assistant', response);

            // Add image preview card
            var $card = $('<div class="gdc-email-ai-image-card" style="margin: 10px 0; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">' +
                '<img src="' + imageUrl + '" style="width: 100%; height: auto; display: block;">' +
                '<div style="padding: 10px; display: flex; gap: 10px;">' +
                '<button class="gdc-email-ai-quick-btn" data-action="use-generated-image" data-url="' + imageUrl + '">Use This</button>' +
                '<button class="gdc-email-ai-quick-btn" data-action="generate-image">Try Again</button>' +
                '</div>' +
                '</div>');

            // Allow clicking buttons inside the card
            $card.find('[data-action="use-generated-image"]').on('click', function () {
                insertImageToEditor($(this).data('url'));
                addChatMessage('user', 'Use this image');
                moveToFinalReview();
            });
            // Try again is handled by existing generic listener re-triggering startImageGeneration? 
            // Wait, existing listener calls handleQuickAction('generate-image'). 
            // handleQuickAction calls startImageGeneration if step is visual_assets.
            // But checking equality: if step is image_generation, we might want to allow 'generate-image' to work too.
            // I should update handleQuickAction to also allow 'generate-image' if step is image_generation.

            $chatThread.append($card);
            $chatThread.scrollTop($chatThread[0].scrollHeight);

        }, 2000);
    }

    /**
     * Insert Image into Editor
     */
    function insertImageToEditor(url) {
        var imgHtml = '<img src="' + url + '" alt="Email Image" style="max-width: 100%; height: auto; display: block; margin: 20px auto;">';
        var content = getEditorContent();
        // Prepend for now
        setEditorContent(imgHtml + "<br>" + content);

        // Flash feedback
        var $editor = $('.gdc-email-ai-editor-wrap');
        $editor.addClass('gdc-email-ai-field-updated');
        setTimeout(function () {
            $editor.removeClass('gdc-email-ai-field-updated');
        }, 2000);
    }

    /**
     * Open Media Library
     */
    function openMediaLibrary() {
        if (typeof wp === 'undefined' || !wp.media) {
            alert('Media Library not available.');
            return;
        }

        var frame = wp.media({
            title: 'Select Email Image',
            multiple: false,
            library: { type: 'image' },
            button: { text: 'Insert Image' }
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            if (attachment && attachment.url) {
                insertImageToEditor(attachment.url);
                addChatMessage('user', 'Uploaded image');
                addChatMessage('assistant', "**Excellent choice.** 📸\n\nMoving to final review.");
                moveToFinalReview();
            }
        });

        frame.open();
    }

    /**
     * Move to Final Review
     */
    function moveToFinalReview() {
        state.workflow.currentStep = 'theme_send';
        var response = "**Final Review Time!** 🚀\n\n" +
            "Your email is taking shape. Check the preview on the left.\n\n" +
            "**Want to adjust the style?** Pick a theme below, or just hit Send if you're ready.";

        addChatMessage('assistant', response);

        // Add Theme Buttons
        var $actions = $('<div class="gdc-email-ai-campaign-actions theme-actions">' +
            '<button class="gdc-email-ai-theme-btn" data-theme="minimalist">⚪ Minimalist</button>' +
            '<button class="gdc-email-ai-theme-btn" data-theme="bold">🟣 Bold & Colorful</button>' +
            '<button class="gdc-email-ai-theme-btn" data-theme="luxury">✨ Luxury</button>' +
            '<button class="gdc-email-ai-theme-btn" data-theme="dark">⚫ Dark Mode</button>' +
            '</div>');

        $chatThread.append($actions);
        $chatThread.scrollTop($chatThread[0].scrollHeight);
    }

    /**
     * Apply Theme
     */
    function applyTheme(theme) {
        var themePrompts = {
            'minimalist': 'Apply a Minimalist theme: Uses lots of white space, clean sans-serif fonts, black text, and subtle gray accents. Simple and elegant.',
            'bold': 'Apply a Bold theme: Use vibrant primary brand colors (Indigo/Cyan) for backgrounds and buttons. Large headings, high contrast. Energetic vibe.',
            'luxury': 'Apply a Luxury theme: Use serif headings (Playfair Display or similar), gold/black/cream color palette. Sophisticated and high-end feel.',
            'dark': 'Apply a Dark Mode theme: Dark background (#1a1a1a), light text (#f0f0f0). High contrast accents. Modern and sleek.'
        };

        var instruction = themePrompts[theme] || 'Apply a ' + theme + ' theme.';

        // Remove theme buttons
        $('.theme-actions').remove();

        var $thinking = $('<div>').addClass('gdc-email-ai-msg gdc-email-ai-msg--thinking').text('Leo is restyling your email...');
        $chatThread.append($thinking);
        $chatThread.scrollTop($chatThread[0].scrollHeight);

        state.isGenerating = true;

        var currentHtml = getEditorContent();

        var systemPrompt = "You are an expert Email Designer.\n\n" +
            "Task: Rewrite the following HTML email to match the requested THEME.\n" +
            "Theme Instruction: " + instruction + "\n" +
            "Key Constraints:\n" +
            "1. Keep the same text content, just change styles (colors, fonts, spacing, borders).\n" +
            "2. Output valid HTML with inline CSS.\n" +
            "3. Return ONLY the HTML.\n\n" +
            "Current HTML:\n" + currentHtml;

        callAiEndpoint("Apply theme: " + theme, {
            email_context: true,
            campaign_architect_mode: true,
            workflow_step: 'theme_application',
            theme: theme,
            system_prompt_override: systemPrompt
        })
            .then(function (response) {
                $thinking.remove();
                handleThemeResponse(response);
            })
            .catch(function (error) {
                $thinking.remove();
                addChatMessage('assistant', "I couldn't apply the theme. Please try again.");
            })
            .always(function () {
                state.isGenerating = false;
            });
    }

    /**
     * Handle Theme Response
     */
    function handleThemeResponse(response) {
        var content = '';
        if (response && response.choices && response.choices[0]) {
            content = response.choices[0].message.content;
        } else if (response && response.content) {
            content = response.content;
        } else if (typeof response === 'string') {
            content = response;
        }

        content = content.replace(/```html/g, '').replace(/```/g, '').trim();
        updateFormField('body', content);

        var responseMsg = "**Theme Applied!** 🎨\n\nHow does it look? You can try another theme or proceed to send.";
        addChatMessage('assistant', responseMsg);

        // Show theme buttons again (in case they want to switch back)
        var $actions = $('<div class="gdc-email-ai-campaign-actions theme-actions">' +
            '<button class="gdc-email-ai-theme-btn" data-theme="minimalist">⚪ Minimalist</button>' +
            '<button class="gdc-email-ai-theme-btn" data-theme="bold">🟣 Bold</button>' +
            '<button class="gdc-email-ai-theme-btn" data-theme="luxury">✨ Luxury</button>' +
            '<button class="gdc-email-ai-theme-btn" data-theme="dark">⚫ Dark</button>' +
            '</div>');
        $chatThread.append($actions);
        $chatThread.scrollTop($chatThread[0].scrollHeight);
    }

    // Fix for Tab Switching (Top Level & Sub Tabs)
    $(document).on('click', '.gdc-sub-tab, .gdc-subtab', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var isSub = $btn.hasClass('gdc-subtab');
        var tabId = $btn.data('tab') || $btn.data('subtab');

        // Determine scope
        var $scope = $btn.closest('.widefat, .gdc-page-header').nextAll('.gdc-tabpanels, .gdc-subtab-panel').first();
        if (!$scope.length) $scope = $(document); // Fallback

        // Deactivate siblings
        $btn.siblings().removeClass('active').attr('aria-selected', 'false');
        $btn.addClass('active').attr('aria-selected', 'true');

        // Toggle Panels
        if (isSub) {
            // Subtabs (Lists, Onboarding, Newsletters)
            var $panelContainer = $btn.closest('.gdc-sub-tabpanel');
            $panelContainer.find('.gdc-subtab-panel').attr('hidden', true).hide();
            $panelContainer.find('.gdc-subtab-panel[data-subpanel="' + tabId + '"]').removeAttr('hidden').show();
        } else {
            // Top Level Tabs (Engagement, Proposals...)
            $('.gdc-sub-tabpanel').attr('hidden', true).hide();
            $('.gdc-sub-tabpanel[data-panel="' + tabId + '"]').removeAttr('hidden').show();
        }
    });

    // Initialize on DOM ready
    $(document).ready(function () {
        // Only init on email page
        if ($('.gdc-email-page').length || $('.gdc-scheduled-panel').length) {
            init();
        }
    });

    // Expose functions globally for other plugins (e.g. Sales Team)
    window.openEditor = openEditor;
    window.closeEditor = closeEditor;

})(jQuery);
