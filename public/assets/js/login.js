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

    $(function () {
        var $form = $('#login-form');
        var $alert = $('#login-alert');
        var $languageInputs = $('input[name="language"]');
        var $timezoneInput = $('#timezone');

        if ($timezoneInput.length && !$timezoneInput.val()) {
            try {
                var timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

                if (timezone) {
                    $timezoneInput.val(timezone);
                }
            } catch (error) {
                // If the browser cannot determine the timezone we fall back to the server default.
            }
        }

        if ($languageInputs.length) {
            $languageInputs.on('change', function () {
                var selectedInput = $languageInputs.filter(':checked');
                var selected = selectedInput.length ? selectedInput.val() : 'es';

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

        if (! $form.length) {
            return;
        }

        $form.on('submit', function (event) {
            event.preventDefault();

            var $submitButton = $form.find('button[type="submit"]');
            showAlert($alert, '', '');
            $submitButton.prop('disabled', true);

            $.ajax({
                url: '../api/auth/login.php',
                method: 'POST',
                data: $form.serialize(),
                dataType: 'json',
            })
                .done(function (response) {
                    if (response && response.success) {
                        var redirectUrl = 'index.php';

                        if (response.user && typeof response.user === 'object' && response.user.role !== undefined && response.user.role !== null) {
                            var userRole = String(response.user.role).trim().toLowerCase();

                            if (userRole === 'cliente') {
                                redirectUrl = 'client-portal.php';
                            }
                        }

                        window.location.href = redirectUrl;
                        return;
                    }

                    var message = translate('auth.login.error.generic', 'No fue posible iniciar sesión con las credenciales proporcionadas.');
                    if (response && response.message) {
                        message = response.message;
                    }

                    showAlert($alert, 'warning', message);
                })
                .fail(function (jqXHR) {
                    var status = jqXHR.status;
                    var message = translate('auth.login.error.request', 'Ocurrió un error al intentar iniciar sesión.');

                    if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                        message = jqXHR.responseJSON.message;
                    }

                    var alertType = 'danger';

                    if (status === 401 || status === 422) {
                        alertType = 'warning';
                    }

                    if (status === 419) {
                        message = jqXHR.responseJSON && jqXHR.responseJSON.message
                            ? jqXHR.responseJSON.message
                            : translate('common.csrf_token_invalid', 'La seguridad del formulario expiró. Actualiza la página e inténtalo de nuevo.');
                        alertType = 'warning';
                    }

                    showAlert($alert, alertType, message);
                })
                .always(function () {
                    $submitButton.prop('disabled', false);
                });
        });
    });
})(jQuery);
