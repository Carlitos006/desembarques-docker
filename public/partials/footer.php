<?php
/**
 * Application footer with a language selector.
 *
 * Expected variables:
 * - string $currentLanguage (optional)
 */

$language = isset($currentLanguage) ? normalizeLanguage((string) $currentLanguage) : getAppLanguage();
$languageOptions = getSupportedLanguages($language);

if ($languageOptions === []) {
    return;
}

$languageLabel = translate('common.language.label', [], $language);
$languageApplyLabel = translate('common.language.apply', [], $language);
$languageSelectId = 'app-footer-language';
$action = '';

if (! empty($_SERVER['SCRIPT_NAME'])) {
    $action = (string) $_SERVER['SCRIPT_NAME'];
} elseif (! empty($_SERVER['PHP_SELF'])) {
    $action = (string) $_SERVER['PHP_SELF'];
}

$preservedQueryParams = [];

if (! empty($_GET) && is_array($_GET)) {
    foreach ($_GET as $paramKey => $paramValue) {
        if ($paramKey === 'lang') {
            continue;
        }

        if (! is_scalar($paramValue)) {
            continue;
        }

        $preservedQueryParams[(string) $paramKey] = (string) $paramValue;
    }
}
?>
<footer class="app-footer border-top mt-5 py-4">
    <div class="container d-flex flex-column flex-md-row align-items-md-center justify-content-md-between gap-3">
        <div class="text-muted small">
            <?= htmlspecialchars(translate('app.name', [], $language), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php if (count($languageOptions) > 1): ?>
            <form
                class="language-switcher-form d-flex flex-column flex-sm-row align-items-sm-center gap-2"
                method="get"
                action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>"
                accept-charset="utf-8"
            >
                <div class="d-flex align-items-center gap-2">
                    <label class="form-label mb-0" for="<?= htmlspecialchars($languageSelectId, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($languageLabel, ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <select
                        class="form-select form-select-sm"
                        id="<?= htmlspecialchars($languageSelectId, ENT_QUOTES, 'UTF-8') ?>"
                        name="lang"
                        onchange="this.form.submit()"
                    >
                        <?php foreach ($languageOptions as $code => $option): ?>
                            <?php $code = (string) $code; ?>
                            <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" <?= $code === $language ? 'selected' : '' ?>>
                                <?= htmlspecialchars($option['label'] ?? $code, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php foreach ($preservedQueryParams as $key => $value): ?>
                    <input type="hidden" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>">
                <?php endforeach; ?>
                <noscript>
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        <?= htmlspecialchars($languageApplyLabel, ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </noscript>
            </form>
        <?php endif; ?>
    </div>
</footer>
