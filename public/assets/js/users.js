(function ($) {
    'use strict';

    var appConfig = window.AppConfig || {};
    var translations = appConfig.translations || {};

    function translate(key, fallback) {
        if (Object.prototype.hasOwnProperty.call(translations, key)) {
            return translations[key];
        }

        return fallback;
    }

     function stringValue(value) {
        if (value === undefined || value === null) {
            return '';
        }

        return String(value);
    }


    function resetAlert($alert) {
        if ($alert.length) {
            $alert.removeClass('alert-success alert-danger alert-warning alert-info alert-primary').addClass('d-none');
            $alert.text('');
        }
    }

    function showAlert($alert, type, message) {
        if (! $alert.length) {
            return;
        }

        $alert
            .removeClass('d-none alert-success alert-danger alert-warning alert-info alert-primary')
            .addClass('alert-' + type)
            .text(message || '');
    }

    function createUserRow(user, editButtonLabel) {
        if (! user) {
            return null;
        }

        var id = stringValue(user.id);
        var name = stringValue(user.name);
        var email = stringValue(user.email);
        var role = stringValue(user.role);
        var roleLabel = stringValue(user.role_label || user.role);
        var createdAt = stringValue(user.created_at_display || user.created_at);
        var lastLoginAt = stringValue(user.last_login_at_display || user.last_login_at_utc || user.last_login_at);
        var buttonLabel = editButtonLabel || 'Editar';

        var $row = $('<tr></tr>').attr('data-user-id', id);
        $row.append($('<td></td>').text(id));
        $row.append($('<td></td>').text(name));
        $row.append($('<td></td>').text(email));
        $row.append($('<td></td>').text(roleLabel));
        $row.append($('<td></td>').text(createdAt));
        $row.append($('<td></td>').text(lastLoginAt));

        var $actionsCell = $('<td></td>').addClass('text-end');
        var $editButton = $('<button type="button"></button>')
            .addClass('btn btn-sm btn-outline-primary js-edit-user')
            .text(buttonLabel)
            .attr('data-user-id', id)
            .attr('data-user-name', name)
            .attr('data-user-email', email)
            .attr('data-user-role', role);

        $actionsCell.append($editButton);
        $row.append($actionsCell);

        return $row;
    }

    function prependUserRow($tableBody, user, editButtonLabel) {
        if (! $tableBody.length) {
            return;
        }

        var $row = createUserRow(user, editButtonLabel);

        if ($row) {
            $tableBody.prepend($row);
        }
    }

    function replaceUserRow($tableBody, user, editButtonLabel) {
        if (! $tableBody.length || ! user) {
            return;
        }

         var id = stringValue(user.id);

        if (! id) {
            return;
        }

        var $existingRow = $tableBody.find('tr[data-user-id="' + id + '"]');
        var $newRow = createUserRow(user, editButtonLabel);

        if ($existingRow.length && $newRow) {
            $existingRow.replaceWith($newRow);
        } else if ($newRow) {
            $tableBody.prepend($newRow);
        }
    }

    $(function () {
        var $form = $('#user-form');
        var $alert = $('#user-alert');
        var $tableBody = $('#users-table-body');
        var $roleSelect = $('#role');
        var $clientAssignmentGroup = $('#client-assignment-group');
        var $clientSelect = $('#client_id');
        var $clientHelp = $('#client-assignment-help');
        var clientHelpText = translate('users.form.client_help', 'Associate this user with a client to grant access to their reports.');
        var clientEmptyText = translate('users.form.client_empty', 'There are no clients available to assign.');
        var clientSelectLocked = Boolean($clientSelect.data('locked'));

        if (! $form.length) {
            return;
        }

        var $submitButton = $form.find('button[type="submit"]');
        var $userIdField = $('#user_id');
        var $nameField = $('#name');
        var $emailField = $('#email');
        var $passwordField = $('#password');
        var $passwordConfirmationField = $('#password_confirmation');
        var $roleField = $('#role');

        var createButtonLabel = translate('users.form.submit', 'Crear usuario');
        var updateButtonLabel = translate('users.form.update', 'Actualizar usuario');
        var editButtonLabel = translate('users.table.actions.edit', 'Editar');

        var createUrl = '../api/users/store.php';
        var updateUrl = '../api/users/update.php';

        function enterCreateMode() {
            $form.attr('data-mode', 'create');

            if ($userIdField.length) {
                $userIdField.val('');
            }

            if ($submitButton.length) {
                $submitButton.text(createButtonLabel);
            }

            if ($passwordField.length) {
                $passwordField.prop('required', true).val('');
            }

            if ($passwordConfirmationField.length) {
                $passwordConfirmationField.prop('required', true).val('');
            }
        }

        function enterEditMode(user) {
            $form.attr('data-mode', 'edit');

            var id = stringValue(user && user.id).trim();
            var name = stringValue(user && user.name);
            var email = stringValue(user && user.email);
            var role = stringValue(user && user.role);

            if ($userIdField.length) {
                $userIdField.val(id);
            }

            if ($nameField.length) {
                $nameField.val(name);
            }

            if ($emailField.length) {
                $emailField.val(email);
            }

            if ($roleField.length && role) {
                $roleField.val(role);
            }

            if ($submitButton.length) {
                $submitButton.text(updateButtonLabel);
            }

            if ($passwordField.length) {
                $passwordField.prop('required', false).val('');
            }

            if ($passwordConfirmationField.length) {
                $passwordConfirmationField.prop('required', false).val('');
            }

            if ($nameField.length) {
                $nameField.trigger('focus');
            }
        }

        function resetFormToCreateMode() {
            if ($form.length && $form[0]) {
                $form[0].reset();
            }

            enterCreateMode();
        }

        enterCreateMode();

        $form.on('reset', function () {
            window.setTimeout(function () {
                resetAlert($alert);
                enterCreateMode();
            }, 0);
        });

        $tableBody.on('click', '.js-edit-user', function () {
            var $button = $(this);
            var user = {
                id: $button.data('userId'),
                name: $button.data('userName'),
                email: $button.data('userEmail'),
                role: $button.data('userRole'),
            };

            resetAlert($alert);
            enterEditMode(user);
        });


        function hasClientOptions() {
            if (! $clientSelect.length) {
                return false;
            }

            var hasOptions = false;

            $clientSelect.find('option').each(function () {
                var value = $(this).attr('value');

                if (value && value !== '') {
                    hasOptions = true;
                    return false;
                }

                return true;
            });

            return hasOptions;
        }

        function updateClientHelpText() {
            if (! $clientHelp.length) {
                return;
            }

            if (! $clientSelect.length || $clientSelect.prop('disabled') || ! hasClientOptions()) {
                $clientHelp.text(clientEmptyText);
            } else {
                $clientHelp.text(clientHelpText);
            }
        }

        function toggleClientAssignmentVisibility() {
            if (! $clientAssignmentGroup.length) {
                return;
            }

            var selectedRole = $roleSelect.val();

            if (selectedRole === 'cliente') {
                $clientAssignmentGroup.removeClass('d-none');
            } else {
                $clientAssignmentGroup.addClass('d-none');

                if ($clientSelect.length) {
                    $clientSelect.val('');
                }
            }

            updateClientHelpText();
        }

        function removeAssignedClientOption(assignedClient) {
            if (! $clientSelect.length) {
                updateClientHelpText();
                return;
            }

            if (! assignedClient || clientSelectLocked) {
                updateClientHelpText();
                return;
            }

            var clientId = typeof assignedClient === 'object' ? assignedClient.id : assignedClient;

            if (clientId === undefined || clientId === null || clientId === '') {
                updateClientHelpText();
                return;
            }

            var clientIdValue = String(clientId);
            var $option = $clientSelect.find('option[value="' + clientIdValue + '"]');

            if ($option.length) {
                $option.remove();
            }

            if (! hasClientOptions()) {
                $clientSelect.prop('disabled', true);
            }

            updateClientHelpText();
        }

        toggleClientAssignmentVisibility();
        updateClientHelpText();

        if ($roleSelect.length) {
            $roleSelect.on('change', toggleClientAssignmentVisibility);
        }

        $form.on('reset', function () {
            setTimeout(function () {
                toggleClientAssignmentVisibility();
            }, 0);
        });

        $form.on('submit', function (event) {
            event.preventDefault();

            resetAlert($alert);

            var currentUserId = stringValue($userIdField.val()).trim();
            var isEditing = currentUserId !== '';
            var requestUrl = isEditing ? updateUrl : createUrl;
            var successFallback = isEditing
                ? translate('users.alert.update_success', 'El usuario se actualizó correctamente.')
                : translate('users.alert.success', 'El usuario se creó correctamente.');
            var validationFallback = isEditing
                ? translate('users.alert.update_validation', 'No fue posible actualizar el usuario con la información proporcionada.')
                : translate('users.alert.validation', 'No fue posible crear el usuario con la información proporcionada.');
            var errorFallback = isEditing
                ? translate('users.alert.update_error', 'Ocurrió un error al actualizar el usuario.')
                : translate('users.alert.error', 'Ocurrió un error al crear el usuario.');

            $submitButton.prop('disabled', true);

            $.ajax({
                url: requestUrl,
                method: 'POST',
                data: $form.serialize(),
                dataType: 'json',
            })
                .done(function (response) {
                    if (response && response.success && response.user) {
                        showAlert($alert, 'success', response.message || successFallback);

                        if (isEditing) {
                            replaceUserRow($tableBody, response.user, editButtonLabel);
                        } else {
                            prependUserRow($tableBody, response.user, editButtonLabel);
                        }

                        resetFormToCreateMode();
                        removeAssignedClientOption(response.assigned_client);
                        return;
                    }

                    var message = translate('users.alert.validation', 'No fue posible crear el usuario con la información proporcionada.');
                    if (response && response.message) {
                        message = response.message;
                    }

                    showAlert($alert, 'warning', message);
                })
                .fail(function (jqXHR) {
                    var message = errorFallback;

                    if (jqXHR.responseJSON) {
                        if (jqXHR.responseJSON.message) {
                            message = jqXHR.responseJSON.message;
                        }

                        if (jqXHR.responseJSON.errors) {
                            var details = Object.values(jqXHR.responseJSON.errors).join(' ');
                            if (details) {
                                message += ' ' + details;
                            }
                        }
                    }

                    var status = jqXHR.status;
                    var type = status === 401 || status === 403 ? 'warning' : 'danger';

                    if (status === 419) {
                        message = jqXHR.responseJSON && jqXHR.responseJSON.message
                            ? jqXHR.responseJSON.message
                            : translate('common.csrf_token_invalid', 'La seguridad del formulario expiró. Actualiza la página e inténtalo de nuevo.');
                        type = 'warning';
                    }

                    showAlert($alert, type, message);
                })
                .always(function () {
                    $submitButton.prop('disabled', false);
                    updateClientHelpText();
                });
        });
    });
})(jQuery);