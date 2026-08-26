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

    function resetFieldErrors($form) {
        if (! $form.length) {
            return;
        }

        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('.invalid-feedback').each(function () {
            var $feedback = $(this);
            if ($feedback.data('errorFor')) {
                $feedback.text('');
            }
        });
    }

    function handleLanguageSelector($selector) {
        if (! $selector.length) {
            return;
        }

        $selector.on('change', function () {
            var selected = $selector.val() || 'es';

            try {
                var url = new URL(window.location.href);
                url.searchParams.set('lang', selected);
                window.location.href = url.toString();
            } catch (error) {
                var base = window.location.href.split('?')[0];
                var params = window.location.search ? window.location.search.replace(/^\?/, '').split('&').filter(function (param) {
                    return param && param.split('=')[0] !== 'lang';
                }) : [];
                params.push('lang=' + encodeURIComponent(selected));
                window.location.href = base + '?' + params.join('&');
            }
        });
    }

    function disableFormFields($form) {
        if (! $form.length) {
            return;
        }

        $form.find('input, button, select').prop('disabled', true);
    }

    $(function () {
        var $form = $('#password-reset-form');
        var $alert = $('#password-reset-alert');
        var $language = $('#language');
        var $submitButton = $form.find('button[type="submit"]');

        handleLanguageSelector($language);

        if (! $form.length) {
            return;
        }

        var initialDisabled = $submitButton.prop('disabled');
        $submitButton.data('initial-disabled', initialDisabled);

        $form.on('submit', function (event) {
            event.preventDefault();

            if (! $form.length) {
                return;
            }

            resetFieldErrors($form);
            showAlert($alert, '', '');

            $submitButton.prop('disabled', true);

            $.ajax({
                url: '../api/auth/reset_password.php',
                method: 'POST',
                data: $form.serialize(),
                dataType: 'json',
            })
                .done(function (response) {
                    if (response && response.success) {
                        var successMessage = response.message || translate('auth.password_reset.reset.success', 'Tu contraseña se actualizó correctamente.');
                        showAlert($alert, 'success', successMessage);

                        if (response.redirect) {
                            var delay = Number(appConfig.redirectDelay || 0);

                            if (delay < 0 || isNaN(delay)) {
                                delay = 0;
                            }

                            window.setTimeout(function () {
                                window.location.href = response.redirect;
                            }, delay || 4000);
                        }

                        disableFormFields($form);

                        return;
                    }

                    var message = translate('auth.password_reset.reset.error.generic', 'No fue posible restablecer la contraseña.');

                    if (response && response.message) {
                        message = response.message;
                    }

                    showAlert($alert, 'danger', message);
                })
                .fail(function (jqXHR) {
                    var status = jqXHR.status;
                    var message = translate('auth.password_reset.reset.error.generic', 'No fue posible restablecer la contraseña.');

                    if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                        message = jqXHR.responseJSON.message;
                    }

                    if (status === 422 && jqXHR.responseJSON && jqXHR.responseJSON.errors) {
                        var errors = jqXHR.responseJSON.errors;

                        Object.keys(errors).forEach(function (field) {
                            var errorMessage = errors[field];
                            var $field = $form.find('[name="' + field + '"]');
                            var $feedback = $form.find('[data-error-for="' + field + '"]');

                            if ($field.length) {
                                $field.addClass('is-invalid');
                            }

                            if ($feedback.length) {
                                $feedback.text(errorMessage);
                            }
                        });

                        if (errors.token) {
                            disableFormFields($form);
                        }

                        showAlert($alert, 'warning', message);
                        return;
                    }

                    if (status === 419) {
                        message = jqXHR.responseJSON && jqXHR.responseJSON.message
                            ? jqXHR.responseJSON.message
                            : translate('common.csrf_token_invalid', 'La seguridad del formulario expiró.');
                        showAlert($alert, 'warning', message);
                        return;
                    }

                    var alertType = status >= 500 ? 'danger' : 'warning';
                    showAlert($alert, alertType, message);
                })
                .always(function () {
                    var keepDisabled = Boolean($submitButton.data('initial-disabled'));
                    $submitButton.prop('disabled', keepDisabled);
                });
        });
    });
})(jQuery);
