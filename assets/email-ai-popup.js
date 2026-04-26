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
        storeRecipients: {
            sendToCustomer: true,
            extraEmails: []
        },
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

    // Config from WordPress
    var config = window.GDC_EMAIL_AI_CONFIG || {};

    /**
     * Initialize the popup system
     */
    function init() {
        console.log('[GDC Email AI] init() started.');
        if ($('.gdc-email-ai-modal').length) {
            console.log('[GDC Email AI] .gdc-email-ai-modal already exists, skipping init.');
            return; // Already initialized
        }

        // Create popup HTML
        createPopupHTML();
        console.log('[GDC Email AI] createPopupHTML() finished.');

        // Cache refs
        $modal = $('.gdc-email-ai-modal');
        $addModal = $('.gdc-email-add-modal');
        $subjectField = $('#gdc-email-ai-subject');
        $preheaderField = $('#gdc-email-ai-preheader');
        $bodyField = $('#gdc-email-ai-body');

        // Bind UI events
        console.log('[GDC Email AI] calling bindEvents().');
        bindEvents();
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
          <!-- Campaign Name (for Proposals) -->
          <div class="gdc-email-ai-field gdc-email-ai-campaign-name" style="display:none;">
            <label for="gdc-email-ai-campaign-name">Campaign Name</label>
            <input type="text" id="gdc-email-ai-campaign-name" placeholder="e.g. Q1 Proposal, Summer Outreach...">
          </div>
          <!-- Recipients (for Proposals) -->
          <div class="gdc-email-ai-field gdc-email-ai-recipients" style="display:none;">
            <label>Recipients</label>
            <div class="gdc-email-ai-recipients-wrap">
              <div class="gdc-email-ai-recipients-tags" id="gdc-email-ai-recipients-tags"></div>
              <input type="text" id="gdc-email-ai-recipient-search" placeholder="Search members or enter email..." autocomplete="off">
              <div class="gdc-email-ai-recipients-dropdown" id="gdc-email-ai-recipients-dropdown" style="display:none;"></div>
            </div>
          </div>
          <!-- Enable / Disable (for Store emails) -->
          <div class="gdc-email-ai-field gdc-email-ai-status-field" style="display:none;">
            <style>
              .gdc-row-toggle { display:flex; align-items:center; gap:12px; padding:10px 14px; background:rgba(15,23,42,.6); border:1px solid #1e293b; border-radius:8px; }
              .gdc-row-toggle .gdc-toggle-label { font-size:13px; color:#cbd5e1; flex:1; }
              .gdc-row-toggle .gdc-toggle-label strong { display:block; color:#e2e8f0; font-weight:600; }
              .gdc-pill-toggle { position:relative; width:40px; height:22px; flex-shrink:0; }
              .gdc-pill-toggle input { opacity:0; width:0; height:0; position:absolute; }
              .gdc-pill-slider { position:absolute; inset:0; background:#334155; border-radius:11px; cursor:pointer; transition:background .2s; }
              .gdc-pill-slider::after { content:''; position:absolute; left:3px; top:3px; width:16px; height:16px; background:#fff; border-radius:50%; transition:transform .2s; }
              .gdc-pill-toggle input:checked + .gdc-pill-slider { background:#22c55e; }
              .gdc-pill-toggle input:checked + .gdc-pill-slider::after { transform:translateX(18px); }
              .gdc-save-status { font-size:11px; color:#64748b; margin-left:8px; }
              .gdc-save-status.saving { color:#f59e0b; }
              .gdc-save-status.saved { color:#22c55e; }
              .gdc-save-status.error { color:#ef4444; }
              .gdc-extra-tags-wrap { display:flex; flex-wrap:wrap; gap:6px; padding:8px; background:#0f172a; border:1px solid #334155; border-radius:8px; min-height:42px; align-items:center; cursor:text; }
              .gdc-extra-tag { display:inline-flex; align-items:center; gap:5px; background:#1e3a5f; border:1px solid #2563eb; border-radius:20px; padding:3px 10px; font-size:12px; color:#93c5fd; }
              .gdc-extra-tag-remove { background:none; border:none; color:#93c5fd; cursor:pointer; font-size:14px; line-height:1; padding:0; opacity:.7; }
              .gdc-extra-tag-remove:hover { opacity:1; }
              .gdc-extra-tag-input { border:none; background:transparent; outline:none; font-size:13px; color:#e2e8f0; min-width:200px; flex:1; padding:2px 4px; }
              .gdc-extra-tag-input::placeholder { color:#475569; }
              .gdc-recipient-hint { font-size:11px; color:#64748b; margin-top:6px; }
              .gdc-recipient-row { display:flex; align-items:center; gap:12px; padding:10px 14px; background:rgba(99,102,241,.06); border:1px solid rgba(99,102,241,.18); border-radius:8px; margin-bottom:10px; }
              .gdc-recipient-row .gdc-toggle-label { font-size:13px; color:#cbd5e1; flex:1; }
              .gdc-recipient-row .gdc-toggle-label strong { display:block; color:#e2e8f0; font-weight:600; }
            </style>
            <div class="gdc-row-toggle">
              <span class="gdc-toggle-label"><strong>Email status</strong>Enable or disable this automated email</span>
              <label class="gdc-pill-toggle">
                <input type="checkbox" id="gdc-email-status-toggle" checked>
                <span class="gdc-pill-slider"></span>
              </label>
              <span class="gdc-save-status" id="gdc-email-status-save-msg"></span>
            </div>
          </div>
          <!-- Recipients (for Store/WooCommerce emails) -->
          <div class="gdc-email-ai-field gdc-email-ai-store-recipients" style="display:none;">
            <label>Recipients</label>
            <div class="gdc-recipient-row" id="gdc-send-to-customer-toggle">
              <label class="gdc-pill-toggle">
                <input type="checkbox" id="gdc-email-send-to-customer" checked>
                <span class="gdc-pill-slider"></span>
              </label>
              <span class="gdc-toggle-label" id="gdc-recipient-toggle-label">
                <strong>Send to customer</strong>
                Automatically delivered to the order purchaser
              </span>
            </div>
            <span style="font-size:12px;color:#94a3b8;font-weight:500;margin-bottom:6px;display:block;">Additional recipients</span>
            <div class="gdc-extra-tags-wrap" id="gdc-extra-tags-wrap">
              <input type="text" class="gdc-extra-tag-input" id="gdc-extra-recipient-input" placeholder="Enter email address, press Enter or comma to add...">
            </div>
            <p class="gdc-recipient-hint">These addresses will also receive this email every time it is triggered.</p>
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
            
            <!-- Shortcode tokens (store emails only) -->
            <div class="gdc-email-tokens-wrap" id="gdc-email-tokens-wrap" style="display:none;margin-bottom:10px;">
              <style>
                .gdc-email-tokens-wrap { background:rgba(15,23,42,.7); border:1px solid #1e3a5f; border-radius:8px; padding:10px 12px; }
                .gdc-tokens-label { font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px; display:block; }
                .gdc-tokens-list { display:flex; flex-wrap:wrap; gap:5px; }
                .gdc-token-chip { display:inline-flex; align-items:center; background:#0f172a; border:1px solid #334155; border-radius:4px; padding:3px 8px; font-size:11px; font-family:monospace; color:#7dd3fc; cursor:pointer; transition:background .15s,border-color .15s; user-select:none; }
                .gdc-token-chip:hover { background:#1e3a5f; border-color:#3b82f6; color:#fff; }
                .gdc-token-chip:active { background:#1d4ed8; }
                .gdc-tokens-copied { font-size:11px; color:#34d399; margin-left:6px; opacity:0; transition:opacity .2s; }
                .gdc-tokens-copied.show { opacity:1; }
              </style>
              <span class="gdc-tokens-label">Available shortcodes — click to insert into body</span>
              <div class="gdc-tokens-list" id="gdc-tokens-list"></div>
            </div>
            <label for="gdc-email-ai-body">Email Body</label>
            <div class="gdc-email-editor-wrapper">
              <div class="gdc-email-editor-tabs">
                <button type="button" class="gdc-editor-tab active" data-mode="visual">Visual</button>
                <button type="button" class="gdc-editor-tab" data-mode="code">Code</button>
              </div>
              
              <!-- Toolbar (Visual Mode Only) -->
              <div class="gdc-email-editor-toolbar" id="gdc-editor-toolbar">
                <select class="gdc-editor-format-block" title="Format Text" style="margin-right: 4px; padding: 2px 4px; border: 1px solid transparent; border-radius: 4px; font-size: 13px; color: #475569; background: transparent; cursor: pointer; outline: none;">
                  <option value="P">Paragraph</option>
                  <option value="H1">Heading 1</option>
                  <option value="H2">Heading 2</option>
                  <option value="H3">Heading 3</option>
                  <option value="H4">Heading 4</option>
                  <option value="BLOCKQUOTE">Quote</option>
                </select>
                <div style="width: 1px; height: 20px; background: #e2e8f0; margin: 0 4px;"></div>
                <button type="button" class="gdc-editor-btn" data-cmd="bold" title="Bold"><b>B</b></button>
                <button type="button" class="gdc-editor-btn" data-cmd="italic" title="Italic"><i>I</i></button>
                <button type="button" class="gdc-editor-btn" data-cmd="underline" title="Underline"><u>U</u></button>
                <button type="button" class="gdc-editor-btn" data-cmd="strikethrough" title="Strikethrough"><span class="dashicons dashicons-editor-strikethrough"></span></button>
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
                 <div style="width: 1px; height: 20px; background: #e2e8f0; margin: 0 4px;"></div>
                 <button type="button" class="gdc-editor-btn" data-cmd="undo" title="Undo"><span class="dashicons dashicons-undo"></span></button>
                 <button type="button" class="gdc-editor-btn" data-cmd="redo" title="Redo"><span class="dashicons dashicons-redo"></span></button>
                 <button type="button" class="gdc-editor-btn" data-cmd="removeFormat" title="Clear Formatting"><span class="dashicons dashicons-editor-removeformatting"></span></button>
                 <div style="width: 1px; height: 20px; background: #e2e8f0; margin: 0 4px;"></div>
                 <button type="button" class="gdc-editor-btn gdc-editor-add-media" title="Add Media"><span class="dashicons dashicons-admin-media"></span></button>
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
        console.log('[GDC Email AI] bindEvents() started.');
        // CRITICAL: Unbind any existing handlers from the old inline script
        // so our new popup opens instead of the old iframe-based modal
        $(document).off('click', '.gdc-email-open-editor');
        $(document).off('click', '#gdc-email-scheduled-add');
        $(document).off('click', '#gdc-email-add-list'); // Unbind Elemailer handler



        // Open editor via Edit button
        $(document).on('click', '.gdc-email-open-editor', function (e) {
            console.log('[GDC Email AI] .gdc-email-open-editor clicked.');
            e.preventDefault();
            e.stopPropagation();

            var emailData = {};
            var b64Data = $(this).attr('data-email-b64');
            var parsed = false;

            if (b64Data) {
                try {
                    var decodedStr = decodeURIComponent(escape(window.atob(b64Data)));
                    emailData = JSON.parse(decodedStr);
                    parsed = true;
                } catch (err) {
                    console.warn('[GDC Email AI] Base64 decode failed', err);
                }
            }

            if (!parsed) {
                var rawData = $(this).attr('data-email');
                if (rawData) {
                    try {
                        emailData = JSON.parse(rawData);
                    } catch (err) {
                        console.warn('[GDC Email AI] Standard JSON.parse failed, falling back to jQuery .data()', err);
                        emailData = $(this).data('email');
                        if (typeof emailData === 'string') {
                            try { emailData = JSON.parse(emailData); } catch(e) {}
                        }
                    }
                }
            }

            if (!emailData || typeof emailData !== 'object') {
                emailData = {};
            }

            // Always open custom editor for all types (Raw HTML Mode)
            openEditor(emailData);
            return false;
        });

        // Open Add New modal
        $(document).on('click', '#gdc-email-scheduled-add', function (e) {
            console.log('[GDC Email AI] #gdc-email-scheduled-add clicked.');
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

        // Quick action buttons
        $(document).on('click', '.gdc-email-ai-quick-btn', function (e) {
            e.preventDefault();
            var action = $(this).data('action');
            handleQuickAction(action);
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
            if ($(this).hasClass('gdc-editor-add-media')) return; // handled separately below
            e.preventDefault();
            var cmd = $(this).data('cmd');
            document.execCommand(cmd, false, null);
            // Sync immediately
            var html = $('#gdc-email-visual-editor').html();
            $('#gdc-email-ai-body').val(html);
        });

        // Format Block Dropdown
        $(document).on('change', '.gdc-editor-format-block', function (e) {
            var val = $(this).val();
            document.execCommand('formatBlock', false, val);
            // Sync immediately
            var html = $('#gdc-email-visual-editor').html();
            $('#gdc-email-ai-body').val(html);
            $(this).val('P'); // reset
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

        // Add Media Handler for custom editor
        $(document).on('click', '.gdc-editor-add-media', function (e) {
            e.preventDefault();
            $('#gdc-email-visual-editor').focus();

            var mediaUploader = wp.media({
                title: 'Add Media to Email',
                button: { text: 'Insert into Email' },
                multiple: false
            });
            mediaUploader.on('select', function () {
                var attachment = mediaUploader.state().get('selection').first().toJSON();
                var imgHtml = '<img src="' + attachment.url + '" alt="' + (attachment.alt || '') + '" style="max-width: 100%; height: auto;"/> ';
                document.execCommand('insertHTML', false, imgHtml);
                $('#gdc-email-ai-body').val($('#gdc-email-visual-editor').html());
            });
            mediaUploader.open();
        });

        // Token chip click — insert into active body editor
        $(document).on('click', '.gdc-token-chip', function (e) {
            e.preventDefault();
            var tok = $(this).data('token');
            if (!tok) { return; }
            var $visual = $('#gdc-email-visual-editor');
            var $code = $('#gdc-email-ai-body');
            if ($code.hasClass('active')) {
                // Code mode: insert at cursor
                var el = $code[0];
                var start = el.selectionStart;
                var end = el.selectionEnd;
                var val = el.value;
                el.value = val.substring(0, start) + tok + val.substring(end);
                el.selectionStart = el.selectionEnd = start + tok.length;
                el.focus();
                $visual.html(el.value);
            } else {
                // Visual mode: insert at cursor/end
                $visual.focus();
                document.execCommand('insertText', false, tok);
                $code.val($visual.html());
            }
        });

        // Close notice dismiss button
        $(document).on('click', '#gdc-store-notice-close', function () {
            closeStoreEmailNotice();
        });

        // Reset to WC default button (wipes saved override, re-fetches)
        $(document).on('click', '.gdc-email-reset-btn', function () {
            if (!state.currentEmail || !state.currentEmail.id) return;
            var $btn = $(this);
            $btn.prop('disabled', true).text('Resetting…');
            var saveEndpoint = config.wcEmailSaveEndpoint || ((config.root || '/wp-json/') + 'em/v1/wc-email-save');
            $.ajax({
                url: saveEndpoint,
                method: 'POST',
                contentType: 'application/json',
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', config.nonce || ''); },
                data: JSON.stringify({ email_id: state.currentEmail.id, additional_content: '' })
            })
            .done(function () {
                // Re-fetch the WC default render
                var renderUrl = (config.wcEmailRenderEndpoint || ((config.root || '/wp-json/') + 'em/v1/wc-email-render'))
                    + '?email_id=' + encodeURIComponent(state.currentEmail.id);
                $.ajax({ url: renderUrl, method: 'GET',
                    beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', config.nonce || ''); } })
                .done(function (res) {
                    if (res && res.html) { setEditorContent(res.html); }
                    showStoreEmailNotice('Template reset to WooCommerce default. Edit and <strong>Save</strong> to create your custom version.');
                });
            })
            .fail(function () { $btn.prop('disabled', false).text('Reset to WC default'); });
        });

        // Store recipients: send-to-customer checkbox change
        $(document).on('change', '#gdc-email-send-to-customer', function () {
            state.storeRecipients.sendToCustomer = $(this).is(':checked');
        });

        // Email status enable/disable toggle — auto-saves to WooCommerce settings
        $(document).on('change', '#gdc-email-status-toggle', function () {
            var enabled = $(this).is(':checked');
            var $msg = $('#gdc-email-status-save-msg');
            var emailId = (state.currentEmail && state.currentEmail.id) ? state.currentEmail.id : '';
            if (!emailId) { return; }
            $msg.text('Saving…').removeClass('saved error').addClass('saving');
            $.ajax({
                url: (config.root || '/wp-json/') + 'em/v1/wc-email-toggle',
                method: 'POST',
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
                },
                data: JSON.stringify({ email_id: emailId, enabled: enabled }),
                success: function (res) {
                    if (res && res.success) {
                        $msg.text(enabled ? 'Enabled' : 'Disabled').removeClass('saving error').addClass('saved');
                        setTimeout(function () { $msg.text('').removeClass('saved'); }, 2500);
                    } else {
                        $msg.text('Save failed').removeClass('saving saved').addClass('error');
                        $('#gdc-email-status-toggle').prop('checked', !enabled);
                    }
                },
                error: function () {
                    $msg.text('Save failed').removeClass('saving saved').addClass('error');
                    $('#gdc-email-status-toggle').prop('checked', !enabled);
                }
            });
        });

        // Store recipients: add chip on Enter or comma
        $(document).on('keydown', '#gdc-extra-recipient-input', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                var val = $(this).val().replace(/,/g, '').trim();
                if (val) { addStoreExtraEmail(val); }
                $(this).val('');
            }
        });

        // Store recipients: add chip on blur
        $(document).on('blur', '#gdc-extra-recipient-input', function () {
            var val = $(this).val().trim();
            if (val) { addStoreExtraEmail(val); $(this).val(''); }
        });

        // Store recipients: click wrap focuses input
        $(document).on('click', '#gdc-extra-tags-wrap', function (e) {
            if (!$(e.target).hasClass('gdc-extra-tag-remove') && !$(e.target).closest('.gdc-extra-tag').length) {
                $('#gdc-extra-recipient-input').focus();
            }
        });

        // Campaign name input: update the modal header label live
        $(document).on('input', '#gdc-email-ai-campaign-name', function () {
            var val = $(this).val().trim();
            $('.gdc-email-ai-modal__email-label').text(val || 'New Proposal Email');
        });

        // Store recipients: remove chip
        $(document).on('click', '.gdc-extra-tag-remove', function (e) {
            e.stopPropagation();
            var email = $(this).data('email');
            state.storeRecipients.extraEmails = state.storeRecipients.extraEmails.filter(function (e) { return e !== email; });
            renderStoreExtraTags();
        });

        // Send Test Email button
        $(document).on('click', '.gdc-email-ai-send-test', function (e) {
            e.preventDefault();
            sendTestEmail();
        });

        // Save Draft button
        $(document).on('click', '.gdc-email-ai-save', function (e) {
            e.preventDefault();
            saveEmail();
        });

        // Preview button
        $(document).on('click', '.gdc-email-ai-preview', function (e) {
            e.preventDefault();
            previewEmail();
        });

        // Send Email button
        $(document).on('click', '.gdc-email-ai-send', function (e) {
            e.preventDefault();
            sendEmail();
        });

        // Send mode toggle (Immediately / Schedule)
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

        // Recipient search input
        $(document).on('input', '#gdc-email-ai-recipient-search', debounce(function () {
            searchRecipients();
        }, 300));

        // Recipient dropdown item click
        $(document).on('click', '.gdc-email-ai-recipient-item', function (e) {
            e.preventDefault();
            var email  = $(this).data('email');
            var name   = $(this).data('name');
            var avatar = $(this).data('avatar');
            addRecipient(email, name, avatar);
        });

        // Recipient tag remove
        $(document).on('click', '.gdc-email-ai-recipient-remove', function (e) {
            e.preventDefault();
            var email = $(this).closest('.gdc-email-ai-recipient-tag').data('email');
            state.recipients = state.recipients.filter(function (r) { return r.email !== email; });
            renderRecipientTags();
        });

        // Manual email add on Enter/comma in recipient search
        $(document).on('keydown', '#gdc-email-ai-recipient-search', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                var val = $(this).val().replace(/,/g, '').trim();
                if (val) { addManualEmail(val); }
            }
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

        // Reset store recipients UI
        state.storeRecipients = { sendToCustomer: true, extraEmails: [] };
        renderStoreExtraTags();
        $('#gdc-extra-recipient-input').val('');

        // Reset schedule UI
        $('.gdc-email-ai-toggle-btn').removeClass('active');
        $('.gdc-email-ai-toggle-btn[data-mode="immediate"]').addClass('active');
        $('.gdc-email-ai-schedule-picker').hide();
        $('.gdc-email-ai-send').text('Send Email');
        var tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        $('#gdc-email-ai-schedule-date').val(tomorrow.toISOString().split('T')[0]);
        $('#gdc-email-ai-schedule-time').val('09:00');

        // Show/hide section-specific panels
        var section = (emailData.section || '').toLowerCase();
        var isProposal = section === 'proposals';
        var isStore = section === 'store';
        var isCommunity = section === 'community';

        // Always reset campaign name field
        $('#gdc-email-ai-campaign-name').val('');
        $('.gdc-email-ai-campaign-name').hide();

        if (isProposal) {
            $('.gdc-email-ai-campaign-name').show();
            $('#gdc-email-ai-campaign-name').val(emailData.label || '');
            $('.gdc-email-ai-recipients').show();
            $('.gdc-email-ai-send-options').show();
            $('.gdc-email-ai-send').show();
            $('.gdc-email-ai-store-recipients').hide();
            $('.gdc-email-ai-status-field').hide();
            $('#gdc-email-tokens-wrap').hide();
        } else if (isStore || isCommunity) {
            $('.gdc-email-ai-recipients').hide();
            $('.gdc-email-ai-send-options').hide();
            $('.gdc-email-ai-send').hide();

            // Rename the save button to just "Save" for store/WC emails (not a draft)
            $('.gdc-email-ai-save').text('Save');

            // Shortcode tokens
            var tokens = (emailData.tokens && Array.isArray(emailData.tokens)) ? emailData.tokens : [];
            var $tokensList = $('#gdc-tokens-list');
            $tokensList.empty();
            if (tokens.length) {
                tokens.forEach(function (tok) {
                    $tokensList.append('<span class="gdc-token-chip" data-token="' + $('<span>').text(tok).html() + '">' + $('<span>').text(tok).html() + '</span>');
                });
                $('#gdc-email-tokens-wrap').show();
            } else {
                $('#gdc-email-tokens-wrap').hide();
            }

            // Enable/disable toggle
            var isEnabled = emailData.is_enabled !== false;
            $('#gdc-email-status-toggle').prop('checked', isEnabled);
            $('#gdc-email-status-save-msg').text('').removeClass('saving saved error');
            $('.gdc-email-ai-status-field').show();

            // Recipients: send_to_customer
            var sendToCustomer = emailData.send_to_customer === true;
            state.storeRecipients.sendToCustomer = sendToCustomer;
            $('#gdc-email-send-to-customer').prop('checked', sendToCustomer);
            var recipientTitle = sendToCustomer ? 'Send to customer' : 'Send to admin';
            var recipientDesc = sendToCustomer
                ? 'Automatically delivered to the order purchaser'
                : 'Delivered to: ' + (emailData.wc_recipient || 'site admin');
            $('#gdc-recipient-toggle-label').html('<strong>' + recipientTitle + '</strong>' + recipientDesc);

            // Extra recipients
            if (emailData.extra_recipients && Array.isArray(emailData.extra_recipients)) {
                state.storeRecipients.extraEmails = emailData.extra_recipients.slice();
                renderStoreExtraTags();
            }
            $('.gdc-email-ai-store-recipients').show();

            // ── Force Code (raw HTML) tab for store/community emails ──────────
            // The visual editor is a contenteditable div; setting .html() on it
            // strips <DOCTYPE>, <html>, <head>, <style> — losing the email
            // envelope. Editing in Code tab preserves the full document.
            $('.gdc-editor-tab').removeClass('active');
            $('.gdc-editor-tab[data-mode="code"]').addClass('active');
            $('#gdc-email-visual-editor').hide();
            $('#gdc-editor-toolbar').hide();
            // Body textarea stays shown in code mode
            $('#gdc-email-ai-body').show().addClass('active');

            if (isCommunity) {
                // Community emails just load the saved body directly
                $bodyField.val(bodyContent || '');
                showStoreEmailNotice('Editing Social Network Email. Use <strong>{token}</strong> chips for dynamic content. Click <strong>Save</strong> to apply changes.');
            } else {
                // \u2500\u2500 Fetch full email HTML from server (Store / WP Core)  \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
                // In code-mode we write directly to the textarea so the full
                // HTML document (DOCTYPE / html / head / style) is preserved.
                if (!bodyContent || bodyContent.length < 100) {
                    $bodyField.val('Loading email template\u2026');

                    var renderUrl = (config.wcEmailRenderEndpoint || ((config.root || '/wp-json/') + 'em/v1/wc-email-render'))
                        + '?email_id=' + encodeURIComponent(emailData.id || '');

                    $.ajax({
                        url: renderUrl,
                        method: 'GET',
                        beforeSend: function (xhr) {
                            xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
                        }
                    })
                    .done(function (res) {
                        if (res && res.html) {
                            // Write full HTML directly to textarea (preserves DOCTYPE/html/head/style)
                            $bodyField.val(res.html);
                            if (res.source === 'woocommerce') {
                                showStoreEmailNotice('Showing the live WooCommerce email. Edit the HTML below and click <strong>Save</strong> to use your custom version. Insert <strong>{token}</strong> chips for dynamic data.');
                            } else {
                                showStoreEmailNotice('Editing your saved custom template. Use <strong>{token}</strong> chips for dynamic order data. <button type="button" class="gdc-email-reset-btn">Reset to WC default</button>');
                            }
                        } else {
                            $bodyField.val('<!-- Could not load email template. Place a test order first, then re-open. -->');
                        }
                    })
                    .fail(function () {
                        $bodyField.val('<!-- Failed to load email template. Check REST API access. -->');
                    });
                } else {
                    // Already have saved override HTML in the textarea \u2014 show notice
                    showStoreEmailNotice('Editing your saved custom template. Use <strong>{token}</strong> chips for dynamic order data. <button type="button" class="gdc-email-reset-btn">Reset to WC default</button>');
                }
            }

        } else {
            $('.gdc-email-ai-campaign-name').hide();
            $('.gdc-email-ai-recipients').hide();
            $('.gdc-email-ai-send-options').hide();
            $('.gdc-email-ai-send').hide();
            $('.gdc-email-ai-store-recipients').hide();
            $('.gdc-email-ai-status-field').hide();
            $('#gdc-email-tokens-wrap').hide();
            // Restore default save button label for non-store emails
            $('.gdc-email-ai-save').text('Save Draft');
        }

        // Set global context for Leo Widget
        window.LEO_OVERRIDE_CONTEXT = 'email_architect';

        // Trigger the global AIPA widget
        if (window.AIPA_WIDGET && document.querySelector('aipa-widget')) {
            const aipa = document.querySelector('aipa-widget');
            if (typeof aipa.open === 'function') {
                aipa.open();
                // We'll let Leo flow logic handle the greeting internally based on context
            }
        }

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
     * Render store extra recipient tags into the UI
     */
    function renderStoreExtraTags() {
        var $wrap = $('#gdc-extra-tags-wrap');
        // Remove existing tags (keep the input)
        $wrap.find('.gdc-extra-tag').remove();
        var $input = $wrap.find('#gdc-extra-recipient-input');
        state.storeRecipients.extraEmails.forEach(function (email) {
            var $tag = $('<span class="gdc-extra-tag">'
                + '<span>' + $('<span>').text(email).html() + '</span>'
                + '<button type="button" class="gdc-extra-tag-remove" data-email="' + $('<span>').text(email).html() + '" title="Remove">&times;</button>'
                + '</span>');
            $wrap.prepend($tag);
        });
        // Ensure input stays last
        $wrap.append($input);
    }

    /**
     * Show an info notice banner inside the email editor for store emails.
     */
    function showStoreEmailNotice(message) {
        var $notice = $('#gdc-store-email-notice');
        if (!$notice.length) {
            $notice = $('<div id="gdc-store-email-notice" style="' +
                'margin:0 0 12px;padding:10px 14px;background:rgba(99,102,241,0.12);' +
                'border:1px solid rgba(99,102,241,0.3);border-radius:8px;font-size:12px;' +
                'color:#c7d2fe;line-height:1.5;display:flex;align-items:flex-start;gap:10px;"></div>');
            // Insert before the subject field
            $('.gdc-email-ai-modal__editor-form').prepend($notice);
        }
        $notice.html('<span style="flex:1;">ℹ️ ' + message + '</span>' +
            '<button type="button" id="gdc-store-notice-close" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:16px;line-height:1;padding:0;">✕</button>');
        $notice.show();
    }

    function closeStoreEmailNotice() {
        $('#gdc-store-email-notice').hide();
    }

    /**
     * Add a store extra recipient email chip
     */
    function addStoreExtraEmail(email) {
        email = email.trim().toLowerCase();
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { return; }
        if (state.storeRecipients.extraEmails.indexOf(email) !== -1) { return; }
        state.storeRecipients.extraEmails.push(email);
        renderStoreExtraTags();
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
     * Close the email editor popup
     */
    function closeEditor() {
        closeStoreEmailNotice();
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
     * Save email
     */
    /**
     * Update a row in the proposals table (or add it if new).
     * Called after a successful save or send so the table reflects the change
     * without needing a full page reload.
     */
    function updateProposalsTable(email) {
        var $tbody = $('.gdc-sub-tabpanel[data-panel="proposals"] table tbody');
        if (!$tbody.length) return;

        // Remove the "no emails yet" placeholder row if present
        $tbody.find('td[colspan]').closest('tr').remove();

        var statusLabel = email.status
            ? (email.status.charAt(0).toUpperCase() + email.status.slice(1))
            : 'Draft';

        var emailData = {
            _id:         email.id,
            section:     'proposals',
            label:       email.label      || 'Proposal Email',
            trigger:     email.trigger    || 'Proposal Sent',
            status:      email.status     || 'draft',
            subject:     email.subject    || '',
            preheader:   email.preheader  || '',
            html:        email.html       || '',
            description: ''
        };

        var rowHtml = '<tr data-email-id="' + email.id + '">' +
            '<td><strong>' + $('<span>').text(emailData.label).html() + '</strong></td>' +
            '<td>' + $('<span>').text(emailData.trigger).html() + '</td>' +
            '<td><span class="gdc-status-badge gdc-status-' + emailData.status + '">' + statusLabel + '</span></td>' +
            '<td><button type="button" class="button button-small gdc-email-open-editor"' +
            ' data-email-section="proposals"' +
            ' data-edit-title="Edit Email"' +
            ' data-email=\'' + JSON.stringify(emailData).replace(/'/g, '&#39;') + '\'>Edit</button></td>' +
            '</tr>';

        var $existing = $tbody.find('tr[data-email-id="' + email.id + '"]');
        if ($existing.length) {
            $existing.replaceWith(rowHtml);
        } else {
            $tbody.append(rowHtml);
        }
    }

    function saveEmail() {
        var section = state.currentEmail ? (state.currentEmail.section || 'general') : 'general';
        var label = (section === 'proposals')
            ? ($('#gdc-email-ai-campaign-name').val().trim() || 'Proposal Email')
            : (state.currentEmail ? (state.currentEmail.label || '') : '');

        var data = {
            section:   section,
            label:     label,
            subject:   $subjectField.val(),
            preheader: $preheaderField.val(),
            html:      getEditorContent(),
            _id:       state.currentEmail ? (state.currentEmail._id || null) : null
        };

        // Trigger custom event for external listeners
        $(document).trigger('gdc_email_ai_save', [data]);

        // ── WooCommerce store emails — persist back to WC settings ──────────
        if (section === 'store' && state.currentEmail && state.currentEmail.id) {
            var $saveBtn = $('.gdc-email-ai-save');
            $saveBtn.prop('disabled', true).text('Saving…');

            var saveEndpoint = (config.wcEmailSaveEndpoint)
                || ((config.root || '/wp-json/') + 'em/v1/wc-email-save');

            $.ajax({
                url: saveEndpoint,
                method: 'POST',
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
                },
                data: JSON.stringify({
                    email_id:           state.currentEmail.id,
                    subject:            data.subject,
                    heading:            data.preheader,
                    additional_content: data.html
                })
            })
            .done(function (response) {
                if (response && response.success) {
                    // Update the in-memory state so a re-open shows the saved values
                    if (state.currentEmail) {
                        state.currentEmail.subject   = data.subject;
                        state.currentEmail.preheader = data.preheader;
                        state.currentEmail.html      = data.html;
                    }
                    // Brief visual confirmation then close
                    $saveBtn.text('Saved! ✓').addClass('gdc-save-btn--success');
                    setTimeout(function () {
                        $saveBtn.prop('disabled', false).text('Save').removeClass('gdc-save-btn--success');
                        closeEditor();
                    }, 900);
                } else {
                    $saveBtn.prop('disabled', false).text('Save');
                    alert('Could not save email settings. Please try again.');
                }
            })
            .fail(function (xhr) {
                $saveBtn.prop('disabled', false).text('Save');
                var msg = 'Failed to save email settings.';
                if (xhr.responseJSON && xhr.responseJSON.message) { msg = xhr.responseJSON.message; }
                alert(msg);
            });
            return; // closeEditor() called inside .done()
        }

        // ── BuddyPress / Community emails — persist to bp-email post ──────────
        if (section === 'community' && state.currentEmail && state.currentEmail.id) {
            var $saveBtn = $('.gdc-email-ai-save');
            $saveBtn.prop('disabled', true).text('Saving…');

            var saveEndpoint = (config.bpEmailSaveEndpoint)
                || ((config.root || '/wp-json/') + 'em/v1/bp-email-save');

            $.ajax({
                url: saveEndpoint,
                method: 'POST',
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
                },
                data: JSON.stringify({
                    email_id:           state.currentEmail.id,
                    subject:            data.subject,
                    heading:            data.preheader,
                    additional_content: data.html
                })
            })
            .done(function (response) {
                if (response && response.success) {
                    if (state.currentEmail) {
                        state.currentEmail.subject   = data.subject;
                        state.currentEmail.preheader = data.preheader;
                        state.currentEmail.html      = data.html;
                    }
                    $saveBtn.text('Saved! ✓').addClass('gdc-save-btn--success');
                    setTimeout(function () {
                        $saveBtn.prop('disabled', false).text('Save').removeClass('gdc-save-btn--success');
                        closeEditor();
                    }, 900);
                } else {
                    $saveBtn.prop('disabled', false).text('Save');
                    alert('Could not save email. Please try again.');
                }
            })
            .fail(function (xhr) {
                $saveBtn.prop('disabled', false).text('Save');
                var msg = 'Failed to save email.';
                if (xhr.responseJSON && xhr.responseJSON.message) { msg = xhr.responseJSON.message; }
                alert(msg);
            });
            return;
        }

        // Persist proposal emails to the server; update the table on success
        if (data.section === 'proposals' && config.proposalEmailsEndpoint) {
            var $saveBtn = $('.gdc-email-ai-save');
            $saveBtn.prop('disabled', true).text('Saving…');
            $.ajax({
                url: config.proposalEmailsEndpoint,
                method: 'POST',
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
                },
                data: JSON.stringify(data)
            })
            .done(function (response) {
                if (response && response.success && response.email) {
                    // Store the ID back so re-saves update the same record
                    if (state.currentEmail) {
                        state.currentEmail._id = response.email.id;
                    }
                    updateProposalsTable(response.email);
                }
                closeEditor();
            })
            .fail(function () {
                $saveBtn.prop('disabled', false).text('Save Draft');
                alert('Failed to save draft. Please try again.');
            });
            return; // closeEditor() is called inside .done()
        }

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

        var section = state.currentEmail ? (state.currentEmail.section || '') : '';

        $.ajax({
            url: config.sendEmailEndpoint || '/wp-json/gdc/v1/send-email',
            method: 'POST',
            contentType: 'application/json',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
            },
            data: JSON.stringify(data)
        })
            .done(function () {
                var msg = state.sendMode === 'schedule' ? 'Email scheduled successfully!' : 'Email sent successfully!';

                // For proposal emails, record the sent/scheduled status in the proposals list
                if (section === 'proposals' && config.proposalEmailsEndpoint) {
                    var savedStatus = state.sendMode === 'schedule' ? 'scheduled' : 'sent';
                    var saveData = {
                        section:   'proposals',
                        label:     state.currentEmail ? (state.currentEmail.label || 'Proposal Email') : 'Proposal Email',
                        subject:   subject,
                        preheader: preheader,
                        html:      body,
                        _id:       state.currentEmail ? (state.currentEmail._id || null) : null,
                        status:    savedStatus
                    };
                    $.ajax({
                        url: config.proposalEmailsEndpoint,
                        method: 'POST',
                        contentType: 'application/json',
                        beforeSend: function (xhr) {
                            xhr.setRequestHeader('X-WP-Nonce', config.nonce || '');
                        },
                        data: JSON.stringify(saveData)
                    }).done(function (saveResponse) {
                        if (saveResponse && saveResponse.success && saveResponse.email) {
                            updateProposalsTable(saveResponse.email);
                        }
                    });
                }

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
        var _isEmailPage = ($('.gdc-email-page').length || $('.gdc-scheduled-panel').length || $('.gdc-store-settings').length || $('.gdc-network-settings').length);
        if (_isEmailPage) {
            init();
        }

        // Auto-open the configure modal when launched from Leo wireframe completion card.
        // PHP resolves the email data server-side and passes it via GDC_EMAIL_AI_CONFIG.configureEmail.
        if (config.configureEmail && config.configureEmail.id) {
            if (!$modal || !$modal.length) { init(); }
            var $targetTab = $('[data-tab="system-emails"]');
            if ($targetTab.length) { $targetTab.trigger('click'); }
            setTimeout(function () {
                openEditor(config.configureEmail);
            }, 300);
        }
    });

    // Expose functions globally for other plugins (e.g. Sales Team)
    window.openEditor = openEditor;
    window.closeEditor = closeEditor;

})(jQuery);
