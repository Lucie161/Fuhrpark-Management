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
// (nur_fuer_rolle()).
$nav = [
    'index.php'               => ['Übersicht',           ['mitarbeiter', 'fuhrparkleiter']],
    'fahrzeuge.php'           => ['Fahrzeuge',           ['fuhrparkleiter']],
    'schaeden.php'            => ['Schäden',             ['fuhrparkleiter']],
    'buchen.php'              => ['Fahrzeug buchen',     ['mitarbeiter']],
    'meine-buchungen.php'     => ['Meine Buchungen',     ['mitarbeiter']],
    'kalender.php'            => ['Kalender',            ['mitarbeiter', 'fuhrparkleiter']],
    'genehmigungen.php'       => ['Anträge genehmigen',  ['fuhrparkleiter']],
    'historie.php'            => ['Historie',            ['fuhrparkleiter']],
];

// Seiten ohne eigenen Navigationspunkt => Punkt, der für sie aktiv ist.
$navUnterseiten = [
    'fruehere-buchungen.php' => 'meine-buchungen.php',
    'rueckgabe.php'          => 'meine-buchungen.php',
];

$aktiveSeite = $navUnterseiten[basename($_SERVER['SCRIPT_NAME'])] ?? basename($_SERVER['SCRIPT_NAME']);

// Personenauswahl (nur Prototyp, siehe rolle.php): kehrt danach auf diese
// Seite zurück, samt Parametern wie dem Zeitraum der Historie.
$rollenwahlZurueck = basename($_SERVER['SCRIPT_NAME'])
    . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
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
                <?php if (in_array(aktuelle_rolle(), $navRollen, true)): ?>
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

    <!-- Personenauswahl, nur im Prototyp. Eigene Zeile, damit sie nicht wie
         ein Teil der Navigation wirkt und auch mobil sichtbar bleibt. -->
    <div class="rollenleiste">
        <form class="container rollenwahl" method="post" action="<?= url('rolle.php') ?>">
            <input type="hidden" name="zurueck" value="<?= e($rollenwahlZurueck) ?>">
            <label class="rollenwahl__label" for="rollenwahl">Angemeldet als (Test):</label>
            <select class="rollenwahl__auswahl" id="rollenwahl" name="nutzer">
                <?php foreach (nutzer_auswahl() as $wert => $text): ?>
                    <?php
                    $rollenwahlZusatz = beispiel_nutzer()[$wert]['rolle'] === 'fuhrparkleiter' ? 'Fuhrparkleiter' : 'Mitarbeiter';
                    $rollenwahlZusatz .= hat_ueberfaellige_rueckgabe($wert) ? ', Rückgabe überfällig' : '';
                    ?>
                    <option value="<?= e((string) $wert) ?>"<?= $wert === aktueller_nutzer() ? ' selected' : '' ?>><?= e($text . ' (' . $rollenwahlZusatz . ')') ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button button--klein rollenwahl__knopf" type="submit">Wechseln</button>
        </form>
    </div>
</header>

<main class="container">
    <h1 class="page-title"><?= e($pageTitle) ?></h1>

    <?php $meldung = hole_meldung(); ?>
    <?php if ($meldung !== null): ?>
        <p class="alert alert--<?= e($meldung['art']) ?>"><?= e($meldung['text']) ?></p>
    <?php endif; ?>
