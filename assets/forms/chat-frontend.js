jQuery(document).ready(function ($) {

    // Auto-init existing forms
    $('.chat-form-container').each(function () {
        initChatForm($(this));
    });

    // Expose so other scripts (widget launcher, admin preview) can mount chats
    window.chatFormsInitContainer = function ($el) { initChatForm($el); };
    $(document).on('chat_forms_init', function (e, $el) { initChatForm($el); });

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
        var isPreview = String($container.attr('data-preview-mode') || '') === '1';
        var botAvatar = $container.attr('data-bot-avatar') || '';

        function bootstrapFlow(qs, settings) {
            questions = qs || [];
            formSettings = $.extend({
                thankYouMessage: '🎉 Thank you! Your response has been saved successfully.',
                redirectUrl: ''
            }, settings || {});

            // Insert the LEO balance bar above the progress bar if the flow
            // contains any prompt_response question that bills the chat user.
            var needsChatUserToken = questions.some(function (q) {
                return q && q.type === 'prompt_response' && q.pays === 'chat_user';
            });
            if (needsChatUserToken) renderLeoBalanceBar();

            $container.prepend('<div class="chat-progress-wrapper">' +
                '<div class="chat-question-counter">Question <span class="current">0</span> of <span class="total">0</span></div>' +
                '<div class="chat-progress-bar"><div class="chat-progress-fill"></div></div>' +
                '</div>');

            setTimeout(function () { moveToNextQuestion(); }, 400);
        }

        /* ---------- LEO balance bar (top of chat, when chat_user pays) ---------- */
        function renderLeoBalanceBar() {
            if ($container.find('.em-leo-bar').length) return;
            var bar = '<div class="em-leo-bar" data-state="loading">' +
                '<div class="em-leo-bar__icon">💎</div>' +
                '<div class="em-leo-bar__body">' +
                  '<div class="em-leo-bar__label">LEO Balance</div>' +
                  '<div class="em-leo-bar__value">Checking…</div>' +
                '</div>' +
                '<div class="em-leo-bar__actions"></div>' +
                '</div>';
            $container.prepend(bar);
            refreshLeoBalanceBar();
        }

        function refreshLeoBalanceBar() {
            var $bar = $container.find('.em-leo-bar');
            if (!$bar.length) return;
            var statusUrl = '/wp-json/em/v1/oauth/status';
            var balanceUrl = '/wp-json/em/v1/oauth/balance';
            $.get(statusUrl).done(function (s) {
                if (!s.configured) {
                    $bar.attr('data-state', 'unconfigured');
                    $bar.find('.em-leo-bar__value').text('Not configured');
                    $bar.find('.em-leo-bar__actions').empty();
                    return;
                }
                if (!s.connected) {
                    $bar.attr('data-state', 'disconnected');
                    $bar.find('.em-leo-bar__value').text('Connect to use AI');
                    $bar.find('.em-leo-bar__actions').html(
                        '<button type="button" class="em-leo-bar__btn em-leo-bar__btn--primary" data-action="connect">🔗 Connect</button>'
                    );
                    return;
                }
                $.get(balanceUrl).done(function (b) {
                    $bar.attr('data-state', 'connected');
                    var bal = (typeof b.balance === 'number') ? b.balance.toFixed(2) : '—';
                    var label = b.token_label || 'Generators';
                    $bar.find('.em-leo-bar__value').html(bal + ' <small>' + label + '</small>');
                    var topupHref = b.topup_url || (s.hub_url + '/leo/tokens');
                    $bar.find('.em-leo-bar__actions').html(
                        '<a href="' + topupHref + '" target="_blank" rel="noopener" class="em-leo-bar__btn">💳 Top up</a>' +
                        '<button type="button" class="em-leo-bar__btn em-leo-bar__btn--ghost" data-action="disconnect">Disconnect</button>'
                    );
                });
            }).fail(function () {
                $bar.attr('data-state', 'error');
                $bar.find('.em-leo-bar__value').text('Status check failed');
            });
        }

        $container.on('click', '.em-leo-bar__btn[data-action="connect"]', function () {
            startChatLeoOAuth(refreshLeoBalanceBar);
        });
        $container.on('click', '.em-leo-bar__btn[data-action="disconnect"]', function () {
            $.post('/wp-json/em/v1/oauth/revoke').always(refreshLeoBalanceBar);
        });

        function startChatLeoOAuth(onDone) {
            $.get('/wp-json/em/v1/oauth/status').done(function (s) {
                if (!s.configured || !s.client_id) { alert('LEO is not configured for this site.'); return; }
                var hub = s.hub_url || 'https://gend.me';
                var redirectUri = 'https://gend.me/oauth-bridge/';
                var b64url = function (bytes) {
                    var x = ''; bytes.forEach(function (b) { x += String.fromCharCode(b); });
                    return btoa(x).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
                };
                var stB = new Uint8Array(32); crypto.getRandomValues(stB);
                var vB  = new Uint8Array(32); crypto.getRandomValues(vB);
                var state = b64url(stB);
                var verifier = b64url(vB);
                crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier)).then(function (digest) {
                    var challenge = b64url(new Uint8Array(digest));
                    try { sessionStorage.setItem('em_leo_oauth_attempt', JSON.stringify({ state: state, codeVerifier: verifier, ts: Date.now() })); }
                    catch (_) { alert('Session storage unavailable.'); return; }
                    var params = new URLSearchParams({
                        client_id: s.client_id,
                        response_type: 'code',
                        redirect_uri: redirectUri,
                        state: state,
                        code_challenge: challenge,
                        code_challenge_method: 'S256'
                    });
                    var win = window.open(hub + '/oauth/authorize?' + params.toString(), 'GenDLogin', 'width=600,height=700');
                    var hubOrigin = new URL(hub).origin;
                    var onMsg = function (ev) {
                        if (ev.origin !== hubOrigin) return;
                        var d = ev.data || {};
                        if (d.type !== 'gdc-auth' || !d.code) return;
                        window.removeEventListener('message', onMsg);
                        if (win) try { win.close(); } catch(_) {}
                        var attempt = {};
                        try { attempt = JSON.parse(sessionStorage.getItem('em_leo_oauth_attempt') || '{}'); } catch(_){}
                        sessionStorage.removeItem('em_leo_oauth_attempt');
                        if (!attempt.state || attempt.state !== d.state) { alert('Login aborted.'); return; }
                        $.ajax({
                            url: '/wp-json/em/v1/oauth/exchange',
                            method: 'POST',
                            contentType: 'application/json',
                            data: JSON.stringify({ code: d.code, redirect_uri: redirectUri, code_verifier: attempt.codeVerifier, state: d.state })
                        }).done(function () { if (onDone) onDone(); });
                    };
                    window.addEventListener('message', onMsg);
                });
            });
        }

        // Inline bootstrap takes precedence (used by preview + widget)
        var inlineBootstrap = $container.attr('data-bootstrap');
        if (inlineBootstrap) {
            try {
                var parsed = JSON.parse(inlineBootstrap);
                bootstrapFlow(parsed.questions || [], {
                    thankYouMessage: parsed.thankYou || '🎉 Thank you! Your response has been saved successfully.',
                    redirectUrl: ''
                });
            } catch (e) {
                addMessage('⚠️ Failed to load preview.', 'bot error');
            }
        } else {
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
                        bootstrapFlow(response.data.questions || response.data, {
                            thankYouMessage: response.data.thank_you_message || '🎉 Thank you! Your response has been saved successfully.',
                            redirectUrl: response.data.redirect_url || ''
                        });
                    }
                },
                error: function () {
                    addMessage('⚠️ Failed to load form. Please refresh the page.', 'bot error');
                }
            });
        }

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
            // Info Block bypasses the typing-indicator + question-bubble flow:
            // it renders its content as a full-width raw block (no avatar, no bubble).
            if (question.type === 'info_block') {
                renderInput(question);
                return;
            }

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

            if (question.type === 'info_block') {
                renderInfoBlock(question);
                return;
            } else if (question.type === 'prompt_response') {
                renderPromptResponse(question);
                return;
            } else if ((question.type === 'select' || question.type === 'multiple') && question.options && question.options.length > 0) {
                renderOptionButtons(question);
            } else if (question.type === 'file') {
                renderFileUpload(question);
            } else if (question.type === 'account_registration') {
                renderAccountRegistration(question);
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
                    var branchResponse = (typeof option === 'object' && option.response_html) ? option.response_html : '';
                    handleAnswer(optValue, optLabel, branchResponse);
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

        function renderAccountRegistration(question) {
            var isLoggedIn = chatFormsPublic.isLoggedIn === true || chatFormsPublic.isLoggedIn === 'true' || chatFormsPublic.isLoggedIn == '1';
            var currentUser = chatFormsPublic.currentUser || {};

            if (isLoggedIn) {
                var displayName = currentUser.display_name || currentUser.username || 'Unknown';
                $inputArea.html(
                    '<div class="chat-account-widget">' +
                        '<div class="account-logged-in">' +
                            '<span class="account-avatar">👤</span>' +
                            '<p>You are logged in as <strong>' + displayName + '</strong></p>' +
                            '<button type="button" class="chat-submit-btn account-continue-btn">Continue</button>' +
                        '</div>' +
                    '</div>'
                );

                $inputArea.find('.account-continue-btn').on('click', function () {
                    handleAnswer(
                        JSON.stringify({ action: 'logged_in', user_id: currentUser.user_id, username: currentUser.username }),
                        'Continuing as ' + displayName
                    );
                });

            } else {
                $inputArea.html(
                    '<div class="chat-account-widget">' +
                        '<div class="account-tabs">' +
                            '<button type="button" class="account-tab active" data-tab="login">Log In</button>' +
                            '<button type="button" class="account-tab" data-tab="register">New Account</button>' +
                        '</div>' +
                        '<div class="account-tab-content login-tab">' +
                            '<input type="text" class="chat-input account-username" placeholder="Username or Email" />' +
                            '<input type="password" class="chat-input account-password" placeholder="Password" />' +
                            '<div class="account-msg" style="display:none;"></div>' +
                            '<button type="button" class="chat-submit-btn account-login-btn">Log In</button>' +
                        '</div>' +
                        '<div class="account-tab-content register-tab" style="display:none;">' +
                            '<input type="text" class="chat-input account-reg-username" placeholder="Username" />' +
                            '<input type="email" class="chat-input account-reg-email" placeholder="Email Address" />' +
                            '<input type="password" class="chat-input account-reg-password" placeholder="Password (min 8 chars)" />' +
                            '<input type="password" class="chat-input account-reg-confirm" placeholder="Confirm Password" />' +
                            '<div class="account-msg" style="display:none;"></div>' +
                            '<button type="button" class="chat-submit-btn account-register-btn">Register & Continue</button>' +
                        '</div>' +
                    '</div>'
                );

                // Tab switching
                $inputArea.find('.account-tab').on('click', function () {
                    $inputArea.find('.account-tab').removeClass('active');
                    $(this).addClass('active');
                    var tab = $(this).data('tab');
                    $inputArea.find('.account-tab-content').hide();
                    $inputArea.find('.' + tab + '-tab').show();
                });

                // Login button
                $inputArea.find('.account-login-btn').on('click', function () {
                    var $btn = $(this);
                    var username = $inputArea.find('.account-username').val().trim();
                    var password = $inputArea.find('.account-password').val();
                    var $msg = $inputArea.find('.login-tab .account-msg');

                    if (!username || !password) {
                        $msg.removeClass('success').addClass('error').text('Please enter your username and password.').show();
                        return;
                    }

                    $btn.prop('disabled', true).text('Logging in…');
                    $msg.hide();

                    $.ajax({
                        url: chatFormsPublic.ajaxUrl,
                        method: 'POST',
                        data: {
                            action: 'chat_forms_account_login',
                            username: username,
                            password: password,
                            nonce: chatFormsPublic.nonce
                        },
                        success: function (res) {
                            if (res.success) {
                                handleAnswer(
                                    JSON.stringify({ action: 'logged_in', user_id: res.data.user_id, username: res.data.username }),
                                    'Logged in as ' + (res.data.display_name || res.data.username)
                                );
                            } else {
                                $msg.removeClass('success').addClass('error').text(res.data || 'Login failed. Please check your credentials.').show();
                                $btn.prop('disabled', false).text('Log In');
                            }
                        },
                        error: function () {
                            $msg.removeClass('success').addClass('error').text('Connection error. Please try again.').show();
                            $btn.prop('disabled', false).text('Log In');
                        }
                    });
                });

                // Register button — collect data and continue; account created on form submission
                $inputArea.find('.account-register-btn').on('click', function () {
                    var username = $inputArea.find('.account-reg-username').val().trim();
                    var email    = $inputArea.find('.account-reg-email').val().trim();
                    var password = $inputArea.find('.account-reg-password').val();
                    var confirm  = $inputArea.find('.account-reg-confirm').val();
                    var $msg     = $inputArea.find('.register-tab .account-msg');

                    if (!username || !email || !password) {
                        $msg.removeClass('success').addClass('error').text('Please fill in all fields.').show();
                        return;
                    }
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                        $msg.removeClass('success').addClass('error').text('Please enter a valid email address.').show();
                        return;
                    }
                    if (password.length < 8) {
                        $msg.removeClass('success').addClass('error').text('Password must be at least 8 characters.').show();
                        return;
                    }
                    if (password !== confirm) {
                        $msg.removeClass('success').addClass('error').text('Passwords do not match.').show();
                        return;
                    }

                    handleAnswer(
                        JSON.stringify({ action: 'register', username: username, email: email, password: password }),
                        'Registering as ' + username
                    );
                });
            }
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

        function handleAnswer(value, displayText, branchResponseHtml) {
            addMessage(displayText, 'user');
            answers[currentQuestionIndex] = value;

            $inputArea.fadeOut(200);

            // Branch response from a multiple-choice option
            if (branchResponseHtml && String(branchResponseHtml).trim() !== '') {
                showTypingIndicator();
                setTimeout(function () {
                    hideTypingIndicator();
                    addMessage(branchResponseHtml, 'bot info');
                    currentQuestionIndex++;
                    setTimeout(function () { moveToNextQuestion(); }, 700);
                }, 600);
                return;
            }

            currentQuestionIndex++;
            setTimeout(function () {
                moveToNextQuestion();
            }, 800);
        }

        function buildAvatarHtml(role) {
            if (role === 'user') {
                var url = (window.chatFormsPublic && chatFormsPublic.currentUser && chatFormsPublic.currentUser.avatar_url) || '';
                if (url) return '<div class="chat-avatar"><img src="' + url + '" alt="" /></div>';
                var name = (chatFormsPublic && chatFormsPublic.currentUser && chatFormsPublic.currentUser.display_name) || 'You';
                return '<div class="chat-avatar">' + (name.charAt(0) || 'U').toUpperCase() + '</div>';
            }
            // bot
            if (botAvatar) return '<div class="chat-avatar"><img src="' + botAvatar + '" alt="" /></div>';
            return '<div class="chat-avatar">🤖</div>';
        }

        function addMessage(text, type) {
            // Original behavior preserved for non-bubble messages (errors, status); upgrade
            // bot/user messages to avatar+bubble layout used by the widget design.
            var classes = String(type || '').split(/\s+/);
            var role = classes.indexOf('user') !== -1 ? 'user' : 'bot';

            // Raw mode: full-width content, no avatar, no bubble container.
            // Used by Info Block question type so admins can lay out custom HTML.
            if (classes.indexOf('raw') !== -1) {
                var rawHtml = '<div class="chat-message chat-message--raw ' + (type || '') + '">' + text + '</div>';
                $messages.append(rawHtml);
                $messages.scrollTop($messages[0].scrollHeight);
                return;
            }

            var modifier = '';
            if (classes.indexOf('info') !== -1) modifier = ' chat-bubble--info';
            else if (classes.indexOf('ai')   !== -1) modifier = ' chat-bubble--ai';
            else if (classes.indexOf('error') !== -1) modifier = ' chat-bubble--error';
            else if (classes.indexOf('success') !== -1) modifier = ' chat-bubble--success';

            var html = '<div class="chat-message chat-message--' + role + ' ' + (type || '') + '">' +
                buildAvatarHtml(role) +
                '<div class="chat-bubble chat-bubble--' + role + modifier + '">' + text + '</div>' +
                '</div>';
            $messages.append(html);
            $messages.scrollTop($messages[0].scrollHeight);
        }

        /* ---------- New question types ---------- */
        function renderInfoBlock(question) {
            // Render full-width raw content — no avatar, no bubble, fills the chat area.
            var content = question.content || question.text || '';
            if (content) addMessage(content, 'bot raw');
            // Auto-advance — info block has no input
            currentQuestionIndex++;
            setTimeout(function () { moveToNextQuestion(); }, 800);
        }

        function renderPromptResponse(question) {
            // In preview mode, simulate without calling LEO
            if (isPreview) {
                addMessage('🤖 (preview) AI response would appear here based on prompt:\n\n' + (question.prompt || ''), 'bot ai');
                currentQuestionIndex++;
                setTimeout(function () { moveToNextQuestion(); }, 800);
                return;
            }
            showTypingIndicator();
            // Build context from prior answers
            var answerList = [];
            for (var k in answers) {
                if (Object.prototype.hasOwnProperty.call(answers, k)) {
                    var qIdx = parseInt(k, 10);
                    var qObj = questions[qIdx] || {};
                    answerList.push({ question: qObj.text || ('Question ' + (qIdx + 1)), answer: answers[k] });
                }
            }
            $.ajax({
                url: chatFormsPublic.ajaxUrl,
                method: 'POST',
                timeout: 35000,
                data: {
                    action: 'em_leo_complete',
                    nonce: chatFormsPublic.nonce,
                    form_id: formId,
                    prompt_template: question.prompt || '',
                    answers: answerList,
                    pays: question.pays || 'site',
                    pays_user_id: question.pays_user_id || 0
                }
            }).done(function (res) {
                hideTypingIndicator();
                if (res && res.success && res.data && res.data.text) {
                    addMessage(res.data.text, 'bot ai');
                    answers[currentQuestionIndex] = { ai_response: res.data.text };
                } else {
                    var msg = (res && res.data && res.data.message) || 'AI response unavailable.';
                    addMessage('⚠️ ' + msg, 'bot error');
                }
                currentQuestionIndex++;
                setTimeout(function () { moveToNextQuestion(); }, 800);
            }).fail(function (xhr) {
                hideTypingIndicator();
                var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'AI request failed.';
                addMessage('⚠️ ' + msg, 'bot error');
                currentQuestionIndex++;
                setTimeout(function () { moveToNextQuestion(); }, 800);
            });
        }

        function submitForm(id, data) {
            $inputArea.empty();

            // Preview mode: never persist
            if (isPreview) {
                addMessage('✅ Preview complete — nothing was saved.', 'bot success');
                setTimeout(function () {
                    var $restartBtn = $('<button class="chat-submit-btn restart-btn">Run Again</button>');
                    $restartBtn.on('click', function () {
                        $messages.empty();
                        questionHistory = [];
                        answers = {};
                        currentQuestionIndex = 0;
                        $inputArea.empty();
                        moveToNextQuestion();
                    });
                    $inputArea.html($restartBtn).fadeIn(300);
                }, 600);
                return;
            }

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
