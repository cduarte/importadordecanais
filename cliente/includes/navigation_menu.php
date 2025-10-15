<?php
$navItems = [
    'canais' => [
        'label' => 'Importar Canais',
        'path' => '',
        'icon' => 'fa-tv',
    ],
    'filmes' => [
        'label' => 'Importar Filmes',
        'path' => 'filmes',
        'icon' => 'fa-film',
    ],
    'series' => [
        'label' => 'Importar Séries',
        'path' => 'series',
        'icon' => 'fa-layer-group',
    ],
    'dividir_m3u' => [
        'label' => 'Dividir M3U',
        'path' => 'dividir_m3u/',
        'icon' => 'fa-scissors',
    ],
    'edit_m3u' => [
        'label' => 'Editar M3U',
        'path' => 'edit_m3u/',
        'icon' => 'fa-pen-to-square',
    ],
];

if (!isset($currentNavKey) || !array_key_exists($currentNavKey, $navItems)) {
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');

    foreach ($navItems as $key => $navItem) {
        if ($navItem['path'] === $currentScript) {
            $currentNavKey = $key;
            break;
        }
    }

    if (!isset($currentNavKey) || !array_key_exists($currentNavKey, $navItems)) {
        $currentNavKey = array_key_first($navItems);
    }
}

$currentPageLabel = $navItems[$currentNavKey]['label'] ?? 'Menu';
?>
<header class="nav-container">
    <div class="nav-bar">
        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="navDrawer">
            <span class="sr-only">Alternar navegação</span>
            <span class="icon icon-hamburger"><i class="fas fa-bars"></i></span>
            <span class="icon icon-close"><i class="fas fa-times"></i></span>
        </button>
        <span class="nav-title"><?= htmlspecialchars($currentPageLabel, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="nav-overlay"></div>
    <nav class="navigation nav-drawer" id="navDrawer">
        <?php foreach ($navItems as $key => $navItem):
            $url = $buildLocalUrl($navItem['path']);
            $isActive = $key === $currentNavKey;
        ?>
            <a class="nav-link<?= $isActive ? ' active' : '' ?>" href="<?= htmlspecialchars($url === '' ? '/' : $url, ENT_QUOTES, 'UTF-8'); ?>"<?= $isActive ? ' aria-current="page"' : ''; ?>>
                <span class="nav-link-icon">
                    <i class="fas <?= htmlspecialchars($navItem['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
                </span>
                <span class="nav-link-text"><?= htmlspecialchars($navItem['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</header>
