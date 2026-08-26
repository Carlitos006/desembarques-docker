<?php
/**
 * Shared premium navigation bar.
 *
 * Expected variables:
 * - string $currentLanguage
 * - string $currentTheme
 * - string $themeLabel
 * - string $themeLightLabel
 * - string $themeDarkLabel
 * - string $themeToggleAnnouncement
 * - string $navProfileLabel
 * - string $navProfileValue
 * - array<int, array<string, mixed>> $navLinks
 */

$currentLanguageValue = isset($currentLanguage) ? (string) $currentLanguage : 'es';
$currentThemeValue = isset($currentTheme) ? (string) $currentTheme : 'light';
$isEnglishNav = strtolower($currentLanguageValue) === 'en';

$navProfileLabelEscaped = htmlspecialchars((string) ($navProfileLabel ?? ''), ENT_QUOTES, 'UTF-8');
$navProfileValueEscaped = htmlspecialchars((string) ($navProfileValue ?? ''), ENT_QUOTES, 'UTF-8');
$themeLabelEscaped = htmlspecialchars((string) ($themeLabel ?? ''), ENT_QUOTES, 'UTF-8');
$themeLightLabelEscaped = htmlspecialchars((string) ($themeLightLabel ?? ''), ENT_QUOTES, 'UTF-8');
$themeDarkLabelEscaped = htmlspecialchars((string) ($themeDarkLabel ?? ''), ENT_QUOTES, 'UTF-8');
$themeToggleAnnouncementEscaped = htmlspecialchars((string) ($themeToggleAnnouncement ?? ''), ENT_QUOTES, 'UTF-8');
$toggleNavigationLabelEscaped = htmlspecialchars(
    translate('dashboard.nav.toggle', [], $currentLanguageValue),
    ENT_QUOTES,
    'UTF-8'
);

$currentScriptName = '';
if (! empty($_SERVER['SCRIPT_NAME'])) {
    $currentScriptName = basename((string) $_SERVER['SCRIPT_NAME']);
} elseif (! empty($_SERVER['PHP_SELF'])) {
    $currentScriptName = basename((string) $_SERVER['PHP_SELF']);
}

/** @return string */
$navBasename = static function (string $href): string {
    $path = parse_url($href, PHP_URL_PATH);
    return is_string($path) && $path !== '' ? basename($path) : '';
};

/** @return bool */
$isActiveHref = static function (string $href) use ($navBasename, $currentScriptName): bool {
    $basename = $navBasename($href);
    return $basename !== '' && $currentScriptName !== '' && $basename === $currentScriptName;
};

$visibleLinks = [];
foreach ((isset($navLinks) && is_array($navLinks)) ? $navLinks : [] as $navLink) {
    if (! (bool) ($navLink['visible'] ?? true)) {
        continue;
    }

    $href = trim((string) ($navLink['href'] ?? '#'));
    $label = trim((string) ($navLink['label'] ?? ''));
    if ($label === '') {
        continue;
    }

    $visibleLinks[] = [
        'href' => $href,
        'label' => $label,
        'class' => (string) ($navLink['class'] ?? ''),
        'basename' => $navBasename($href),
        'active' => $isActiveHref($href),
    ];
}

$sessionRole = trim((string) ($_SESSION['user']['role'] ?? ''));
$hasTrashLink = false;
foreach ($visibleLinks as $visibleLink) {
    if (($visibleLink['basename'] ?? '') === 'aviso-papelera.php') {
        $hasTrashLink = true;
        break;
    }
}
if ($sessionRole === 'admin' && ! $hasTrashLink) {
    $trashHref = 'aviso-papelera.php';
    $visibleLinks[] = [
        'href' => $trashHref,
        'label' => $isEnglishNav ? 'Deleted notices' : 'Avisos eliminados',
        'class' => '',
        'basename' => $navBasename($trashHref),
        'active' => $isActiveHref($trashHref),
    ];
}

$primaryRoutes = [
    'dashboard-avisos.php',
    'index.php',
    'desembarque-nuevo.php',
    'client-portal.php',
    'reportes.php',
    'analytics.php',
];
$operationsRoutes = [
    'aviso-importar.php',
    'control_tower.php',
];
$adminRoutes = [
    'clients.php',
    'statuses.php',
    'users.php',
    'audit_logs.php',
    'aviso-papelera.php',
];
$accountRoutes = [
    'myprofile.php',
    'logout.php',
];

$primaryLinks = [];
$operationsLinks = [];
$adminLinks = [];
$accountLinks = [];
$moreLinks = [];

foreach ($visibleLinks as $link) {
    $route = $link['basename'];
    if (in_array($route, $accountRoutes, true)) {
        $accountLinks[] = $link;
    } elseif (in_array($route, $adminRoutes, true)) {
        $adminLinks[] = $link;
    } elseif (in_array($route, $operationsRoutes, true)) {
        $operationsLinks[] = $link;
    } elseif (in_array($route, $primaryRoutes, true)) {
        $primaryLinks[] = $link;
    } else {
        $moreLinks[] = $link;
    }
}

