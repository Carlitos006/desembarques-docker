(function ($) {
    'use strict';

    var appConfig = window.AppConfig || {};
    var translations = appConfig.translations || {};
    var csrfToken = typeof appConfig.csrfToken === 'string' ? appConfig.csrfToken : '';
    var apiTokenScopes = Array.isArray(appConfig.apiTokenScopes) ? appConfig.apiTokenScopes : [];

    function translate(key, fallback) {
        if (Object.prototype.hasOwnProperty.call(translations, key)) {
            return translations[key];
        }

        return fallback;
    }

    function showAlert($alert, type, message) {
        if (! $alert.length) {
            return;
        }

        if (! type) {
            $alert.removeClass('alert-success alert-danger alert-warning alert-info alert-primary').addClass('d-none');
            $alert.text('');

            return;
        }

        $alert
            .removeClass('d-none alert-success alert-danger alert-warning alert-info alert-primary')
            .addClass('alert-' + type)
            .text(message || '');
    }

    function updateDisplayedName(name) {
        var displayName = '';

        if (typeof name === 'string' && name.trim() !== '') {
            displayName = name.trim();
        } else {
            displayName = translate('dashboard.nav.profile', 'Perfil');
        }

        $('[data-profile-name-display]').text(displayName);
    }

    function updateCsrfToken(newToken) {
        if (typeof newToken !== 'string' || newToken.trim() === '') {
            return;
        }

        csrfToken = newToken;
        window.AppConfig = window.AppConfig || {};
        window.AppConfig.csrfToken = newToken;

        $('input[name="csrf_token"]').val(newToken);
    }

    function buildScopeId(scopeKey) {
        return 'api-scope-' + scopeKey.replace(/[^a-zA-Z0-9_-]/g, '-');
    }

    function renderTokenScopes(scopes) {
        var $scopesContainer = $('#api-token-scopes');

        if (! $scopesContainer.length) {
            return;
        }

        $scopesContainer.empty();

        if (! Array.isArray(scopes) || scopes.length === 0) {
            $scopesContainer.append(
                $('<div/>', { 'class': 'col-12 text-muted small' })
                    .text(translations['profile.tokens.form.no_scopes'] || 'No scopes configured.')
            );

            return;
        }

        scopes.forEach(function (scope) {
            var key = typeof scope.key === 'string' ? scope.key : '';
            var label = typeof scope.label === 'string' ? scope.label : key;
            var description = typeof scope.description === 'string' ? scope.description : '';
            var scopeId = buildScopeId(key);

            var $col = $('<div/>', { 'class': 'col-12 col-md-6' });
            var $wrapper = $('<div/>', { 'class': 'form-check border rounded p-2 h-100' });
            var $input = $('<input/>', {
                type: 'checkbox',
                'class': 'form-check-input',
                id: scopeId,
                name: 'scopes[]',
                value: key
            });
            var $label = $('<label/>', { 'class': 'form-check-label', 'for': scopeId });
            var $labelText = $('<span/>', { 'class': 'fw-semibold d-block' }).text(label);
            $label.append($labelText);

            if (description !== '') {
                $label.append($('<small/>', { 'class': 'text-muted d-block' }).text(description));
            }

            $wrapper.append($input).append($label);
            $col.append($wrapper);
            $scopesContainer.append($col);
        });
    }

    function formatTimestamp(value) {
        if (typeof value !== 'string' || value.trim() === '') {
            return translations['profile.tokens.last_used.never'] || '—';
        }

        var dateString = value.replace(' ', 'T');
        var date = new Date(dateString);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        try {
            return date.toLocaleString();
        } catch (error) {
            return value;
        }
    }

    function renderTokenTable(tokens) {
        var $table = $('#api-token-table');
        var $body = $table.find('tbody');
        var $emptyRow = $('#api-token-empty');

        if (! $table.length) {
            return;
        }

        $body.find('tr').not($emptyRow).remove();

        if (! Array.isArray(tokens) || tokens.length === 0) {
            $emptyRow.removeClass('d-none');
            return;
        }

        $emptyRow.addClass('d-none');

        tokens.forEach(function (token) {
            var tokenId = token && typeof token.id === 'number' ? token.id : null;
            var prefix = token && typeof token.token_prefix === 'string' ? token.token_prefix : '';
            var name = token && typeof token.name === 'string' && token.name.trim() !== '' ? token.name.trim() : '';
            var scopes = Array.isArray(token.scopes) ? token.scopes : [];
            var createdAt = token && typeof token.created_at === 'string' ? token.created_at : '';
            var lastUsed = token && typeof token.last_used === 'string' ? token.last_used : '';

            var $row = $('<tr/>');
            var $tokenCell = $('<td/>');
            var preview = prefix !== '' ? prefix + '…' : '—';
            $tokenCell.append($('<span/>', { 'class': 'fw-semibold' }).text(preview));

            if (name !== '') {
                $tokenCell.append($('<div/>', { 'class': 'small text-muted' }).text(name));
            }

            var $scopeCell = $('<td/>');
            if (scopes.length === 0) {
                $scopeCell.text('—');
            } else {
                $scopeCell.append($('<span/>').text(scopes.join(', ')));
            }

            var $createdCell = $('<td/>').text(createdAt !== '' ? createdAt : '—');
            var $lastUsedCell = $('<td/>').text(lastUsed !== '' ? formatTimestamp(lastUsed) : (translations['profile.tokens.last_used.never'] || '—'));

            var $actionsCell = $('<td/>', { 'class': 'text-end' });

            if (tokenId !== null) {
                var revokeLabel = translations['profile.tokens.action.revoke'] || 'Revoke';
                var $revokeButton = $('<button/>', {
                    type: 'button',
                    'class': 'btn btn-sm btn-outline-danger',
                    'data-api-token-revoke': tokenId
                }).text(revokeLabel);

                $actionsCell.append($revokeButton);
            }

            $row.append($tokenCell, $scopeCell, $createdCell, $lastUsedCell, $actionsCell);
            $body.append($row);
        });
    }

    function fetchTokens() {
        var $section = $('#api-token-section');

        if (! $section.length) {
            return;
        }

        $.ajax({
            url: '../api/auth/tokens/list.php',
            method: 'GET',
            dataType: 'json'
        }).done(function (response) {
            if (response && Array.isArray(response.tokens)) {
                renderTokenTable(response.tokens);
            }

            if (response && Array.isArray(response.available_scopes)) {
                apiTokenScopes = response.available_scopes;
                renderTokenScopes(apiTokenScopes);
            }

            if (response && typeof response.csrf_token === 'string') {
                updateCsrfToken(response.csrf_token);
            }
        }).fail(function () {
            renderTokenTable([]);
        });
    }

    $(function () {
        var $form = $('#profile-form');
        var $alert = $('#profile-alert');
        var $nameField = $('#profile-name');
        var $emailField = $('#profile-email');
        var $passwordField = $('#profile-password');
        var $passwordConfirmationField = $('#profile-password-confirmation');

        if (! $form.length) {
            return;
        }

        $form.on('submit', function (event) {
            event.preventDefault();

            var $submitButton = $form.find('button[type="submit"]');
            showAlert($alert, '', '');
            $submitButton.prop('disabled', true);

            $.ajax({
                url: '../api/auth/update_profile.php',
                method: 'POST',
                data: $form.serialize(),
                dataType: 'json'
            })
                .done(function (response) {
                    if (response && response.success) {
                        var successMessage = translate('profile.alert.success', 'Tu perfil se actualizó correctamente.');

                        if (response.message) {
                            successMessage = response.message;
                        }

                        showAlert($alert, 'success', successMessage);

                        if ($passwordField.length) {
                            $passwordField.val('');
                        }

                        if ($passwordConfirmationField.length) {
                            $passwordConfirmationField.val('');
                        }

                        if (response.user && typeof response.user === 'object') {
                            var updatedName = response.user.name !== undefined && response.user.name !== null
                                ? String(response.user.name)
                                : '';
                            var updatedEmail = response.user.email !== undefined && response.user.email !== null
                                ? String(response.user.email)
                                : '';

                            if ($nameField.length && updatedName !== '') {
                                $nameField.val(updatedName);
                            }

                            if ($emailField.length && updatedEmail !== '') {
                                $emailField.val(updatedEmail);
                            }

                            updateDisplayedName(updatedName);
                        }

                        return;
                    }

                    var warningMessage = translate('profile.alert.validation', 'No fue posible actualizar tu perfil con la información proporcionada.');

                    if (response && response.message) {
                        warningMessage = response.message;
                    }

                    showAlert($alert, 'warning', warningMessage);
                })
                .fail(function (jqXHR) {
                    var status = jqXHR.status;
                    var alertType = 'danger';
                    var message = translate('profile.alert.error', 'Ocurrió un error al actualizar tu perfil.');

                    if (status === 401) {
                        message = jqXHR.responseJSON && jqXHR.responseJSON.message
                            ? jqXHR.responseJSON.message
                            : translate('common.session_expired', 'Tu sesión ha expirado. Por favor, inicia sesión nuevamente.');
                        alertType = 'warning';
                        showAlert($alert, alertType, message);

                        setTimeout(function () {
                            window.location.href = 'login.php?status=expired';
                        }, 1500);

                        return;
                    }

                    if (status === 422 || status === 409) {
                        message = jqXHR.responseJSON && jqXHR.responseJSON.message
                            ? jqXHR.responseJSON.message
                            : translate('profile.alert.validation', 'No fue posible actualizar tu perfil con la información proporcionada.');
                        alertType = 'warning';
                    } else if (status === 419) {
                        message = jqXHR.responseJSON && jqXHR.responseJSON.message
                            ? jqXHR.responseJSON.message
                            : translate('common.csrf_token_invalid', 'La seguridad del formulario expiró. Actualiza la página e inténtalo de nuevo.');
                        alertType = 'warning';
                    } else if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                        message = jqXHR.responseJSON.message;
                    }

                    showAlert($alert, alertType, message);
                })
                .always(function () {
                    $submitButton.prop('disabled', false);
                });
        });

        renderTokenScopes(apiTokenScopes);
        fetchTokens();

        var $tokenForm = $('#api-token-form');
        var $tokenAlert = $('#api-token-alert');
        var $tokenCreated = $('#api-token-created');
        var $tokenCreatedValue = $('#api-token-created-value');

        if ($tokenForm.length) {
            $tokenForm.on('submit', function (event) {
                event.preventDefault();

                if (! csrfToken) {
                    csrfToken = $('input[name="csrf_token"]').first().val() || '';
                }

                var formData = $tokenForm.serializeArray();
                formData.push({ name: 'csrf_token', value: csrfToken });

                $tokenForm.find('button[type="submit"]').prop('disabled', true);
                showAlert($tokenAlert, '', '');

                $.ajax({
                    url: '../api/auth/tokens/create.php',
                    method: 'POST',
                    data: formData,
                    dataType: 'json'
                }).done(function (response) {
                    if (response && response.success) {
                        var successMessage = response.message || translations['profile.tokens.alert.created'] || 'Token created successfully.';
                        showAlert($tokenAlert, 'success', successMessage);

                        if (response.token) {
                            $tokenCreatedValue.text(response.token);
                            $tokenCreated.removeClass('d-none');
                        }

                        $tokenForm.trigger('reset');
                        fetchTokens();
                    } else {
                        var warningMessage = response && response.message
                            ? response.message
                            : translations['profile.tokens.alert.validation'] || 'Unable to create the token.';
                        showAlert($tokenAlert, 'warning', warningMessage);
                    }

                    if (response && typeof response.csrf_token === 'string') {
                        updateCsrfToken(response.csrf_token);
                    }
                }).fail(function (jqXHR) {
                    var status = jqXHR.status;
                    var message = translations['profile.tokens.alert.error'] || 'An error occurred while creating the token.';
                    var alertType = 'danger';

                    if (status === 422 || status === 409) {
                        message = jqXHR.responseJSON && jqXHR.responseJSON.message
                            ? jqXHR.responseJSON.message
                            : translations['profile.tokens.alert.validation'] || 'Unable to create the token with the provided data.';
                        alertType = 'warning';
                    } else if (status === 419) {
                        message = jqXHR.responseJSON && jqXHR.responseJSON.message
                            ? jqXHR.responseJSON.message
                            : translations['common.csrf_token_invalid'] || 'The security token is invalid.';
                        alertType = 'warning';
                    } else if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                        message = jqXHR.responseJSON.message;
                    }

                    showAlert($tokenAlert, alertType, message);

                    if (jqXHR.responseJSON && typeof jqXHR.responseJSON.csrf_token === 'string') {
                        updateCsrfToken(jqXHR.responseJSON.csrf_token);
                    }
                }).always(function () {
                    $tokenForm.find('button[type="submit"]').prop('disabled', false);
                });
            });
        }

        $(document).on('click', '[data-api-token-revoke]', function () {
            var tokenId = $(this).data('api-token-revoke');

            if (! tokenId) {
                return;
            }

            var confirmationMessage = translations['profile.tokens.confirm_revoke'] || 'Are you sure you want to revoke this token?';

            if (! window.confirm(confirmationMessage)) {
                return;
            }

            if (! csrfToken) {
                csrfToken = $('input[name="csrf_token"]').first().val() || '';
            }

            $.ajax({
                url: '../api/auth/tokens/revoke.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    token_id: tokenId,
                    csrf_token: csrfToken
                }
            }).done(function (response) {
                if (response && response.success) {
                    var successMessage = response.message || translations['profile.tokens.alert.revoked'] || 'Token revoked successfully.';
                    showAlert($tokenAlert, 'success', successMessage);
                    fetchTokens();
                } else {
                    var warningMessage = response && response.message
                        ? response.message
                        : translations['profile.tokens.alert.validation'] || 'Unable to revoke the token.';
                    showAlert($tokenAlert, 'warning', warningMessage);
                }

                if (response && typeof response.csrf_token === 'string') {
                    updateCsrfToken(response.csrf_token);
                }
            }).fail(function (jqXHR) {
                var message = translations['profile.tokens.alert.error'] || 'An error occurred while revoking the token.';
                var alertType = 'danger';

                if (jqXHR.status === 422 || jqXHR.status === 409) {
                    message = jqXHR.responseJSON && jqXHR.responseJSON.message
                        ? jqXHR.responseJSON.message
                        : translations['profile.tokens.alert.validation'] || 'Unable to revoke the token.';
                    alertType = 'warning';
                } else if (jqXHR.status === 419) {
                    message = jqXHR.responseJSON && jqXHR.responseJSON.message
                        ? jqXHR.responseJSON.message
                        : translations['common.csrf_token_invalid'] || 'The security token is invalid.';
                    alertType = 'warning';
                } else if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                    message = jqXHR.responseJSON.message;
                }

                showAlert($tokenAlert, alertType, message);

                if (jqXHR.responseJSON && typeof jqXHR.responseJSON.csrf_token === 'string') {
                    updateCsrfToken(jqXHR.responseJSON.csrf_token);
                }
            });
        });
    });
})(jQuery);
