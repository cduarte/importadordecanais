<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/form_page_setup.php';

$context = importador_bootstrap_form_context('series', $_POST);
extract($context, EXTR_SKIP);

require __DIR__ . '/templates/import_form.php';
