jQuery(document).ready(function ($) {

    // Auto-init existing forms
    $('.chat-form-container').each(function () {
        initChatForm($(this));
    });

    // Popup Trigger Logic
    $(document).on('click', '.chat-form-popup-trigger', function (e) {
        e.preventDefault();
        var formId = $(this).data('form-id');

        // Check if modal container exists, if not create it
        if ($('#chat-form-popup-modal').length === 0) {
            $('body').append(`
                <div id="chat-form-popup-modal">
                    <div class="chat-popup-backdrop"></div>
                    <div class="chat-popup-content">
                        <button class="chat-popup-close-btn">&times;</button>
                        <div id="popup-chat-container"></div>
                    </div>
                </div>
            `);
        }

        var $modal = $('#chat-form-popup-modal');
        var $container = $('#popup-chat-container');

        // Close logic
        $modal.find('.chat-popup-close-btn, .chat-popup-backdrop').off('click').on('click', function () {
            $modal.fadeOut(200);
            $container.empty(); // Clear content to reset state on next open
        });

        // Show modal
        $modal.fadeIn(200);

        // Inject container structure similar to shortcode output
        $container.html('<div class="chat-form-container" data-form-id="' + formId + '">' +
            '<div class="chat-messages"></div>' +
            '<div class="typing-indicator" style="display:none;"><span></span><span></span><span></span></div>' +
            '<div class="chat-input-area">' +
            '<input type="text" class="chat-input" placeholder="Type your answer...">' +
            '<button class="chat-submit-btn">Send</button>' +
            '</div>' +
            '</div>');

        // Re-initialize the chat script for the new container
        initChatForm($container.find('.chat-form-container'));
    });


    function initChatForm($container) {
        var formId = $container.data('form-id');
        var $messages = $container.find('.chat-messages');
        var $inputArea = $container.find('.chat-input-area');
        var questions = [];
        var formSettings = {};
        var currentQuestionIndex = 0;
        var answers = {};
        var questionHistory = []; // Track shown questions for back button

        // Load questions via AJAX
        $.ajax({
            url: chatFormsPublic.ajaxUrl,
            method: 'POST',
            data: {
                action: 'chat_forms_get_questions',
                form_id: formId,
                nonce: chatFormsPublic.nonce
            },
            success: function (response) {
                if (response.success) {
                    questions = response.data.questions || response.data;
                    formSettings = {
                        thankYouMessage: response.data.thank_you_message || '🎉 Thank you! Your response has been saved successfully.',
                        redirectUrl: response.data.redirect_url || ''
                    };

                    // Add progress bar and counter
                    // Initial count will be updated immediately
                    $container.prepend('<div class="chat-progress-wrapper">' +
                        '<div class="chat-question-counter">Question <span class="current">0</span> of <span class="total">0</span></div>' +
                        '<div class="chat-progress-bar"><div class="chat-progress-fill"></div></div>' +
                        '</div>');

                    setTimeout(function () {
                        moveToNextQuestion();
                    }, 500);
                }
            },
            error: function () {
                addMessage('⚠️ Failed to load form. Please refresh the page.', 'bot error');
            }
        });

        // Calculate reachable questions based on current answers
        function getReachableQuestionsCount() {
            var count = 0;
            // Iterate through all questions to simulate flow
            for (var i = 0; i < questions.length; i++) {
                if (shouldShowQuestion(questions[i], answers)) {
                    count++;
                }
            }
            return count;
        }

        // Calculate the current step number (1-based index in the reachable path)
        function getCurrentStepNumber() {
            // questionHistory contains the INDICES of questions shown so far.
            return questionHistory.length;
        }

        function updateProgress() {
            var currentStep = getCurrentStepNumber();
            var totalReachable = getReachableQuestionsCount();

            // Debugging
            console.log('Progress Update:', { step: currentStep, total: totalReachable, history: questionHistory });

            // If total reachable is less than current step (edge case with complex logic), clamp it
            if (totalReachable < currentStep) totalReachable = currentStep;

            var percentage = totalReachable > 0 ? (currentStep / totalReachable) * 100 : 0;
            // Cap percentage at 100
            if (percentage > 100) percentage = 100;

            $container.find('.current').text(currentStep);
            $container.find('.total').text(totalReachable);
            $container.find('.chat-progress-fill').css('width', percentage + '%');
        }

        function moveToNextQuestion() {
            // Find next question that should be shown
            while (currentQuestionIndex < questions.length) {
                var question = questions[currentQuestionIndex];

                if (shouldShowQuestion(question, answers)) {
                    // Before showing, push to history (so 'current' updates correctly)
                    // But wait, if we push now, `getCurrentStepNumber` will be history.length.
                    // Actually `askQuestion` is effectively "showing" it.
                    // Let's optimize: `updateProgress` uses `questionHistory.length + 1` which assumes the *next* question is the one being viewed.
                    // So we shouldn't push to history until we actually *answer* or *render* it?
                    // Previous logic pushed to history here.

                    questionHistory.push(currentQuestionIndex);
                    updateProgress();
                    askQuestion(question);
                    return;
                } else {
                    // Skip this question
                    currentQuestionIndex++;
                }
            }

            // All questions complete
            setTimeout(function () {
                submitForm(formId, answers);
            }, 500);
        }

        function goBack() {
            if (questionHistory.length <= 1) return; // Can't go back from first question

            // Remove current question from history
            questionHistory.pop();
            var previousIndex = questionHistory[questionHistory.length - 1];

            // Remove last user message, bot question, AND the previous question to avoid duplicates
            // We need to be careful: 
            // 1. Current Question (Bot) -> User Answer (pending/or already answered?)
            // If we are "at" a question, we haven't answered it yet.
            // So we remove the Bot's last message (Current Question).
            // AND we remove the User's last answer (Previous Answer).
            // AND we remove the Bot's previous message (Previous Question).

            // Actually, simply removing the last 2 interactions (Bot Prompt + User Answer) + Current Bot Prompt?
            // "goBack" is clicked *on the current input*.
            // So visible elements: [Old Q, Old A], [Current Q], [Input Area]
            // We want to remove [Current Q].
            // And remove [Old A].
            // And show [Old Q]. (Actually Old Q is already there).
            // We just need to re-activate the input for Old Q.

            // Current code removed LAST 3 messages.
            // 1. Current Bot Question
            // 2. Previous User Answer
            // 3. Previous Bot Question (Wait, why remove the previous bot question? To re-ask it with typing effect?)
            // Yes, "askQuestion" appends a new message. So we must remove the old instance of the question to avoid duplication.

            if ($messages.find('.chat-message').length >= 3) {
                $messages.find('.chat-message').slice(-3).remove();
            } else {
                $messages.empty();
            }

            // Reset to previous question
            currentQuestionIndex = previousIndex;
            questionHistory.pop(); // Remove again so moveToNextQuestion adds it back

            // Recalculate answers (remove the answer we just backtracked over)
            delete answers[previousIndex];

            // Update UI
            updateProgress(); // Will show (N-1) of Total
            moveToNextQuestion(); // Will find the previous question again and ask it
        }

        function shouldShowQuestion(question, currentAnswers) {
            // If no conditional logic, always show
            if (!question.conditional || !question.conditional.enabled) {
                return true;
            }

            var rules = question.conditional.rules || [];
            var logic = question.conditional.logic || 'all';

            if (rules.length === 0) {
                return true;
            }

            var results = [];
            for (var i = 0; i < rules.length; i++) {
                var rule = rules[i];
                var questionId = rule.question; // Index of the dependency question
                var operator = rule.operator;
                var value = rule.value;

                // Get the answer to the referenced question
                var answer = currentAnswers[questionId];

                // Logic: If the referenced question hasn't been answered yet (is undefined),
                // we treat it as NOT MATCHING.
                // However, for "future" prediction (getReachableQuestionsCount), we might be looking ahead.
                // If we are looking ahead, 'answer' might be undefined because we haven't reached it.
                // In that case, we can't know if it will be shown.
                // STANDARD PRACTICE: Assume 'false' until proven true for conditional logic dependent on future/unanswered questions?
                // Actually conditional logic usually depends on *past* questions.
                // So if it depends on Q1 and we are at Q1, we have an answer.
                // If it depends on Q3 and we are at Q1, answer is undefined.

                if (typeof answer === 'undefined') {
                    results.push(false);
                    continue;
                }

                // Evaluate based on operator
                var match = false;
                answer = String(answer).toLowerCase();
                value = String(value).toLowerCase();

                switch (operator) {
                    case 'equals':
                        match = answer === value;
                        break;
                    case 'not_equals':
                        match = answer !== value;
                        break;
                    case 'contains':
                        match = answer.indexOf(value) !== -1;
                        break;
                }

                results.push(match);
            }

            // Apply logic (all or any)
            if (logic === 'all') {
                return results.every(function (r) { return r === true; });
            } else {
                return results.some(function (r) { return r === true; });
            }
        }

        function askQuestion(question) {
            showTypingIndicator();

            setTimeout(function () {
                hideTypingIndicator();
                addMessage(question.text, 'bot');

                setTimeout(function () {
                    renderInput(question);
                }, 200);
            }, 800);
        }

        function showTypingIndicator() {
            var $indicator = $('<div class="chat-message bot typing-indicator"><span></span><span></span><span></span></div>');
            $messages.append($indicator);
            $messages.scrollTop($messages[0].scrollHeight);
        }

        function hideTypingIndicator() {
            $('.typing-indicator').fadeOut(200, function () {
                $(this).remove();
            });
        }

        function renderInput(question) {
            $inputArea.empty();

            if ((question.type === 'select' || question.type === 'multiple') && question.options && question.options.length > 0) {
                renderOptionButtons(question);
            } else if (question.type === 'file') {
                renderFileUpload(question);
            } else {
                renderTextInput(question);
            }

            // Add back button if not first question
            if (questionHistory.length > 1) {
                var $backBtn = $('<button type="button" class="chat-back-btn">← Back</button>');
                $backBtn.on('click', function () {
                    goBack();
                });
                $inputArea.append($backBtn);
            }

            $inputArea.hide().fadeIn(300);
        }

        function renderOptionButtons(question) {
            var $optionsContainer = $('<div class="chat-options-container"></div>');

            question.options.forEach(function (option, index) {
                var optLabel = typeof option === 'object' ? option.label : option;
                var optValue = typeof option === 'object' ? option.value : option;
                var optImage = typeof option === 'object' ? option.image : '';

                var $btn = $('<button class="chat-option-btn"></button>');

                if (optImage) {
                    $btn.append('<img src="' + optImage + '" alt="' + optLabel + '" class="option-image">');
                }
                $btn.append('<span class="option-label">' + optLabel + '</span>');

                $btn.css('animation-delay', (index * 0.1) + 's');

                if (answers[question.id] == optValue) {
                    $btn.addClass('selected');
                }

                $btn.on('click', function () {
                    $(this).addClass('selected');
                    // Disable all buttons to prevent double-click
                    $optionsContainer.find('.chat-option-btn').prop('disabled', true);
                    handleAnswer(optValue, optLabel);
                });

                $optionsContainer.append($btn);
            });

            $inputArea.append($optionsContainer);
        }

        function renderFileUpload(question) {
            var html = '<div class="chat-file-upload">' +
                '<input type="file" id="chat-file-input" class="chat-file-input" />' +
                '<label for="chat-file-input" class="chat-file-label">📎 Choose File</label>' +
                '<button type="button" class="chat-submit-btn">Upload</button>' +
                '</div>';
            $inputArea.html(html);

            $inputArea.find('.chat-submit-btn').on('click', function () {
                var fileName = $inputArea.find('.chat-file-input').val();
                if (fileName) {
                    var fileNameOnly = fileName.split('\\').pop();
                    handleAnswer(fileName, '📎 ' + fileNameOnly);
                }
            });

            // Update label on file selection
            $inputArea.find('.chat-file-input').on('change', function () {
                var fileName = $(this).val().split('\\').pop();
                if (fileName) {
                    $inputArea.find('.chat-file-label').text('📄 ' + fileName);
                } else {
                    $inputArea.find('.chat-file-label').text('📎 Choose File');
                }
            });
        }

        function renderTextInput(question) {
            var inputType = question.type === 'email' ? 'email' : (question.type === 'tel' ? 'tel' : 'text');
            var placeholder = question.type === 'email' ? 'your@email.com' : (question.type === 'tel' ? '(555) 123-4567' : 'Type your answer...');
            var value = answers[question.id] || '';

            var html = '<input type="' + inputType + '" class="chat-input" placeholder="' + placeholder + '" value="' + value + '" />' +
                '<button type="button" class="chat-submit-btn">Send</button>';
            $inputArea.html(html);

            var $input = $inputArea.find('.chat-input');
            var $btn = $inputArea.find('.chat-submit-btn');

            setTimeout(function () {
                $input.focus();
            }, 100);

            $btn.on('click', function () {
                submitTextAnswer($input, question);
            });

            $input.on('keypress', function (e) {
                if (e.which === 13) {
                    submitTextAnswer($input, question);
                }
            });
        }

        // Validation function
        function validateAnswer(question, answer) {
            var validation = question.validation || {};

            // Check required
            if (validation.required && (!answer || answer.trim() === '')) {
                return validation.error_message || 'This field is required.';
            }

            // Check min length
            if (validation.min_length && answer.length < parseInt(validation.min_length)) {
                return validation.error_message || 'Answer must be at least ' + validation.min_length + ' characters.';
            }

            // Check max length
            if (validation.max_length && answer.length > parseInt(validation.max_length)) {
                return validation.error_message || 'Answer must not exceed ' + validation.max_length + ' characters.';
            }

            // Email validation
            if (question.type === 'email' && answer) {
                var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailPattern.test(answer)) {
                    return validation.error_message || 'Please enter a valid email address.';
                }
            }

            return null; // No error
        }

        function submitTextAnswer($input, question) {
            var answer = $input.val().trim();

            // Validate answer
            var error = validateAnswer(question, answer);
            if (error) {
                // Show error with shake effect
                $input.addClass('shake');

                // Remove existing error message
                $inputArea.find('.validation-error').remove();

                // Add error message
                $inputArea.append('<div class="validation-error" style="color: #d63638; margin-top: 8px; font-size: 14px;">⚠️ ' + error + '</div>');

                setTimeout(function () {
                    $input.removeClass('shake');
                }, 500);
                return;
            }

            // Clear any existing errors
            $inputArea.find('.validation-error').remove();

            handleAnswer(answer, answer);
        }

        function handleAnswer(value, displayText) {
            addMessage(displayText, 'user');
            answers[currentQuestionIndex] = value;

            $inputArea.fadeOut(200);

            currentQuestionIndex++;
            setTimeout(function () {
                moveToNextQuestion();
            }, 800);
        }

        function addMessage(text, type) {
            var html = '<div class="chat-message ' + type + '">' + text + '</div>';
            $messages.append(html);
            $messages.scrollTop($messages[0].scrollHeight);
        }

        function submitForm(id, data) {
            $inputArea.empty();
            addMessage('✨ Submitting your response...', 'bot');

            var performSubmit = function (token) {
                var payload = {
                    action: 'chat_forms_submit_entry',
                    form_id: id,
                    answers: data,
                    nonce: chatFormsPublic.nonce
                };
                if (token) payload.recaptcha_token = token;

                $.ajax({
                    url: chatFormsPublic.ajaxUrl,
                    method: 'POST',
                    data: payload,
                    success: function (res) {
                        $('.chat-message:last').fadeOut(200, function () {
                            $(this).remove();

                            if (res.success) {
                                // Check for redirect URL first
                                if (formSettings.redirectUrl) {
                                    addMessage('✅ Success! Redirecting...', 'bot success');
                                    setTimeout(function () {
                                        window.location.href = formSettings.redirectUrl;
                                    }, 1500);
                                } else {
                                    // Show custom or default thank you message
                                    var message = formSettings.thankYouMessage || '🎉 Thank you! Your response has been saved successfully.';
                                    addMessage(message, 'bot success');

                                    setTimeout(function () {
                                        var $restartBtn = $('<button class="chat-submit-btn restart-btn">Start Over</button>');
                                        $restartBtn.on('click', function () {
                                            location.reload();
                                        });
                                        $inputArea.html($restartBtn).fadeIn(300);
                                    }, 1000);
                                }
                            } else {
                                addMessage('❌ Error: ' + (res.data || 'Something went wrong.'), 'bot error');
                            }
                        });
                    },
                    error: function () {
                        $('.chat-message:last').fadeOut(200, function () {
                            $(this).remove();
                            addMessage('❌ Oops! Something went wrong. Please try again.', 'bot error');
                        });
                    }
                });
            };

            if (typeof grecaptcha !== 'undefined' && chatFormsPublic.recaptchaSiteKey) {
                grecaptcha.ready(function () {
                    grecaptcha.execute(chatFormsPublic.recaptchaSiteKey, { action: 'submit' }).then(function (token) {
                        performSubmit(token);
                    });
                });
            } else {
                performSubmit(null);
            }
        }
    }

    // --- Basic Forms Submit Handler ---
    $(document).on('submit', '.chat-forms-basic-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var formId = $form.data('form-id');
        var $submitBtn = $form.find('.sn-submit-btn, button[type="submit"]');
        var $messageContainer = $form.find('.chat-form-response-message');

        if ($messageContainer.length === 0) {
            $form.append('<div class="chat-form-response-message" style="display:none; margin-top:15px; padding:15px; border-radius:4px;"></div>');
            $messageContainer = $form.find('.chat-form-response-message');
        }

        var originalBtnText = $submitBtn.text();
        $submitBtn.prop('disabled', true).text('Sending...');
        $messageContainer.hide().removeClass('success error').empty();

        // Gather answers mapped to their field index
        var answers = {};
        var formData = $form.serializeArray();

        // Form structure: name="chat_form_field_0", etc. Let's map it exactly how Chat Forms wants it (0, 1, 2)
        $.each(formData, function (i, field) {
            // Check if it's one of our generated fields
            if (field.name.indexOf('chat_form_field_') === 0) {
                var index = field.name.replace('chat_form_field_', '');
                answers[index] = field.value;
            } else if (field.name !== 'action' && field.name !== 'form_id' && field.name !== 'nonce') {
                // If it's a custom field from user HTML like name="project_type"
                // Store it under its string name so it's captured
                answers[field.name] = field.value;
            }
        });

        // The nonce logic fallback just in case the form didn't output one (e.g., custom HTML replaced it all without appending)
        var submitNonce = $form.find('input[name="nonce"]').val() || chatFormsPublic.nonce;

        var performBasicSubmit = function (token) {
            var submitData = {
                action: 'chat_forms_submit_entry',
                form_id: formId,
                answers: answers,
                nonce: submitNonce
            };
            if (token) submitData.recaptcha_token = token;

            $.ajax({
                url: chatFormsPublic.ajaxUrl,
                method: 'POST',
                data: submitData,
                success: function (res) {
                    if (res.success) {
                        $form[0].reset();
                        $messageContainer.addClass('success').css({
                            'background-color': 'rgba(46, 204, 113, 0.1)',
                            'border': '1px solid #2ecc71',
                            'color': '#2ecc71'
                        }).html('✅ ' + (res.data.message || 'Thank you! Your response has been saved.')).fadeIn();

                        if (res.data.redirect_url) {
                            setTimeout(function () {
                                window.location.href = res.data.redirect_url;
                            }, 1500);
                        }
                    } else {
                        $messageContainer.addClass('error').css({
                            'background-color': 'rgba(231, 76, 60, 0.1)',
                            'border': '1px solid #e74c3c',
                            'color': '#e74c3c'
                        }).html('❌ Error: ' + (res.data || 'Failed to submit form.')).fadeIn();
                    }
                },
                error: function () {
                    $messageContainer.addClass('error').css({
                        'background-color': 'rgba(231, 76, 60, 0.1)',
                        'border': '1px solid #e74c3c',
                        'color': '#e74c3c'
                    }).html('❌ Oops! Something went wrong. Please try again.').fadeIn();
                },
                complete: function () {
                    $submitBtn.prop('disabled', false).text(originalBtnText);
                }
            });
        };

        if (typeof grecaptcha !== 'undefined' && chatFormsPublic.recaptchaSiteKey) {
            grecaptcha.ready(function () {
                grecaptcha.execute(chatFormsPublic.recaptchaSiteKey, { action: 'submit' }).then(function (token) {
                    performBasicSubmit(token);
                });
            });
        } else {
            performBasicSubmit(null);
        }
    });

});
