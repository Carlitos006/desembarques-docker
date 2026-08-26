<?php
/**
 * Shared script includes.
 *
 * Optional variables:
 * - bool $includeSweetAlert
 * - array<int, string|array<string, string>> $pageScripts
 */

$includeSweetAlert = isset($includeSweetAlert) ? (bool) $includeSweetAlert : false;
$pageScripts = isset($pageScripts) && is_array($pageScripts) ? $pageScripts : [];
?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="assets/js/i18n-runtime.js?v=42"></script>
<?php if ($includeSweetAlert): ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.all.min.js" integrity="sha384-5VBAkNWNEnA0Y+L5aWNg6fHumW6MdNSl4unYF6X6pHsXjltAvKa6VxLur8ZAQlzu" crossorigin="anonymous"></script>
<?php endif; ?>
<?php foreach ($pageScripts as $scriptDefinition): ?>
    <?php
        $scriptAttributes = null;

        if (is_string($scriptDefinition) && $scriptDefinition !== '') {
            $scriptAttributes = ['src' => $scriptDefinition];
        } elseif (is_array($scriptDefinition)) {
            $scriptSrc = (string) ($scriptDefinition['src'] ?? '');

            if ($scriptSrc !== '') {
                $scriptAttributes = $scriptDefinition;
                $scriptAttributes['src'] = $scriptSrc;
            }
        }

        if ($scriptAttributes === null) {
            continue;
        }

        $attributeHtml = '';

        foreach ($scriptAttributes as $attribute => $value) {
            if ($attribute === '') {
                continue;
            }

            if ($value === true) {
                $attributeHtml .= sprintf(
                    ' %s',
                    htmlspecialchars((string) $attribute, ENT_QUOTES, 'UTF-8')
                );

                continue;
            }

            if ($value === false || $value === null || $value === '') {
                continue;
            }

            $attributeHtml .= sprintf(
                ' %s="%s"',
                htmlspecialchars((string) $attribute, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
            );
        }
    ?>
    <script<?= $attributeHtml ?>></script>
<?php endforeach; ?>
<script>
    (function () {
        if (window.jQuery) {
            // Las peticiones GET de jQuery no deben reutilizar respuestas con datos de MySQL.
            window.jQuery.ajaxSetup({ cache: false });
        }

        if (!('serviceWorker' in navigator)) {
            return;
        }

        var hadController = Boolean(navigator.serviceWorker.controller);
        var isReloadingForServiceWorker = false;
        var appLanguage = String(document.documentElement.lang || 'es').toLowerCase().indexOf('en') === 0 ? 'en' : 'es';

        function sendLanguageToServiceWorker(registration) {
            var message = {type: 'SET_APP_LANGUAGE', language: appLanguage};
            if (navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage(message);
            }
            if (!registration) {
                return;
            }
            [registration.active, registration.waiting, registration.installing].forEach(function (worker) {
                if (worker) {
                    worker.postMessage(message);
                }
            });
        }

        if (hadController) {
            navigator.serviceWorker.addEventListener('controllerchange', function () {
                if (isReloadingForServiceWorker) {
                    return;
                }

                isReloadingForServiceWorker = true;
                window.location.reload();
            });
        }

        window.addEventListener('load', function () {
            navigator.serviceWorker.register('service-worker.js', { updateViaCache: 'none' }).then(function (registration) {
                sendLanguageToServiceWorker(registration);
                return registration.update().then(function () {
                    sendLanguageToServiceWorker(registration);
                });
            }).catch(function (error) {
                console.error('Service worker registration failed:', error);
            });
            navigator.serviceWorker.ready.then(sendLanguageToServiceWorker).catch(function () {});
        });
    }());
</script>