$hasActiveOperations = false;
foreach ($operationsLinks as $link) {
    $hasActiveOperations = $hasActiveOperations || $link['active'];
}
$hasActiveAdmin = false;
foreach ($adminLinks as $link) {
    $hasActiveAdmin = $hasActiveAdmin || $link['active'];
}
$hasActiveMore = false;
foreach ($moreLinks as $link) {
    $hasActiveMore = $hasActiveMore || $link['active'];
}

$profileLink = null;
$logoutLink = null;
foreach ($accountLinks as $link) {
    if ($link['basename'] === 'logout.php') {
        $logoutLink = $link;
    } elseif ($link['basename'] === 'myprofile.php') {
        $profileLink = $link;
    }
}

$profileInitialSource = trim((string) ($navProfileValue ?? ''));
$profileInitial = $profileInitialSource !== ''
    ? strtoupper(function_exists('mb_substr') ? mb_substr($profileInitialSource, 0, 1, 'UTF-8') : substr($profileInitialSource, 0, 1))
    : 'U';
$profileInitialEscaped = htmlspecialchars($profileInitial, ENT_QUOTES, 'UTF-8');
$hasProfileInfo = $navProfileLabelEscaped !== '' || $navProfileValueEscaped !== '';

$operationsLabel = $isEnglishNav ? 'Operations' : 'Operación';
$adminLabel = $isEnglishNav ? 'Administration' : 'Administración';
$moreLabel = $isEnglishNav ? 'More' : 'Más';
$accountLabel = $isEnglishNav ? 'Account' : 'Cuenta';

