(function ($) {
    'use strict';

    var VALID_THEMES = ['light', 'dark'];
    var ONE_YEAR_SECONDS = 60 * 60 * 24 * 365;

    function normalizeTheme(theme) {
        if (typeof theme === 'string') {
            var normalized = theme.toLowerCase();
            if (VALID_THEMES.indexOf(normalized) !== -1) {
                return normalized;
            }
        }

        return 'light';
    }

    function applyTheme(theme) {
        if (theme === 'dark') {
            document.body.classList.add('theme-dark');
        } else {
            document.body.classList.remove('theme-dark');
        }
    }

    function persistTheme(preferenceKey, theme) {
        if (! preferenceKey) {
            return;
        }

        var cookieValue = preferenceKey + '=' + encodeURIComponent(theme) + ';path=/;max-age=' + ONE_YEAR_SECONDS + ';SameSite=Lax';
        document.cookie = cookieValue;
    }

    function getAttribute($element, attribute, fallback) {
        if (! ($element && $element.length)) {
            return fallback;
        }

        var value = $element.attr(attribute);

        return typeof value === 'string' && value.length ? value : fallback;
    }

    function updateToggleState($toggle, theme) {
        if (! ($toggle && $toggle.length)) {
            return;
        }

        var label = getAttribute($toggle, 'data-theme-label', '');
        var lightText = getAttribute($toggle, 'data-theme-light-text', 'Light');
        var darkText = getAttribute($toggle, 'data-theme-dark-text', 'Dark');
        var themeText = theme === 'dark' ? darkText : lightText;
        var combinedLabel = label ? label + ': ' + themeText : themeText;
        var $icon = $toggle.find('.theme-icon');
        var $srLabel = $toggle.find('.theme-toggle-label');

        if ($icon.length) {
            $icon.text(theme === 'dark' ? '🌙' : '☀️');
        }

        if ($srLabel.length) {
            $srLabel.text(combinedLabel);
        }

        $toggle.attr('aria-pressed', theme === 'dark' ? 'true' : 'false');
        $toggle.attr('aria-label', combinedLabel);
        $toggle.attr('title', combinedLabel);
    }

    function syncThemeControls($selector, $toggles, theme) {
        if ($selector && $selector.length) {
            $selector.val(theme);
        }

        if ($toggles && $toggles.length) {
            $toggles.each(function () {
                updateToggleState($(this), theme);
            });
        }
    }

    $(function () {
        var appConfig = window.AppConfig || {};
        var preferenceKey = appConfig.themePreferenceKey || '';
        var currentTheme = normalizeTheme(appConfig.theme);
        var $selector = $('#theme-selector');
        var $toggles = $('[data-theme-toggle]');

        applyTheme(currentTheme);
        syncThemeControls($selector, $toggles, currentTheme);

        if ($selector.length) {
            $selector.on('change', function () {
                var selectedTheme = normalizeTheme($(this).val());

                currentTheme = selectedTheme;
                applyTheme(selectedTheme);
                persistTheme(preferenceKey, selectedTheme);
                syncThemeControls($selector, $toggles, selectedTheme);
            });
        }

        if ($toggles.length) {
            $toggles.on('click', function () {
                var nextTheme = currentTheme === 'dark' ? 'light' : 'dark';

                currentTheme = nextTheme;
                applyTheme(nextTheme);
                persistTheme(preferenceKey, nextTheme);
                syncThemeControls($selector, $toggles, nextTheme);
            });
        }
    });
})(jQuery);
