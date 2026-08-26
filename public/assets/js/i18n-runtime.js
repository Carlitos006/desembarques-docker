(function (window, document) {
    'use strict';

    function normalizeLanguage(value) {
        return String(value || '').trim().toLowerCase() === 'en' ? 'en' : 'es';
    }

    function getLanguage() {
        var configured = window.AppConfig && window.AppConfig.language;
        return normalizeLanguage(configured || document.documentElement.lang || 'es');
    }

    function text(spanish, english) {
        return getLanguage() === 'en'
            ? String(english == null ? '' : english)
            : String(spanish == null ? '' : spanish);
    }

    function format(spanish, english, replacements) {
        var value = text(spanish, english);
        Object.keys(replacements || {}).forEach(function (key) {
            value = value.split('{{' + key + '}}').join(String(replacements[key] == null ? '' : replacements[key]));
        });
        return value;
    }

    function plural(count, spanishOne, spanishMany, englishOne, englishMany) {
        var numericCount = Number(count || 0);
        return getLanguage() === 'en'
            ? (numericCount === 1 ? englishOne : englishMany)
            : (numericCount === 1 ? spanishOne : spanishMany);
    }

    window.AppI18n = Object.assign({}, window.AppI18n || {}, {
        getLanguage: getLanguage,
        text: text,
        format: format,
        plural: plural
    });
}(window, document));
