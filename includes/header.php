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

// Navigationspunkte: Dateiname => [Beschriftung, Rollen, die ihn sehen]. Der
// Fuhrparkleiter bucht nicht selbst, daher fehlen ihm Buchen und Meine
// Buchungen. Die Seiten sind zusätzlich für die andere Rolle gesperrt
// (nur_fuer_rolle()). Ohne Anmeldung gibt es nur den Punkt „Anmelden“.
$nav = [
    'index.php'               => ['Übersicht',           [ROLLE_MITARBEITER, ROLLE_FUHRPARKLEITER]],
    'fahrzeuge.php'           => ['Fahrzeuge',           [ROLLE_FUHRPARKLEITER]],
    'schaeden.php'            => ['Schäden',             [ROLLE_FUHRPARKLEITER]],
    'buchen.php'              => ['Fahrzeug buchen',     [ROLLE_MITARBEITER]],
    'meine-buchungen.php'     => ['Meine Buchungen',     [ROLLE_MITARBEITER]],
    'kalender.php'            => ['Kalender',            [ROLLE_MITARBEITER, ROLLE_FUHRPARKLEITER]],
    'genehmigungen.php'       => ['Anträge genehmigen',  [ROLLE_FUHRPARKLEITER]],
    'historie.php'            => ['Historie',            [ROLLE_FUHRPARKLEITER]],
];

// Seiten ohne eigenen Navigationspunkt => Punkt, der für sie aktiv ist.
$navUnterseiten = [
    'fruehere-buchungen.php' => 'meine-buchungen.php',
    'rueckgabe.php'          => 'meine-buchungen.php',
];

$aktiveSeite = $navUnterseiten[basename($_SERVER['SCRIPT_NAME'])] ?? basename($_SERVER['SCRIPT_NAME']);
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
            <?php foreach ($nav as $datei => [$beschriftung, $navRollen]): ?>
                <?php if (is_logged_in() && in_array(aktuelle_rolle(), $navRollen, true)): ?>
                    <a class="nav__link<?= $datei === $aktiveSeite ? ' nav__link--active' : '' ?>"
                       href="<?= url($datei) ?>"><?= e($beschriftung) ?></a>
                <?php endif; ?>
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

    <?php $meldung = hole_meldung(); ?>
    <?php if ($meldung !== null): ?>
        <p class="alert alert--<?= e($meldung['art']) ?>"><?= e($meldung['text']) ?></p>
    <?php endif; ?>
