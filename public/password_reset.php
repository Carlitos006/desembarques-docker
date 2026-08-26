<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/csrf.php';

if (isset($_GET['lang'])) {
    setAppLanguage((string) $_GET['lang']);
}

$currentLanguage = getAppLanguage();

if (isset($_SESSION['user'])) {
    $userRole = (string) ($_SESSION['user']['role'] ?? '');
    $redirectTo = strtolower($userRole) === 'cliente'
        ? 'reportes.php'
        : 'index.php';

    header('Location: ' . $redirectTo);
    exit;
}

$token = trim((string) ($_GET['token'] ?? ''));
$tokenMissing = $token === '';

$csrfToken = csrf_token();

$alertClasses = $tokenMissing ? 'alert alert-warning' : 'alert d-none';
$alertMessage = $tokenMissing
    ? translate('auth.password_reset.reset.token_missing', [], $currentLanguage)
    : '';

$translations = getClientTranslations([
    'auth.password_reset.reset.success',
    'auth.password_reset.reset.error.generic',
    'auth.password_reset.reset.error.invalid_token',
    'auth.password_reset.reset.error.expired',
    'auth.password_reset.reset.error.used',
    'auth.password_reset.reset.error.hash',
    'validation.errors',
    'auth.password_reset.reset.validation.token_required',
    'auth.password_reset.reset.validation.password_required',
    'auth.password_reset.reset.validation.password_min',
    'auth.password_reset.reset.validation.password_confirmation',
    'common.csrf_token_invalid',
], $currentLanguage);

$languageOptions = getSupportedLanguages($currentLanguage);

?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title><?= htmlspecialchars(translate('auth.password_reset.reset.page_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></title>
        <?php require __DIR__ . '/partials/styles.php'; ?>
    </head>
    <body data-token-present="<?= $tokenMissing ? '0' : '1' ?>">
        <div class="auth-wrapper">
            <div class="card shadow-sm auth-card">
                <div class="card-body">
                    <div class="text-center mb-4">
                        <h1 class="h3 mb-1"><?= htmlspecialchars(translate('auth.password_reset.reset.heading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h1>
                        <p class="text-muted mb-0"><?= htmlspecialchars(translate('auth.password_reset.reset.description', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div id="password-reset-alert" class="<?= htmlspecialchars($alertClasses, ENT_QUOTES, 'UTF-8') ?>" role="alert">
                        <?= htmlspecialchars($alertMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <form id="password-reset-form" autocomplete="off" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mb-3">
                            <label for="language" class="form-label"><?= htmlspecialchars(translate('auth.login.language_label', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                            <select class="form-select" id="language" name="language">
                                <?php foreach ($languageOptions as $code => $languageOption): ?>
                                    <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" <?= $code === $currentLanguage ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($languageOption['label'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label"><?= htmlspecialchars(translate('auth.password_reset.reset.password_label', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="password" class="form-control" id="password" name="password" required <?= $tokenMissing ? 'disabled' : '' ?>>
                            <div class="invalid-feedback" data-error-for="password"></div>
                        </div>
                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label"><?= htmlspecialchars(translate('auth.password_reset.reset.password_confirmation_label', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required <?= $tokenMissing ? 'disabled' : '' ?>>
                            <div class="invalid-feedback" data-error-for="password_confirmation"></div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" <?= $tokenMissing ? 'disabled' : '' ?>>
                            <?= htmlspecialchars(translate('auth.password_reset.reset.submit', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </form>
                    <p class="text-muted small text-center mt-4 mb-0">
                        <a href="login.php" class="link-secondary text-decoration-none">
                            <?= htmlspecialchars(translate('auth.password_reset.reset.back_to_login', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <span class="mx-1">·</span>
                        <a href="password_reset_request.php" class="link-secondary text-decoration-none">
                            <?= htmlspecialchars(translate('auth.password_reset.reset.request_new', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </p>
                </div>
            </div>
        </div>
        <script>
            window.AppConfig = {
                language: '<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>',
                translations: <?= json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                redirectDelay: 4000
            };
        </script>
        <?php
            $includeSweetAlert = false;
            $pageScripts = [
                'assets/js/password_reset.js',
            ];
            require __DIR__ . '/partials/scripts.php';
        ?>
    </body>
</html>
