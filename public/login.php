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

$status = (string) ($_GET['status'] ?? '');
$message = '';
$messageType = 'info';

switch ($status) {
    case 'logged_out':
        $message = translate('auth.login.status.logged_out', [], $currentLanguage);
        $messageType = 'success';
        break;
    case 'unauthorized':
        $message = translate('auth.login.status.unauthorized', [], $currentLanguage);
        $messageType = 'warning';
        break;
    case 'expired':
        $message = translate('auth.login.status.expired', [], $currentLanguage);
        $messageType = 'warning';
        break;
    case 'password_reset_sent':
        $message = translate('auth.login.status.password_reset_sent', [], $currentLanguage);
        $messageType = 'success';
        break;
    case 'password_reset_success':
        $message = translate('auth.login.status.password_reset_success', [], $currentLanguage);
        $messageType = 'success';
        break;
}

$alertClasses = 'alert d-none';
if ($message !== '') {
    $alertClasses = 'alert alert-' . $messageType;
}

$loginTranslations = getClientTranslations([
    'auth.login.error.generic',
    'auth.login.error.invalid_credentials',
    'auth.login.error.request',
    'common.csrf_token_invalid',
], $currentLanguage);

$csrfToken = csrf_token();
$contactEmailAddress = 'carlos.navarrete@grupogerez.com';
$contactEmailBody = translate('auth.login.contact_admin_email_body', [], $currentLanguage);
$contactEmailHref = 'mailto:' . $contactEmailAddress;

if ($contactEmailBody !== '') {
    $contactEmailHref .= '?' . http_build_query([
        'body' => $contactEmailBody,
    ], '', '&', PHP_QUERY_RFC3986);
}

?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title><?= htmlspecialchars(translate('auth.login.page_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></title>
        <?php require __DIR__ . '/partials/styles.php'; ?>
    </head>
    <body>
        <div class="auth-wrapper">
            <div class="card shadow-sm auth-card">
                <div class="card-body">
                    <div class="text-center mb-4">
                        <h1 class="h3 mb-1"><?= htmlspecialchars(translate('auth.login.heading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h1>
                        <p class="text-muted mb-0"><?= htmlspecialchars(translate('auth.login.subheading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div id="login-alert" class="<?= htmlspecialchars($alertClasses, ENT_QUOTES, 'UTF-8') ?>" role="alert">
                        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <form id="login-form" autocomplete="off" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="timezone" id="timezone" value="">
                        <div class="mb-3">
                            <?php $languageLabelId = 'language-label'; ?>
                            <span id="<?= htmlspecialchars($languageLabelId, ENT_QUOTES, 'UTF-8') ?>" class="form-label d-block mb-2">
                                <?= htmlspecialchars(translate('auth.login.language_label', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <div class="language-selector" role="radiogroup" aria-labelledby="<?= htmlspecialchars($languageLabelId, ENT_QUOTES, 'UTF-8') ?>">
                                <?php foreach (getSupportedLanguages($currentLanguage) as $code => $languageOption): ?>
                                    <?php
                                        $code = (string) $code;
                                        $optionId = 'language-' . $code;
                                        $isCurrent = $code === $currentLanguage;
                                        $flagPath = 'assets/img/flags/' . $code . '.svg';
                                    ?>
                                    <input
                                        type="radio"
                                        class="language-selector__input"
                                        name="language"
                                        id="<?= htmlspecialchars($optionId, ENT_QUOTES, 'UTF-8') ?>"
                                        value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
                                        <?= $isCurrent ? 'checked' : '' ?>
                                    >
                                    <label
                                        class="language-selector__option"
                                        for="<?= htmlspecialchars($optionId, ENT_QUOTES, 'UTF-8') ?>"
                                        title="<?= htmlspecialchars($languageOption['label'], ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                        <span class="language-selector__flag" aria-hidden="true">
                                            <img src="<?= htmlspecialchars($flagPath, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                        </span>
                                        <span class="language-selector__text">
                                            <?= htmlspecialchars($languageOption['label'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label"><?= htmlspecialchars(translate('auth.login.email_label', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="email" class="form-control" id="email" name="email" maxlength="150" required autofocus>
                        </div>
                        <div class="mb-4">
                            <label for="password" class="form-label"><?= htmlspecialchars(translate('auth.login.password_label', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><?= htmlspecialchars(translate('auth.login.submit', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></button>
                    </form>
                    <p class="text-center mt-4 mb-2">
                        <a href="password_reset_request.php" class="link-secondary text-decoration-none small">
                            <?= htmlspecialchars(translate('auth.login.forgot_password', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </p>
                    <p class="text-muted small text-center mb-0">
                        <?= htmlspecialchars(translate('auth.login.request_account', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                        <a href="<?= htmlspecialchars($contactEmailHref, ENT_QUOTES, 'UTF-8') ?>" class="link-secondary text-decoration-none">
                            <?= htmlspecialchars(translate('auth.login.contact_admin', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                        </a>.
                    </p>
                </div>
            </div>
        </div>
        <script>
            window.AppConfig = {
                language: '<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>',
                translations: <?= json_encode($loginTranslations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
            };
        </script>
        <?php
            $includeSweetAlert = false;
            $pageScripts = [
                'assets/js/login.js',
            ];
            require __DIR__ . '/partials/scripts.php';
        ?>
    </body>
</html>
