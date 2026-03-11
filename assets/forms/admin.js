jQuery(document).ready(function ($) {

    // Preview Modal
    $('#preview-form-btn').on('click', function () {
        // Create modal if it doesn't exist
        if ($('#chat-form-preview-modal').length === 0) {
            $('body').append(`
                <div id="chat-form-preview-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 100000; overflow: auto;">
                    <div style="max-width: 600px; margin: 50px auto; position: relative;">
                        <button id="close-preview-modal" style="position: absolute; top: 10px; right: 10px; background: white; border: none; border-radius: 50%; width: 40px; height: 40px; cursor: pointer; font-size: 24px; z-index: 10;">×</button>
                        <div id="preview-container"></div>
                    </div>
                </div>
            `);

            $('#close-preview-modal, #chat-form-preview-modal').on('click', function (e) {
                if (e.target === this) {
                    $('#chat-form-preview-modal').fadeOut();
                }
            });
        }

        // Collect current form data
        var questions = [];
        $('.chat-form-question').each(function () {
            var $q = $(this);
            var questionData = {
                text: $q.find('.question-text').val(),
                type: $q.find('.question-type').val(),
                options: []
            };

            // Collect options for multiple choice
            if (questionData.type === 'multiple' || questionData.type === 'select') {
                $q.find('.option-item').each(function () {
                    var $opt = $(this);
                    var label = $opt.find('.option-label').val();
                    var value = $opt.find('.option-value').val();
                    var image = $opt.find('.option-image-url').val();

                    if (label || value) {
                        questionData.options.push({
                            label: label || value,
                            value: value || label,
                            image: image || ''
                        });
                    }
                });
            }

            questions.push(questionData);
        });

        // Generate preview HTML
        var previewHTML = `
            <div class="chat-form-container" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 40px 20px; border-radius: 12px;">
                <div class="chat-box" style="background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-radius: 12px; padding: 30px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); max-height: 500px; overflow-y: auto;">
                    <div class="chat-messages">
                        <h3 style="margin: 0 0 20px 0; color: #333; text-align: center;">Form Preview</h3>`;

        questions.forEach(function (q, index) {
            previewHTML += `
                <div class="chat-message bot" style="background: #f0f0f0; padding: 15px; border-radius: 12px; margin-bottom: 15px;">
                    <strong>Question ${index + 1}:</strong> ${q.text || 'Untitled Question'}
                    <div style="margin-top: 10px; font-size: 12px; color: #666;">Type: ${q.type}</div>`;

            if (q.options && q.options.length > 0) {
                previewHTML += '<div style="margin-top: 10px;">';
                q.options.forEach(function (opt) {
                    previewHTML += `<div style="background: white; padding: 8px; margin: 5px 0; border-radius: 6px; border: 2px solid #667eea;">`;
                    if (opt.image) {
                        previewHTML += `<img src="${opt.image}" style="max-width: 100px; height: auto; margin-right: 10px; border-radius: 4px; vertical-align: middle;">`;
                    }
                    previewHTML += `${opt.label}</div>`;
                });
                previewHTML += '</div>';
            }

            previewHTML += '</div>';
        });

        previewHTML += `
                    </div>
                </div>
            </div>`;

        $('#preview-container').html(previewHTML);
        $('#chat-form-preview-modal').fadeIn();
    });

    var questionTemplate = `
        <div class="chat-form-question">
            <h4>
                <span>Question <span class="question-number"></span></span>
                <button type="button" class="button remove-question">Remove</button>
            </h4>
            <p>
                <label>Question Text:</label>
                <input type="text" name="chat_form_questions[INDEX][text]" class="widefat question-text" />
            </p>
            <p>
                <label>Type:</label>
                <select name="chat_form_questions[INDEX][type]" class="question-type">
                    <option value="text">Text</option>
                    <option value="email">Email</option>
                    <option value="tel">Telephone</option>
                    <option value="multiple">Multiple Choice</option>
                    <option value="file">File Upload</option>
                </select>
            </p>
            <div class="options-manager" style="display:none;">
                <label>Options:</label>
                <div class="options-container"></div>
                <button type="button" class="button add-option-btn">+ Add Option</button>
            </div>
            <div class="conditional-logic-wrapper">
                <label>
                    <input type="checkbox" name="chat_form_questions[INDEX][conditional][enabled]" class="conditional-toggle" value="1" />
                    Enable Conditional Logic
                </label>
                <div class="conditional-rules" style="display:none; margin-top:10px; padding:10px; background:#fafafa; border-radius:4px;">
                    <p><small>Show this question only if:</small></p>
                    <select class="conditional-logic-select" style="margin-bottom:10px;">
                        <option value="all">All conditions match</option>
                        <option value="any">Any condition matches</option>
                    </select>
                    <div class="conditional-rules-list"></div>
                    <button type="button" class="button add-rule-btn">+ Add Rule</button>
                </div>
            </div>
        </div>
    `;

    var optionTemplate = `
        <div class="option-item">
            <input type="text" name="chat_form_questions[QINDEX][options][OINDEX][label]" placeholder="Label (e.g., Red)" class="option-label" />
            <input type="text" name="chat_form_questions[QINDEX][options][OINDEX][value]" placeholder="Value (e.g., red)" class="option-value" />
            <input type="hidden" name="chat_form_questions[QINDEX][options][OINDEX][image]" class="option-image-url" />
            <button type="button" class="button upload-image-btn">📷 Add Image</button>
            <button type="button" class="remove-option">Remove</button>
        </div>
    `;

    var ruleTemplate = `
        <div class="conditional-rule-item" style="display:flex; gap:5px; margin-bottom:5px; align-items:center;">
            <select class="rule-question-select" style="flex:1;">
                <option value="">Select question...</option>
            </select>
            <select class="rule-operator-select" style="flex:1;">
                <option value="equals">equals</option>
                <option value="not_equals">not equals</option>
                <option value="contains">contains</option>
            </select>
            <input type="text" class="rule-value-input" placeholder="Value" style="flex:1;" />
            <button type="button" class="button remove-rule">×</button>
        </div>
    `;

    // WP Media Library uploader
    var mediaUploader;

    $(document).on('click', '.upload-image-btn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $optionItem = $btn.closest('.option-item');
        var $imageInput = $optionItem.find('.option-image-url');

        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        mediaUploader = wp.media({
            title: 'Choose Option Image',
            button: {
                text: 'Use this image'
            },
            multiple: false
        });

        mediaUploader.on('select', function () {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            $imageInput.val(attachment.url);

            $btn.text('🖼️ Change');
            var $existingPreview = $optionItem.find('.option-image-preview');
            if ($existingPreview.length) {
                $existingPreview.attr('src', attachment.url);
            } else {
                $btn.after('<img src="' + attachment.url + '" class="option-image-preview" style="width:40px;height:40px;object-fit:cover;border-radius:4px;margin-left:5px;" />');
            }
        });

        mediaUploader.open();
    });

    // Conditional Logic Toggle
    $(document).on('change', '.conditional-toggle', function () {
        var $rules = $(this).closest('.conditional-logic-wrapper').find('.conditional-rules');
        if ($(this).is(':checked')) {
            $rules.slideDown(200);
            // Add initial rule if none exist
            if ($rules.find('.conditional-rule-item').length === 0) {
                addRuleToQuestion($(this).closest('.chat-form-question'));
            }
        } else {
            $rules.slideUp(200);
        }
    });

    // Add Rule
    $(document).on('click', '.add-rule-btn', function () {
        addRuleToQuestion($(this).closest('.chat-form-question'));
    });

    // Remove Rule
    $(document).on('click', '.remove-rule', function () {
        $(this).closest('.conditional-rule-item').remove();
    });

    function addRuleToQuestion($question) {
        var $rulesList = $question.find('.conditional-rules-list');
        var $newRule = $(ruleTemplate);

        $rulesList.append($newRule);
        updateQuestionNumbers();
        refreshRuleQuestionSelects($question); // Populate selects for this question
        updateRuleValueInput($newRule); // Initialize the new rule's value input
    }

    function refreshRuleQuestionSelects($question) {
        var qIndex = $('.chat-form-question').index($question);
        $question.find('.rule-question-select').each(function () {
            var $select = $(this);
            var currentVal = $select.val();
            $select.empty().append('<option value="">Select question...</option>');
            for (var i = 0; i < qIndex; i++) {
                var qText = $('.chat-form-question').eq(i).find('.question-text').val() || 'Question ' + (i + 1);
                $select.append('<option value="' + i + '">' + qText + '</option>');
            }
            $select.val(currentVal);
        });
    }

    function refreshAllRuleSelects() {
        $('.chat-form-question').each(function () {
            refreshRuleQuestionSelects($(this));
        });
    }

    // Dynamic Value Dropdown Logic
    $(document).on('change', '.rule-question-select', function () {
        updateRuleValueInput($(this).closest('.conditional-rule-item'));
    });

    function updateRuleValueInput($ruleItem) {
        var $questionSelect = $ruleItem.find('.rule-question-select');
        var targetQuestionIndex = $questionSelect.val();
        var $valueContainer = $ruleItem.find('.rule-value-input').parent(); // Or just replace the element itself
        // Note: ruleTemplate structure: select, select, input.rule-value-input, button

        // Find existing input/select
        var $existingInput = $ruleItem.find('.rule-value-input');
        var currentValue = $existingInput.val();
        var nameAttr = $existingInput.attr('name') || ''; // Preserve name if exists

        if (targetQuestionIndex === '') {
            // Revert to text input if no question selected
            replaceWithTextInput($existingInput, currentValue, nameAttr);
            return;
        }

        // Find the target question options
        var $targetQuestion = $('.chat-form-question').eq(targetQuestionIndex);
        var type = $targetQuestion.find('.question-type').val();

        if (type === 'multiple' || type === 'select') {
            // It has options, let's build a select
            var options = [];
            $targetQuestion.find('.option-item').each(function () {
                var val = $(this).find('.option-value').val();
                var label = $(this).find('.option-label').val();
                if (val || label) {
                    options.push({ value: val || label, label: label || val });
                }
            });

            if (options.length > 0) {
                var selectHtml = '<select class="rule-value-input" style="flex:1;">';
                selectHtml += '<option value="">Select value...</option>';
                options.forEach(function (opt) {
                    var selected = (currentValue === opt.value) ? 'selected' : '';
                    selectHtml += '<option value="' + opt.value + '" ' + selected + '>' + opt.label + '</option>';
                });
                selectHtml += '</select>';

                var $newSelect = $(selectHtml);
                if (nameAttr) $newSelect.attr('name', nameAttr);
                $existingInput.replaceWith($newSelect);

            } else {
                replaceWithTextInput($existingInput, currentValue, nameAttr);
            }
        } else {
            // Not a choice question, use text input
            replaceWithTextInput($existingInput, currentValue, nameAttr);
        }
    }

    function replaceWithTextInput($element, value, name) {
        if ($element.is('input[type="text"]')) return; // Already text input
        var $newInput = $('<input type="text" class="rule-value-input" placeholder="Value" style="flex:1;" />');
        $newInput.val(value);
        if (name) $newInput.attr('name', name);
        $element.replaceWith($newInput);
    }

    // Initializer for existing rules
    function initializeConditionalRules() {
        $('.conditional-rule-item').each(function () {
            updateRuleValueInput($(this));
        });
    }
    // Run initialization on load
    initializeConditionalRules();


    // Listen for changes that should refresh rules
    $(document).on('change', '.question-text, .option-label, .option-value, .question-type', function () {
        refreshAllRuleSelects();
        // Also refresh value dropdowns for matching rules
        $('.conditional-rule-item').each(function () {
            updateRuleValueInput($(this));
        });
    });

    $('#add-question').on('click', function () {
        var currentCount = $('.chat-form-question').length;
        var newQuestionHtml = questionTemplate.replace(/INDEX/g, currentCount);
        $('#chat-forms-questions-container').append(newQuestionHtml);
        updateQuestionNumbers();
    });

    // Remove question
    $(document).on('click', '.remove-question', function () {
        if ($('.chat-form-question').length > 1) {
            $(this).closest('.chat-form-question').remove();
            updateQuestionNumbers();
        } else {
            alert('You must have at least one question.');
        }
    });

    // Duplicate question
    $(document).on('click', '.duplicate-question', function () {
        var $question = $(this).closest('.chat-form-question');
        var $clone = $question.clone(true, true); // Clone with events and data

        // Clear conditional logic to avoid conflicts
        $clone.find('.conditional-toggle').prop('checked', false);
        $clone.find('.conditional-rules').hide();
        $clone.find('.conditional-rule-item').remove();

        // Insert clone after original
        $clone.insertAfter($question);

        // Update all indices
        updateQuestionNumbers();
    });

    $(document).on('change', '.question-type', function () {
        var $question = $(this).closest('.chat-form-question');
        var $optionsManager = $question.find('.options-manager');

        if ($(this).val() === 'select' || $(this).val() === 'multiple') {
            $optionsManager.show();
            if ($optionsManager.find('.option-item').length === 0) {
                addOptionToQuestion($question);
            }
        } else {
            $optionsManager.hide();
        }
    });

    $(document).on('click', '.add-option-btn', function () {
        var $question = $(this).closest('.chat-form-question');
        addOptionToQuestion($question);
    });

    $(document).on('click', '.remove-option', function () {
        $(this).closest('.option-item').remove();
    });

    function addOptionToQuestion($question) {
        var $container = $question.find('.options-container');
        var questionIndex = $question.index();
        var optionIndex = $container.find('.option-item').length;

        // Replace placeholders with actual indexes
        var optionHtml = optionTemplate
            .replace(/QINDEX/g, questionIndex)
            .replace(/OINDEX/g, optionIndex);

        $container.append(optionHtml);
    }

    function updateQuestionNumbers() {
        $('.chat-form-question').each(function (i) {
            var newIndex = i;
            $(this).find('.question-number').text(newIndex + 1);
            var $q = $(this);

            // Update all name attributes to reflect new index
            $(this).find('input, select, textarea').each(function () {
                var name = $(this).attr('name');
                if (name) {
                    // Start of generic replacement
                    // We need to be careful not to double-replace if we already did
                    // But names are typically 'chat_form_questions[X]...'

                    // Simple regex to swap the main question index
                    var updatedName = name.replace(/^chat_form_questions\[\d+\]/, 'chat_form_questions[' + newIndex + ']');

                    if (updatedName !== name) {
                        $(this).attr('name', updatedName);
                        name = updatedName; // Update local var for further checks
                    }

                    // For options, generic replace doesn't handle option index, which is fine as option index doesn't depend on question index movement
                    // UNLESS we are doing something fancy. But the OINDEX is local to the options array.
                    // The only thing is 'chat_form_questions[X][options][Y]'. If we change X, [Y] remains valid.

                    // However, we DO need to ensure consistency if options were resorted? 
                    // No, Sortable options is not implemented yet or separate.
                    // But question reordering IS.
                }
            });

            // Update conditional rule field names SPECIFICALLY to ensure they are correct
            // This is the safety net for dynamic rows
            $q.find('.conditional-rule-item').each(function (rIndex) {
                var $rule = $(this);
                // We use .prop('name', val) or .attr('name', val)
                $rule.find('.rule-question-select').attr('name', 'chat_form_questions[' + newIndex + '][conditional][rules][' + rIndex + '][question]');
                $rule.find('.rule-operator-select').attr('name', 'chat_form_questions[' + newIndex + '][conditional][rules][' + rIndex + '][operator]');
                $rule.find('.rule-value-input').attr('name', 'chat_form_questions[' + newIndex + '][conditional][rules][' + rIndex + '][value]');
            });

            // Update conditional logic select
            $q.find('.conditional-logic-select').attr('name', 'chat_form_questions[' + newIndex + '][conditional][logic]');
        });

        // After updating indices, refresh the selects that depend on indices
        refreshAllRuleSelects();
    }

    // Initialize options manager visibility on page load
    $('.question-type').each(function () {
        if ($(this).val() === 'select' || $(this).val() === 'multiple') {
            $(this).closest('.chat-form-question').find('.options-manager').show();
        }
    });



    // Initialize conditional logic visibility
    $('.conditional-toggle').each(function () {
        if ($(this).is(':checked')) {
            $(this).closest('.conditional-logic-wrapper').find('.conditional-rules').show();
        }
    });

    // Submission View Modal
    $(document).on('click', '.view-submission-btn', function (e) {
        e.preventDefault();
        var submissionId = $(this).data('id');
        var $modal = $('#chat-submission-modal');
        var $content = $modal.find('.chat-modal-data');
        var $loading = $modal.find('.chat-modal-loading');

        $modal.fadeIn(200);
        $loading.show();
        $content.empty();

        $.ajax({
            url: chatFormsAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'chat_forms_get_submission_details',
                submission_id: submissionId,
                nonce: chatFormsAjax.nonce
            },
            success: function (response) {
                $loading.hide();
                if (response.success) {
                    $content.html(response.data.content);
                } else {
                    $content.html('<p class="error">Error loading submission.</p>');
                }
            },
            error: function () {
                $loading.hide();
                $content.html('<p class="error">Connection error.</p>');
            }
        });
    });

    // Close Modal
    $(document).on('click', '.chat-modal-close, .chat-modal-backdrop', function () {
        $('#chat-submission-modal').fadeOut(200);
    });

    // ==========================================
    // EMAIL RULES LOGIC
    // ==========================================
    var emailRules = [];
    var $rulesInput = $('#chat_form_email_rules');
    var $rulesList = $('#chat-forms-email-rules-list tbody');

    // Initialize rules from hidden input
    if ($rulesInput.length > 0 && $rulesInput.val()) {
        try {
            emailRules = JSON.parse($rulesInput.val());
        } catch (e) {
            emailRules = [];
        }
        renderEmailRulesList();
    }

    function renderEmailRulesList() {
        $rulesList.find('tr:not(.no-rules-message)').remove();

        if (emailRules.length === 0) {
            $rulesList.find('.no-rules-message').show();
        } else {
            $rulesList.find('.no-rules-message').hide();

            emailRules.forEach(function (rule) {
                var row = `
                    <tr data-id="${rule.id}">
                        <td><strong>${rule.name || '(Untitled Rule)'}</strong></td>
                        <td>${rule.to || '-'}</td>
                        <td style="text-align: right;">
                            <button type="button" class="button edit-email-rule">Edit</button>
                            <button type="button" class="button delete-email-rule" style="color: #a00;">Delete</button>
                        </td>
                    </tr>
                `;
                $rulesList.append(row);
            });
        }

        // Update hidden input
        $rulesInput.val(JSON.stringify(emailRules));
    }



    // Open Modal (Add/Edit)
    function openEmailModal(ruleId) {
        console.log('openEmailModal called with ID:', ruleId);

        var rule = null;
        if (ruleId) {
            rule = emailRules.find(r => r.id === ruleId);
        }
        console.log('Rule found:', rule);

        // Reset fields
        $('#email_rule_id').val(rule ? rule.id : '');
        $('#email_rule_name').val(rule ? rule.name : '');
        $('#email_rule_to').val(rule ? rule.to : '');
        $('#email_rule_cc').val(rule ? rule.cc : '');
        $('#email_rule_bcc').val(rule ? rule.bcc : '');
        $('#email_rule_subject').val(rule ? rule.subject : '');
        $('#test-email-recipient').val(''); // Clear test email field

        var bodyContent = rule ? rule.body : '';
        console.log('Body content prepared');

        var $modal = $('#chat-forms-email-modal');
        if ($modal.length === 0) {
            console.error('Modal #chat-forms-email-modal NOT FOUND in DOM');
            alert('Error: Email modal HTML is missing from the page.');
            return;
        }
        console.log('Modal element found, fading in...');

        // Show modal first to ensure editor visibility
        $modal.fadeIn(200, function () {
            console.log('Modal fadeIn complete');
            try {
                // Set WP Editor Content after visible
                if (typeof tinymce !== 'undefined' && tinymce.get('chat_form_email_body_editor')) {
                    console.log('Setting TinyMCE content');
                    tinymce.get('chat_form_email_body_editor').setContent(bodyContent);
                    tinymce.execCommand('mceRepaint'); // Force repaint
                } else {
                    console.log('Setting textarea val (TinyMCE not active)');
                    $('#chat_form_email_body_editor').val(bodyContent);
                }
            } catch (err) {
                console.error('Error setting editor content:', err);
                $('#chat_form_email_body_editor').val(bodyContent);
            }

            // Populate Dynamic Variables
            populateDynamicVars();
        });
    }

    function populateDynamicVars() {
        var $list = $('#email-dynamic-vars .vars-list');
        $list.empty();

        // Standard Vars
        var standardVars = [
            { code: '{all_fields}', label: 'All Fields Table' },
            { code: '{user_email}', label: 'User Email' },
            { code: '{form_name}', label: 'Form Name' },
            { code: '{submission_id}', label: 'Submission ID' },
            { code: '{date}', label: 'Date' },
            { code: '{time}', label: 'Time' }
        ];

        standardVars.forEach(function (v) {
            $list.append(`<button type="button" class="button button-small insert-var-btn" data-code="${v.code}">${v.label}</button>`);
        });

        // Question Vars
        $('#chat-forms-questions-container .chat-form-question').each(function (index) {
            var qId = index + 1; // 1-based index for user friendliness
            var qLabel = $(this).find('input[name$="[text]"]').val() || 'Question ' + qId;
            if (qLabel.length > 20) qLabel = qLabel.substring(0, 20) + '...';

            $list.append(`<button type="button" class="button button-small insert-var-btn" data-code="{question_${qId}}">Q${qId}: ${qLabel}</button>`);
        });
    }

    // Event: Insert Variable
    $(document).on('click', '.insert-var-btn', function () {
        var code = $(this).data('code');
        if (typeof tinymce !== 'undefined' && tinymce.get('chat_form_email_body_editor') && !tinymce.get('chat_form_email_body_editor').isHidden()) {
            tinymce.get('chat_form_email_body_editor').execCommand('mceInsertContent', false, code);
        } else {
            // Textarea fallback
            var $textarea = $('#chat_form_email_body_editor');
            var caretPos = $textarea[0].selectionStart;
            var textAreaTxt = $textarea.val();
            $textarea.val(textAreaTxt.substring(0, caretPos) + code + textAreaTxt.substring(caretPos));
        }
    });

    // Event: Add Rule
    $(document).on('click', '#add-email-rule-btn', function (e) {
        e.preventDefault();
        console.log('Add Email Rule Clicked');
        openEmailModal(null);
    });

    // Event: Edit Rule
    $(document).on('click', '.edit-email-rule', function () {
        var id = $(this).closest('tr').data('id');
        openEmailModal(id);
    });

    // Event: Delete Rule
    $(document).on('click', '.delete-email-rule', function () {
        if (!confirm('Are you sure you want to delete this email rule?')) return;

        var id = $(this).closest('tr').data('id');
        emailRules = emailRules.filter(r => r.id !== id);
        renderEmailRulesList();
    });

    // Event: Save Rule
    $('#save-email-rule-btn').on('click', function () {
        var id = $('#email_rule_id').val();
        var name = $('#email_rule_name').val();

        // Get Editor Content
        var body = '';
        if (typeof tinymce !== 'undefined' && tinymce.get('chat_form_email_body_editor') && !tinymce.get('chat_form_email_body_editor').isHidden()) {
            body = tinymce.get('chat_form_email_body_editor').getContent();
        } else {
            body = $('#chat_form_email_body_editor').val();
        }

        var newRule = {
            id: id || 'rule_' + new Date().getTime(),
            name: name,
            to: $('#email_rule_to').val(),
            cc: $('#email_rule_cc').val(),
            bcc: $('#email_rule_bcc').val(),
            subject: $('#email_rule_subject').val(),
            body: body
        };

        if (id) {
            // Update existing
            var index = emailRules.findIndex(r => r.id === id);
            if (index !== -1) {
                emailRules[index] = newRule;
            }
        } else {
            // Add new
            emailRules.push(newRule);
        }

        renderEmailRulesList();
        $('#chat-forms-email-modal').fadeOut(200);
    });

    // Event: Send Test Email
    $('#send-test-email-btn').on('click', function () {
        var $btn = $(this);
        var originalText = $btn.text();
        var to = $('#test-email-recipient').val();

        if (!to) {
            alert('Please enter a recipient email address for testing.');
            return;
        }

        $btn.text('Sending...').prop('disabled', true);

        // Get Editor Content
        var body = '';
        if (typeof tinymce !== 'undefined' && tinymce.get('chat_form_email_body_editor') && !tinymce.get('chat_form_email_body_editor').isHidden()) {
            body = tinymce.get('chat_form_email_body_editor').getContent();
        } else {
            body = $('#chat_form_email_body_editor').val();
        }

        $.ajax({
            url: chatFormsAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'chat_forms_send_test_email',
                nonce: chatFormsAjax.nonce,
                to: to,
                subject: $('#email_rule_subject').val(),
                body: body
            },
            success: function (response) {
                if (response.success) {
                    alert('✅ Test email sent successfully!');
                } else {
                    alert('❌ Failed to send: ' + (response.data || 'Unknown error'));
                }
            },
            error: function () {
                alert('❌ Connection error');
            },
            complete: function () {
                $btn.text(originalText).prop('disabled', false);
            }
        });
    });

    // Close Modal Logic
    $(document).on('click', '.chat-forms-modal-close, .chat-forms-modal-backdrop', function () {
        $('#chat-forms-email-modal').fadeOut(200);
    });

    // Drag and drop sorting for questions
    $("#chat-forms-questions-container").sortable({
        handle: ".drag-handle",
        placeholder: "ui-state-highlight",
        stop: function (event, ui) {
            updateQuestionNumbers();
        }
    });

});
