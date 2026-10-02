<?php
/**
 * Kopfbereich jeder Seite: <head>, Navigation, Öffnen der Layout-Container.
 *
 * Die aufrufende Seite kann vorher $pageTitle setzen:
 *   $pageTitle = 'Fahrzeuge';
 *   require_once __DIR__ . '/includes/header.php';
 */

declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Übersicht';

// Navigationspunkte: Dateiname => Beschriftung
// TODO: 'genehmigungen.php' und 'fahrzeuge-verwalten.php' nur für den
// Fuhrparkleiter anzeigen, sobald Rollen existieren.
$nav = [
    'index.php'               => 'Übersicht',
    'fahrzeuge.php'           => 'Fahrzeuge',
    'buchen.php'              => 'Fahrzeug buchen',
    'meine-buchungen.php'     => 'Meine Buchungen',
    'rueckgabe.php'           => 'Zurückgeben',
    'genehmigungen.php'       => 'Anträge genehmigen',
    'fahrzeuge-verwalten.php' => 'Fahrzeuge verwalten',
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> &ndash; <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body>

<header class="site-header">
    <div class="container site-header__inner">
        <a class="logo" href="<?= url('index.php') ?>"><?= e(APP_NAME) ?></a>

        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="hauptnavigation">
            Menü
        </button>

        <nav id="hauptnavigation" class="nav">
            <?php foreach ($nav as $datei => $beschriftung): ?>
                <a class="nav__link<?= is_current($datei) ? ' nav__link--active' : '' ?>"
                   href="<?= url($datei) ?>"><?= e($beschriftung) ?></a>
            <?php endforeach; ?>

            <?php if (is_logged_in()): ?>
                <a class="nav__link nav__link--right" href="<?= url('login.php?logout=1') ?>">Abmelden</a>
            <?php else: ?>
                <a class="nav__link nav__link--right<?= is_current('login.php') ? ' nav__link--active' : '' ?>"
                   href="<?= url('login.php') ?>">Anmelden</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="container">
    <h1 class="page-title"><?= e($pageTitle) ?></h1>