$mobileModeConfig = null;
if (isset($mobileModeToggleConfig) && is_array($mobileModeToggleConfig)) {
    $mobileModeConfig = [
        'label' => htmlspecialchars((string) ($mobileModeToggleConfig['label'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'on_text' => htmlspecialchars((string) ($mobileModeToggleConfig['on_text'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'off_text' => htmlspecialchars((string) ($mobileModeToggleConfig['off_text'] ?? ''), ENT_QUOTES, 'UTF-8'),
    ];
}

/** Render a dropdown item. */
$renderDropdownLink = static function (array $link, bool $danger = false): void {
    $classes = 'dropdown-item app-navbar-dropdown-item';
    if ($link['active']) {
        $classes .= ' active';
    }
    if ($danger) {
        $classes .= ' app-navbar-dropdown-danger';
    }
    ?>
    <li>
        <a
            class="<?= htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') ?>"
            href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"
            <?php if ($link['active']): ?>aria-current="page"<?php endif; ?>
        ><?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?></a>
    </li>
    <?php
};
?>
<nav class="navbar navbar-expand-xl app-navbar app-navbar-premium sticky-top" aria-label="Navegación principal">
    <div class="container-fluid app-navbar-shell">
        <a class="navbar-brand app-navbar-brand" href="index.php">
            <span class="app-navbar-brand-icon" aria-hidden="true">🛳️</span>
            <span class="app-navbar-brand-copy">
                <span class="app-navbar-brand-text"><?= htmlspecialchars(translate('app.name', [], $currentLanguageValue), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="app-navbar-brand-subtitle"><?= $isEnglishNav ? 'Unloading operations' : 'Operación de desembarques' ?></span>
            </span>
        </a>

        <button
            class="navbar-toggler app-navbar-toggler ms-auto"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#appNavbarCollapse"
            aria-controls="appNavbarCollapse"
            aria-expanded="false"
            aria-label="<?= $toggleNavigationLabelEscaped ?>"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse app-navbar-collapse" id="appNavbarCollapse">
            <div class="app-navbar-layout">
                <div class="app-navbar-primary" aria-label="Secciones principales">
                    <?php foreach ($primaryLinks as $link): ?>
                        <?php
                            $isPrimaryCta = $link['basename'] === 'desembarque-nuevo.php';
                            $linkClass = 'app-navbar-link';
                            if ($link['active']) {
                                $linkClass .= ' active';
                            }
                            if ($isPrimaryCta && ! $link['active']) {
                                $linkClass .= ' app-navbar-link-cta';
                            }
                        ?>
                        <a
                            class="<?= htmlspecialchars($linkClass, ENT_QUOTES, 'UTF-8') ?>"
                            href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"
                            <?php if ($link['active']): ?>aria-current="page"<?php endif; ?>
                        ><?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endforeach; ?>
                </div>

                <div class="app-navbar-tools">
                    <?php if ($operationsLinks !== []): ?>
                        <div class="dropdown app-navbar-dropdown">
                            <button class="app-navbar-tool dropdown-toggle<?= $hasActiveOperations ? ' active' : '' ?>" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <?= htmlspecialchars($operationsLabel, ENT_QUOTES, 'UTF-8') ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end app-navbar-menu">
                                <li class="app-navbar-menu-title"><?= htmlspecialchars($operationsLabel, ENT_QUOTES, 'UTF-8') ?></li>
                                <?php foreach ($operationsLinks as $link) { $renderDropdownLink($link); } ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($adminLinks !== []): ?>
                        <div class="dropdown app-navbar-dropdown">
                            <button class="app-navbar-tool dropdown-toggle<?= $hasActiveAdmin ? ' active' : '' ?>" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <?= htmlspecialchars($adminLabel, ENT_QUOTES, 'UTF-8') ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end app-navbar-menu">
                                <li class="app-navbar-menu-title"><?= htmlspecialchars($adminLabel, ENT_QUOTES, 'UTF-8') ?></li>
                                <?php foreach ($adminLinks as $link) { $renderDropdownLink($link); } ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($moreLinks !== []): ?>
                        <div class="dropdown app-navbar-dropdown">
                            <button class="app-navbar-tool dropdown-toggle<?= $hasActiveMore ? ' active' : '' ?>" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <?= htmlspecialchars($moreLabel, ENT_QUOTES, 'UTF-8') ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end app-navbar-menu">
                                <?php foreach ($moreLinks as $link) { $renderDropdownLink($link); } ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($mobileModeConfig !== null): ?>
                        <div class="mobile-mode-selector">
                            <button
                                type="button"
                                class="app-navbar-icon-button mobile-mode-toggle"
                                data-mobile-mode-toggle="true"
                                data-mobile-mode-label="<?= $mobileModeConfig['label'] ?>"
                                data-mobile-mode-on-text="<?= $mobileModeConfig['on_text'] ?>"
                                data-mobile-mode-off-text="<?= $mobileModeConfig['off_text'] ?>"
                                aria-label="<?= $mobileModeConfig['on_text'] ?>"
                                aria-pressed="false"
                                title="<?= $mobileModeConfig['on_text'] ?>"
                            ><span aria-hidden="true">📱</span><span class="visually-hidden mobile-mode-toggle-text"><?= $mobileModeConfig['on_text'] ?></span></button>
                        </div>
                    <?php endif; ?>

                    <div class="theme-selector">
                        <button
                            type="button"
                            class="app-navbar-icon-button theme-toggle"
                            data-theme-toggle
                            data-theme-label="<?= $themeLabelEscaped ?>"
                            data-theme-light-text="<?= $themeLightLabelEscaped ?>"
                            data-theme-dark-text="<?= $themeDarkLabelEscaped ?>"
                            aria-label="<?= $themeToggleAnnouncementEscaped ?>"
                            aria-pressed="<?= $currentThemeValue === 'dark' ? 'true' : 'false' ?>"
                            title="<?= $themeToggleAnnouncementEscaped ?>"
                        >
                            <span class="theme-icon" aria-hidden="true"><?= $currentThemeValue === 'dark' ? '🌙' : '☀️' ?></span>
                            <span class="visually-hidden theme-toggle-label"><?= $themeToggleAnnouncementEscaped ?></span>
                        </button>
                    </div>

                    <?php if ($hasProfileInfo || $profileLink !== null || $logoutLink !== null): ?>
                        <div class="dropdown app-navbar-account-dropdown">
                            <button class="app-navbar-account dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= htmlspecialchars($accountLabel, ENT_QUOTES, 'UTF-8') ?>">
                                <span class="app-navbar-avatar" aria-hidden="true"><?= $profileInitialEscaped ?></span>
                                <?php if ($hasProfileInfo): ?>
                                    <span class="app-navbar-profile-copy">
                                        <?php if ($navProfileLabelEscaped !== ''): ?><span class="app-navbar-profile-label"><?= $navProfileLabelEscaped ?></span><?php endif; ?>
                                        <?php if ($navProfileValueEscaped !== ''): ?><span class="app-navbar-profile-value"><?= $navProfileValueEscaped ?></span><?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end app-navbar-menu app-navbar-account-menu">
                                <?php if ($hasProfileInfo): ?>
                                    <li class="app-navbar-account-summary">
                                        <?php if ($navProfileLabelEscaped !== ''): ?><span><?= $navProfileLabelEscaped ?></span><?php endif; ?>
                                        <?php if ($navProfileValueEscaped !== ''): ?><strong><?= $navProfileValueEscaped ?></strong><?php endif; ?>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                <?php endif; ?>
                                <?php if ($profileLink !== null) { $renderDropdownLink($profileLink); } ?>
                                <?php if ($profileLink !== null && $logoutLink !== null): ?><li><hr class="dropdown-divider"></li><?php endif; ?>
                                <?php if ($logoutLink !== null) { $renderDropdownLink($logoutLink, true); } ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</nav>
