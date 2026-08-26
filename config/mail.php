<?php

declare(strict_types=1);

/**
 * Retrieve SMTP and mail-related configuration from the environment.
 */
function getMailConfiguration(): array
{
    static $configuration = null;

    if ($configuration !== null) {
        return $configuration;
    }

    $appUrl = getenv('APP_URL');
    $appUrl = is_string($appUrl) ? trim($appUrl) : '';

    if ($appUrl !== '') {
        $appUrl = rtrim($appUrl, '/');
    }

    $configuration = [
        'host' => (string) (getenv('SMTP_HOST') ?: ''),
        'port' => (int) (getenv('SMTP_PORT') ?: 587),
        'username' => (string) (getenv('SMTP_USERNAME') ?: ''),
        'password' => (string) (getenv('SMTP_PASSWORD') ?: ''),
        'encryption' => (string) (getenv('SMTP_ENCRYPTION') ?: ''),
        'from_address' => (string) (getenv('MAIL_FROM_ADDRESS') ?: 'no-reply@example.com'),
        'from_name' => (string) (getenv('MAIL_FROM_NAME') ?: 'Registro de Desembarques'),
        'app_url' => $appUrl,
    ];

    return $configuration;
}

/**
 * Resolve the base application URL used in email content.
 */
function resolveMailAppBaseUrl(): string
{
    $configuration = getMailConfiguration();
    $baseUrl = $configuration['app_url'];

    if ($baseUrl !== '') {
        return $baseUrl;
    }

    $scheme = (! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return rtrim($scheme . '://' . $host, '/') . '/desembarques/public';
}

/**
 * Resolve the preferred application entry point URL for general access links.
 */
function resolveMailAppEntryUrl(): string
{
    $baseUrl = resolveMailAppBaseUrl();

    if ($baseUrl === '') {
        return '';
    }

    $normalized = rtrim($baseUrl, '/');
    $path = parse_url($normalized, PHP_URL_PATH);

    if (is_string($path) && $path !== '') {
        if (preg_match('/\.php$/i', $path) === 1) {
            return $normalized;
        }
    }

    return $normalized . '/reportes.php';
}

/**
 * Translate a string if the translation helpers are available.
 *
 * @param array<string, string> $replacements
 */
function mail_translate(string $key, array $replacements, string $language, string $default): string
{
    if (function_exists('translate')) {
        $translated = translate($key, $replacements, $language);

        if (is_string($translated)) {
            $normalizedTranslated = trim($translated);
            $normalizedKey = trim($key);

            if ($normalizedTranslated !== '' && $normalizedTranslated !== $normalizedKey) {
                return $translated;
            }
        }
    }

    if ($replacements !== []) {
        $replacePairs = [];

        foreach ($replacements as $placeholder => $replacement) {
            $replacePairs['{{' . $placeholder . '}}'] = (string) $replacement;
        }

        $default = strtr($default, $replacePairs);
    }

    return $default;
}

/**
 * Normalize language codes for email rendering.
 */
function normalizeMailLanguage(?string $language): string
{
    if (function_exists('normalizeLanguage')) {
        $normalized = normalizeLanguage($language);

        if (is_string($normalized) && $normalized !== '') {
            return strtolower($normalized);
        }
    }

    if (is_string($language) && $language !== '') {
        return strtolower($language);
    }

    return 'es';
}

/**
 * Resolve the application name with translation fallbacks.
 */
function resolveMailAppName(string $language): string
{
    return mail_translate('app.name', [], $language, 'Registro de Desembarques');
}

/**
 * Provide a human-friendly label for a language code.
 */
function mail_language_label(string $language): string
{
    $language = strtolower($language);

    switch ($language) {
        case 'en':
            return 'English';
        case 'es':
            return 'Español';
        default:
            return strtoupper($language);
    }
}

/**
 * Convert a list of lines into formatted HTML blocks.
 *
 * @param list<string> $lines
 */
function mail_format_lines_as_html(array $lines): string
{
    $htmlBlocks = [];
    $currentParagraph = [];

    foreach ($lines as $line) {
        $normalizedLine = rtrim((string) $line, "\r\n");

        if ($normalizedLine === '') {
            if ($currentParagraph !== []) {
                $htmlBlocks[] = '<p>' . implode('<br>', $currentParagraph) . '</p>';
                $currentParagraph = [];
            }

            continue;
        }

        if (filter_var($normalizedLine, FILTER_VALIDATE_URL) !== false) {
            if ($currentParagraph !== []) {
                $htmlBlocks[] = '<p>' . implode('<br>', $currentParagraph) . '</p>';
                $currentParagraph = [];
            }

            $escapedUrl = htmlspecialchars($normalizedLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $htmlBlocks[] = sprintf(
                '<p class="email-cta"><a class="email-button" href="%1$s" target="_blank" rel="noopener">%1$s</a></p>',
                $escapedUrl
            );

            continue;
        }

        $currentParagraph[] = htmlspecialchars(
            $normalizedLine,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    if ($currentParagraph !== []) {
        $htmlBlocks[] = '<p>' . implode('<br>', $currentParagraph) . '</p>';
    }

    if ($htmlBlocks === []) {
        $htmlBlocks[] = '<p>&nbsp;</p>';
    }

    return implode("\n", $htmlBlocks);
}

/**
 * Build an absolute password reset URL.
 */
function buildPasswordResetUrl(string $token, ?string $language = null): string
{
    $baseUrl = resolveMailAppBaseUrl();
    $url = $baseUrl . '/password_reset.php?token=' . rawurlencode($token);

    if ($language !== null && $language !== '') {
        $url .= '&lang=' . rawurlencode($language);
    }

    return $url;
}

/**
 * Send an email using the configured SMTP settings with both plain text and HTML bodies.
 *
 * @param list<string> $lines
 * @param list<array{language:string,lines:list<string>}> $alternateLanguageSets
 */
function sendConfiguredPlainTextEmail(
    string $recipientEmail,
    string $subject,
    array $lines,
    ?string $language = null,
    array $alternateLanguageSets = []
): bool {
    $configuration = getMailConfiguration();

    $language = normalizeMailLanguage($language);

    $appName = resolveMailAppName($language);

    if ($configuration['host'] !== '') {
        ini_set('SMTP', $configuration['host']);
    }

    if ($configuration['port'] > 0) {
        ini_set('smtp_port', (string) $configuration['port']);
    }

    if ($configuration['from_address'] !== '') {
        ini_set('sendmail_from', $configuration['from_address']);
    }

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';

    try {
        $boundary = '=_Part_' . bin2hex(random_bytes(16));
    } catch (Throwable $exception) {
        $fallbackRandom = pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand());
        $boundary = '=_Part_' . bin2hex($fallbackRandom);
    }

    $headers[] = sprintf('Content-Type: multipart/alternative; boundary="%s"', $boundary);

    $fromName = $configuration['from_name'] !== ''
        ? $configuration['from_name']
        : $appName;

    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $headers[] = sprintf('From: %s <%s>', $encodedFromName, $configuration['from_address']);
    $headers[] = sprintf('Reply-To: %s <%s>', $encodedFromName, $configuration['from_address']);

    $encodedSubject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8')
        : '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $sections = [];
    $sections[] = [
        'language' => $language,
        'lines' => array_map(static fn ($line): string => (string) $line, $lines),
    ];

    foreach ($alternateLanguageSets as $alternateSet) {
        if (! is_array($alternateSet)) {
            continue;
        }

        $alternateLines = $alternateSet['lines'] ?? null;

        if (! is_array($alternateLines)) {
            continue;
        }

        $alternateLanguage = $alternateSet['language'] ?? '';
        $alternateLanguage = is_string($alternateLanguage) ? $alternateLanguage : '';

        $sections[] = [
            'language' => $alternateLanguage,
            'lines' => array_map(static fn ($line): string => (string) $line, $alternateLines),
        ];
    }

    $normalizedSections = [];
    $seenLanguages = [];

    foreach ($sections as $section) {
        $sectionLanguage = normalizeMailLanguage($section['language']);

        if (isset($seenLanguages[$sectionLanguage])) {
            continue;
        }

        $seenLanguages[$sectionLanguage] = true;
        $normalizedSections[] = [
            'language' => $sectionLanguage,
            'lines' => $section['lines'],
        ];
    }

    $includeLabels = count($normalizedSections) > 1;
    $textSegments = [];
    $htmlSections = [];

    foreach ($normalizedSections as $section) {
        $label = mail_language_label($section['language']);
        $sectionLines = $section['lines'];

        $textLines = [];

        if ($includeLabels && $label !== '') {
            $textLines[] = '== ' . $label . ' ==';
            $textLines[] = '';
        }

        foreach ($sectionLines as $sectionLine) {
            $textLines[] = (string) $sectionLine;
        }

        $textSegments[] = implode("\r\n", $textLines);

        $sectionHtmlParts = [];

        if ($includeLabels && $label !== '') {
            $escapedLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $sectionHtmlParts[] = '<p class="email-section-title">' . $escapedLabel . '</p>';
        }

        $sectionHtmlParts[] = '<div class="email-section-content">' . mail_format_lines_as_html($sectionLines) . '</div>';

        $htmlSections[] = '<div class="email-section">' . implode("\n", $sectionHtmlParts) . '</div>';
    }

    $textBody = implode("\r\n\r\n", $textSegments);
    $htmlSectionMarkup = implode("\n", $htmlSections);

    $escapedSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escapedBrandName = htmlspecialchars($fromName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $brandInitialSource = $fromName !== '' ? $fromName : $appName;

    if (function_exists('mb_substr')) {
        $brandInitial = mb_substr($brandInitialSource, 0, 1, 'UTF-8') ?: '';
    } else {
        $brandInitial = substr($brandInitialSource, 0, 1) ?: '';
    }

    if ($brandInitial === '') {
        $brandInitial = 'R';
    }

    if (function_exists('mb_strtoupper')) {
        $brandInitial = mb_strtoupper($brandInitial, 'UTF-8');
    } else {
        $brandInitial = strtoupper($brandInitial);
    }

    $escapedBrandInitial = htmlspecialchars($brandInitial, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $metadataLanguages = array_map(static fn ($section): string => $section['language'], $normalizedSections);

    $taglineValues = [];
    $footerValues = [];

    foreach ($metadataLanguages as $metadataLanguage) {
        $taglineDefault = $metadataLanguage === 'en'
            ? 'Reliable tracking of maritime unloading operations'
            : 'Monitoreo confiable de desembarques marítimos';
        $taglineValue = mail_translate('desembarques.email.tagline', [], $metadataLanguage, $taglineDefault);

        if ($taglineValue !== '' && ! in_array($taglineValue, $taglineValues, true)) {
            $taglineValues[] = $taglineValue;
        }

        $footerDefault = $metadataLanguage === 'en'
            ? 'You received this notification because your email is registered in the Unloading Registry.'
            : 'Recibiste esta notificación porque tu correo está registrado en Registro de Desembarques.';
        $footerValue = mail_translate('desembarques.email.footer.notice', [], $metadataLanguage, $footerDefault);

        if ($footerValue !== '' && ! in_array($footerValue, $footerValues, true)) {
            $footerValues[] = $footerValue;
        }
    }

    $escapedTaglineParts = array_map(
        static fn ($value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        $taglineValues
    );
    $escapedTagline = $escapedTaglineParts !== []
        ? implode(' / ', $escapedTaglineParts)
        : '';

    $escapedFooterParts = array_map(
        static fn ($value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        $footerValues
    );
    $footerHtml = $escapedFooterParts !== []
        ? '<div class="email-footer"><strong>' . $escapedBrandName . '</strong>' . implode('<br>', $escapedFooterParts) . '</div>'
        : '';

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="{$language}">
<head>
<meta charset="UTF-8">
<title>{$escapedSubject}</title>
<style>
    body {
        margin: 0;
        padding: 0;
        background: radial-gradient(circle at top left, rgba(14, 165, 233, 0.08), transparent 55%),
            radial-gradient(circle at bottom right, rgba(14, 116, 144, 0.12), transparent 60%),
            #f1f5f9;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        color: #0b1b3a;
        line-height: 1.7;
        -webkit-font-smoothing: antialiased;
    }

    .email-wrapper {
        padding: 40px 18px;
    }

    .email-container {
        max-width: 640px;
        margin: 0 auto;
        background-color: #ffffff;
        border-radius: 24px;
        padding: 72px 40px 40px 40px;
        border: 1px solid rgba(15, 23, 42, 0.08);
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
        position: relative;
        overflow: hidden;
        isolation: isolate;
    }

    .email-container::before {
        content: '';
        position: absolute;
        inset: 0;
        background: radial-gradient(circle at top right, rgba(14, 116, 144, 0.16), transparent 55%),
            radial-gradient(circle at bottom left, rgba(37, 99, 235, 0.14), transparent 60%);
        opacity: 0.85;
        z-index: -2;
    }

    .email-container::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(145deg, rgba(15, 23, 42, 0.04), rgba(15, 23, 42, 0));
        z-index: -1;
    }

    .email-ribbon {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 130px;
        background: linear-gradient(135deg, #0b3b60 0%, #0f4c81 35%, #1d9bf0 100%);
        z-index: -1;
    }

    .email-ribbon::after {
        content: '';
        position: absolute;
        bottom: -50px;
        left: 50%;
        width: 240px;
        height: 240px;
        transform: translateX(-50%);
        background: radial-gradient(circle, rgba(255, 255, 255, 0.45), transparent 65%);
        opacity: 0.65;
    }

    .email-emblem {
        width: 72px;
        height: 72px;
        margin: 0 auto 16px auto;
        border-radius: 24px;
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.28), rgba(255, 255, 255, 0.06));
        border: 1px solid rgba(255, 255, 255, 0.55);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #ffffff;
        font-weight: 700;
        font-size: 28px;
        backdrop-filter: blur(3px);
        box-shadow: 0 14px 28px rgba(15, 76, 129, 0.28);
    }

    .email-emblem-letter {
        text-transform: uppercase;
    }

    .email-accent {
        width: 90px;
        height: 5px;
        background: linear-gradient(135deg, #38bdf8, #1d9bf0);
        border-radius: 999px;
        margin: 0 auto 32px auto;
    }

    .email-header {
        text-align: center;
        position: relative;
        color: #0b1b3a;
    }

    .email-brand {
        font-weight: 700;
        font-size: 24px;
        letter-spacing: 0.5px;
    }

    .email-tagline {
        margin: 8px 0 0 0;
        font-size: 14px;
        color: #e2f3ff;
        opacity: 0.95;
    }

    .email-card {
        margin-top: 28px;
        background: #ffffff;
        border-radius: 20px;
        padding: 32px;
        border: 1px solid rgba(15, 23, 42, 0.06);
        box-shadow: 0 16px 35px rgba(15, 23, 42, 0.08);
        position: relative;
    }

    .email-card::before {
        content: '';
        position: absolute;
        inset: -1px;
        border-radius: inherit;
        border: 1px solid rgba(56, 189, 248, 0.25);
        pointer-events: none;
    }

    .email-divider {
        height: 2px;
        background: linear-gradient(90deg, rgba(56, 189, 248, 0), rgba(56, 189, 248, 0.6), rgba(56, 189, 248, 0));
        margin: 0 0 28px 0;
    }

    .email-body {
        color: #1f2a44;
        font-size: 15px;
    }

    .email-section + .email-section {
        margin-top: 28px;
    }

    .email-section-title {
        margin: 0 0 12px 0;
        font-weight: 600;
        font-size: 13px;
        letter-spacing: 0.4px;
        text-transform: uppercase;
        color: #0b3b60;
    }

    .email-section-content p {
        margin: 0 0 18px 0;
    }

    .email-section-content p:first-of-type {
        font-weight: 600;
        font-size: 18px;
        color: #0b1b3a;
    }

    .email-section-content p:last-of-type {
        margin-bottom: 0;
    }

    .email-section-content a {
        color: #0f4c81;
        text-decoration: none;
        font-weight: 600;
        border-bottom: 1px solid rgba(15, 76, 129, 0.35);
    }

    .email-section-content a:hover {
        color: #1d9bf0;
        border-bottom-color: rgba(29, 155, 240, 0.65);
    }

    .email-cta {
        margin: 32px 0 24px 0;
        text-align: center;
    }

    .email-button {
        display: inline-block;
        padding: 14px 32px;
        background: linear-gradient(135deg, #0f4c81, #1d9bf0);
        color: #ffffff;
        border-radius: 999px;
        font-weight: 700;
        letter-spacing: 0.3px;
        box-shadow: 0 16px 40px rgba(15, 76, 129, 0.35);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .email-button:hover {
        transform: translateY(-2px);
        box-shadow: 0 20px 45px rgba(29, 155, 240, 0.45);
    }

    .email-button:active {
        transform: translateY(0);
        box-shadow: 0 12px 28px rgba(29, 155, 240, 0.35);
    }

    .email-footer {
        font-size: 12px;
        color: #475569;
        margin-top: 36px;
        text-align: center;
        padding-top: 22px;
        border-top: 1px dashed rgba(148, 163, 184, 0.4);
    }

    .email-footer strong {
        display: block;
        margin-bottom: 6px;
        letter-spacing: 0.3px;
        color: #0b3b60;
    }

    @media (max-width: 560px) {
        .email-container {
            padding: 72px 22px 34px 22px;
            border-radius: 26px;
        }

        .email-card {
            padding: 26px 20px;
        }

        .email-section-content p {
            font-size: 14px;
        }

        .email-button {
            width: 100%;
            box-sizing: border-box;
        }
    }
</style>
</head>
<body>
<div class="email-wrapper">
    <div class="email-container">
        <div class="email-ribbon"></div>
        <div class="email-header">
            <div class="email-emblem"><span class="email-emblem-letter">{$escapedBrandInitial}</span></div>
            <div class="email-accent"></div>
            <div class="email-brand">{$escapedBrandName}</div>
            <p class="email-tagline">{$escapedTagline}</p>
        </div>
        <div class="email-card">
            <div class="email-divider"></div>
            <div class="email-body">
{$htmlSectionMarkup}
            </div>
        </div>
        {$footerHtml}
    </div>
</div>
</body>
</html>
HTML;

    $bodyParts = [
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        $textBody,
        '',
        '--' . $boundary,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        $htmlBody,
        '',
        '--' . $boundary . '--',
        '',
    ];

    $body = implode("\r\n", $bodyParts);

    return mail($recipientEmail, $encodedSubject, $body, implode("\r\n", $headers));
}

/**
 * Build password reset subject and lines for a specific language.
 *
 * @return array{subject:string, lines:list<string>}
 */
function buildPasswordResetEmailContent(
    string $recipientName,
    string $resetUrl,
    int $expiresInMinutes,
    string $language,
    string $appName
): array {
    $subject = mail_translate(
        'auth.password_reset.email.subject',
        ['app' => $appName],
        $language,
        $language === 'en'
            ? 'Reset your password — {{app}}'
            : 'Restablece tu contraseña — {{app}}'
    );

    $intro = mail_translate(
        'auth.password_reset.email.intro',
        [],
        $language,
        $language === 'en'
            ? 'We received a request to reset the password for your account.'
            : 'Recibimos una solicitud para restablecer la contraseña de tu cuenta.'
    );

    $action = mail_translate(
        'auth.password_reset.email.action',
        [],
        $language,
        $language === 'en'
            ? 'Click the link or copy it into your browser to continue:'
            : 'Haz clic en el enlace o cópialo en tu navegador para continuar:'
    );

    $expirationNotice = mail_translate(
        'auth.password_reset.email.expiration',
        ['minutes' => $expiresInMinutes],
        $language,
        $language === 'en'
            ? 'This link will expire in {{minutes}} minutes.'
            : 'Este enlace vencerá en {{minutes}} minutos.'
    );

    $outro = mail_translate(
        'auth.password_reset.email.outro',
        [],
        $language,
        $language === 'en'
            ? 'If you did not request the reset, you can ignore this email.'
            : 'Si no solicitaste el restablecimiento, puedes ignorar este correo.'
    );

    return [
        'subject' => $subject,
        'lines' => [
            buildMailGreeting($recipientName, $language),
            '',
            $intro,
            $action,
            $resetUrl,
            '',
            $expirationNotice,
            '',
            $outro,
            '',
            $appName,
        ],
    ];
}

/**
 * Send the password reset email to the given recipient.
 */
function sendPasswordResetEmail(
    string $recipientEmail,
    string $recipientName,
    string $token,
    int $expiresInMinutes,
    ?string $language = null
): bool {
    $language = normalizeMailLanguage($language);

    $recipientName = trim($recipientName);

    $primaryAppName = resolveMailAppName($language);
    $primaryResetUrl = buildPasswordResetUrl($token, $language);
    $primaryContent = buildPasswordResetEmailContent(
        $recipientName,
        $primaryResetUrl,
        $expiresInMinutes,
        $language,
        $primaryAppName
    );

    $alternateLanguage = $language === 'en' ? 'es' : 'en';
    $alternateSets = [];

    if ($alternateLanguage !== $language) {
        $alternateContent = buildPasswordResetEmailContent(
            $recipientName,
            buildPasswordResetUrl($token, $alternateLanguage),
            $expiresInMinutes,
            $alternateLanguage,
            resolveMailAppName($alternateLanguage)
        );

        $alternateSets[] = [
            'language' => $alternateLanguage,
            'lines' => $alternateContent['lines'],
        ];
    }

    return sendConfiguredPlainTextEmail(
        $recipientEmail,
        $primaryContent['subject'],
        $primaryContent['lines'],
        $language,
        $alternateSets
    );
}

/**
 * Build a localized greeting for email recipients.
 */
function buildMailGreeting(?string $recipientName, string $language): string
{
    $recipientName = trim((string) $recipientName);

    if ($recipientName !== '') {
        return mail_translate(
            'desembarques.email.greeting_named',
            ['name' => $recipientName],
            $language,
            $language === 'en' ? 'Hello {{name}},' : 'Hola {{name}},'
        );
    }

    return mail_translate(
        'desembarques.email.greeting_generic',
        [],
        $language,
        $language === 'en' ? 'Hello,' : 'Hola,'
    );
}

/**
 * Build the access instruction line for email bodies.
 */
function buildMailAccessLine(string $language): string
{
    $appUrl = resolveMailAppEntryUrl();

    if ($appUrl !== '') {
        return mail_translate(
            'desembarques.email.access_with_url',
            ['url' => $appUrl],
            $language,
            $language === 'en' ? 'Sign in here: {{url}}' : 'Ingresa al sistema: {{url}}'
        );
    }

    return mail_translate(
        'desembarques.email.access_generic',
        [],
        $language,
        $language === 'en' ? 'Sign in to review the details.' : 'Ingresa al sistema para revisar los detalles.'
    );
}

/**
 * Prepare status change subject and lines for a specific language.
 *
 * @return array{subject:string, lines:list<string>}
 */
function buildDesembarqueStatusChangeEmailContent(
    string $recipientName,
    string $reference,
    string $statusLabel,
    string $language
): array {
    $reference = trim($reference);
    $statusLabel = trim($statusLabel);

    $appName = resolveMailAppName($language);

    $referenceFallback = mail_translate(
        'desembarques.email.status_change.subject_fallback',
        [],
        $language,
        $language === 'en' ? 'unloading record' : 'desembarque'
    );

    $referenceForSubject = $reference !== '' ? $reference : $referenceFallback;

    $subject = mail_translate(
        'desembarques.email.status_change.subject',
        ['reference' => $referenceForSubject],
        $language,
        $language === 'en'
            ? 'Status update — {{reference}}'
            : 'Actualización de estado — {{reference}}'
    );

    $introReference = $reference !== '' ? $reference : $referenceFallback;

    $intro = mail_translate(
        'desembarques.email.status_change.intro',
        ['reference' => $introReference],
        $language,
        $language === 'en'
            ? 'The status of the unloading record "{{reference}}" has changed.'
            : 'El estado del desembarque "{{reference}}" ha cambiado.'
    );

    $statusValue = $statusLabel !== ''
        ? $statusLabel
        : mail_translate(
            'desembarques.email.status_change.unknown_status',
            [],
            $language,
            $language === 'en' ? 'Unknown status' : 'Estado desconocido'
        );

    $statusLine = mail_translate(
        'desembarques.email.status_change.status',
        ['status' => $statusValue],
        $language,
        $language === 'en'
            ? 'Current status: {{status}}.'
            : 'Estado actual: {{status}}.'
    );

    return [
        'subject' => $subject,
        'lines' => [
            buildMailGreeting($recipientName, $language),
            '',
            $intro,
            $statusLine,
            '',
            buildMailAccessLine($language),
            '',
            $appName,
        ],
    ];
}

/**
 * Prepare observation subject and lines for a specific language.
 *
 * @return array{subject:string, lines:list<string>}
 */
function buildDesembarqueObservationEmailContent(
    string $recipientName,
    string $reference,
    string $authorLabel,
    string $message,
    string $language
): array {
    $reference = trim($reference);
    $authorLabel = trim($authorLabel);
    $message = trim($message);

    $appName = resolveMailAppName($language);

    $referenceFallback = mail_translate(
        'desembarques.email.observation.subject_fallback',
        [],
        $language,
        $language === 'en' ? 'unloading record' : 'desembarque'
    );

    $referenceForSubject = $reference !== '' ? $reference : $referenceFallback;

    $subject = mail_translate(
        'desembarques.email.observation.subject',
        ['reference' => $referenceForSubject],
        $language,
        $language === 'en'
            ? 'New observation — {{reference}}'
            : 'Nueva observación — {{reference}}'
    );

    $introReference = $reference !== '' ? $reference : $referenceForSubject;

    $intro = mail_translate(
        'desembarques.email.observation.intro',
        ['reference' => $introReference],
        $language,
        $language === 'en'
            ? 'A new observation was added to the unloading record "{{reference}}".'
            : 'Se agregó una nueva observación al desembarque "{{reference}}".'
    );

    $authorValue = $authorLabel !== ''
        ? $authorLabel
        : mail_translate(
            'desembarques.email.observation.unknown_author',
            [],
            $language,
            $language === 'en' ? 'Unknown author' : 'Autor desconocido'
        );

    $authorLine = mail_translate(
        'desembarques.email.observation.author',
        ['author' => $authorValue],
        $language,
        $language === 'en'
            ? 'Author: {{author}}.'
            : 'Autor: {{author}}.'
    );

    $lines = [
        buildMailGreeting($recipientName, $language),
        '',
        $intro,
        $authorLine,
    ];

    if ($message !== '') {
        $messageHeading = mail_translate(
            'desembarques.email.observation.message',
            [],
            $language,
            $language === 'en' ? 'Message:' : 'Mensaje:'
        );

        $lines[] = '';
        $lines[] = $messageHeading;

        $messageLines = preg_split("/\r\n|\r|\n/", $message) ?: [];

        foreach ($messageLines as $messageLine) {
            $lines[] = (string) $messageLine;
        }
    }

    $lines[] = '';
    $lines[] = buildMailAccessLine($language);
    $lines[] = '';
    $lines[] = $appName;

    return [
        'subject' => $subject,
        'lines' => $lines,
    ];
}

/**
 * Notify a client about a status change on their unloading record.
 */
function sendDesembarqueStatusChangeEmail(
    string $recipientEmail,
    string $recipientName,
    string $reference,
    string $statusLabel,
    ?string $language = null
): bool {
    $language = normalizeMailLanguage($language);

    $primaryContent = buildDesembarqueStatusChangeEmailContent(
        $recipientName,
        $reference,
        $statusLabel,
        $language
    );

    $alternateLanguage = $language === 'en' ? 'es' : 'en';
    $alternateSets = [];

    if ($alternateLanguage !== $language) {
        $alternateContent = buildDesembarqueStatusChangeEmailContent(
            $recipientName,
            $reference,
            $statusLabel,
            $alternateLanguage
        );

        $alternateSets[] = [
            'language' => $alternateLanguage,
            'lines' => $alternateContent['lines'],
        ];
    }

    return sendConfiguredPlainTextEmail(
        $recipientEmail,
        $primaryContent['subject'],
        $primaryContent['lines'],
        $language,
        $alternateSets
    );
}

/**
 * Notify about a new observation created on an unloading record.
 */
function sendDesembarqueObservationEmail(
    string $recipientEmail,
    string $recipientName,
    string $reference,
    string $authorLabel,
    string $message,
    ?string $language = null
): bool {
    $language = normalizeMailLanguage($language);

    $primaryContent = buildDesembarqueObservationEmailContent(
        $recipientName,
        $reference,
        $authorLabel,
        $message,
        $language
    );

    $alternateLanguage = $language === 'en' ? 'es' : 'en';
    $alternateSets = [];

    if ($alternateLanguage !== $language) {
        $alternateContent = buildDesembarqueObservationEmailContent(
            $recipientName,
            $reference,
            $authorLabel,
            $message,
            $alternateLanguage
        );

        $alternateSets[] = [
            'language' => $alternateLanguage,
            'lines' => $alternateContent['lines'],
        ];
    }

    return sendConfiguredPlainTextEmail(
        $recipientEmail,
        $primaryContent['subject'],
        $primaryContent['lines'],
        $language,
        $alternateSets
    );
}
