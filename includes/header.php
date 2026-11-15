<?php
/**
 * Kopfbereich jeder Seite: <head>, Kopfleiste mit Navigation, Seitentitel.
 *
 * Die aufrufende Seite kann vorher setzen:
 *   $pageTitle        Titel der Seite, z. B. 'Fahrzeuge'
 *   $pageZusatz       kurzer Text neben dem Titel, z. B. das Datum
 *   $pageAktion       ['Beschriftung', 'datei.php'] für einen Knopf rechts
 *                     neben dem Titel, optional mit Icon als drittem Wert,
 *                     z. B. ['Fahrzeug buchen', 'buchen.php', 'plus']
 *   $pageZurueck      ['Beschriftung', 'datei.php'] für einen Link über dem
 *                     Titel, z. B. ['Meine Buchungen', 'meine-buchungen.php']
 *   $pageMitte        true: Titel und Inhalt mittig (Anmeldung)
 *   $bannerFahrten         überfällige Fahrten für die rote Meldung (includes/banner.php)
 *   $bannerEntscheidungen  ungelesene Ablehnungen und Stornierungen (ebenda)
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
    'analyse.php'             => ['Analyse',             [ROLLE_FUHRPARKLEITER]],
];

// Seiten ohne eigenen Navigationspunkt => Punkt, der für sie aktiv ist.
$navUnterseiten = [
    'fruehere-buchungen.php' => 'meine-buchungen.php',
    'rueckgabe.php'          => 'meine-buchungen.php',
];

$aktiveSeite = $navUnterseiten[basename($_SERVER['SCRIPT_NAME'])] ?? basename($_SERVER['SCRIPT_NAME']);

// Name des Angemeldeten rechts in der Kopfleiste
$Angemeldet = is_logged_in() ? CNutzer::LoadByID(aktueller_nutzer()) : null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> &ndash; <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= url('assets/img/app-icon.png') ?>">
    <link rel="apple-touch-icon" href="<?= url('assets/img/app-icon.png') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <!-- Mit JavaScript klappt die Navigation auf dem Handy erst auf Knopfdruck auf. -->
    <script>document.documentElement.classList.add('js');</script>
</head>
<body>

<header class="kopfleiste">
    <div class="container kopfleiste__innen">
        <a class="logo" href="<?= url('index.php') ?>">
            <img class="logo__zeichen" src="<?= url('assets/img/app-icon.png') ?>" alt="" width="28" height="28">
            <?= e(APP_NAME) ?>
        </a>

        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="hauptnavigation" aria-label="Menü">
            <span class="icon icon--menue" aria-hidden="true"></span>
        </button>

        <nav id="hauptnavigation" class="nav">
            <?php foreach ($nav as $datei => [$beschriftung, $navRollen]): ?>
                <?php if (is_logged_in() && in_array(aktuelle_rolle(), $navRollen, true)): ?>
                    <a class="nav__link<?= $datei === $aktiveSeite ? ' nav__link--aktiv' : '' ?>"
                       href="<?= url($datei) ?>"<?= $datei === $aktiveSeite ? ' aria-current="page"' : '' ?>><?= e($beschriftung) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="nav__nutzer">
                <?php if ($Angemeldet !== null): ?>
                    <span class="nav__name"><?= e($Angemeldet->GetName()) ?></span>
                    <a class="nav__link nav__link--klein" href="<?= url('login.php?logout=1') ?>">
                        <span class="icon icon--abmelden" aria-hidden="true"></span>Abmelden
                    </a>
                <?php else: ?>
                    <a class="nav__link<?= is_current('login.php') ? ' nav__link--aktiv' : '' ?>"
                       href="<?= url('login.php') ?>">Anmelden</a>
                <?php endif; ?>
            </div>
        </nav>
    </div>
</header>

<?php if (($bannerFahrten ?? []) !== [] || ($bannerEntscheidungen ?? []) !== []): ?>
    <?php require __DIR__ . '/banner.php'; ?>
<?php endif; ?>

<main class="container seite<?= ($pageMitte ?? false) ? ' seite--mitte' : '' ?>">
    <?php if (isset($pageZurueck)): ?>
        <p class="seitenkopf__zurueck">
            <a href="<?= url($pageZurueck[1]) ?>"><span class="icon icon--links" aria-hidden="true"></span><?= e($pageZurueck[0]) ?></a>
        </p>
    <?php endif; ?>

    <div class="seitenkopf">
        <h1 class="seitenkopf__titel"><?= e($pageTitle) ?></h1>

        <?php if (isset($pageZusatz)): ?>
            <p class="seitenkopf__zusatz"><?= e($pageZusatz) ?></p>
        <?php endif; ?>

        <?php if (isset($pageAktion)): ?>
            <a class="button seitenkopf__aktion" href="<?= url($pageAktion[1]) ?>">
                <?php if (isset($pageAktion[2])): ?>
                    <span class="icon icon--<?= e($pageAktion[2]) ?>" aria-hidden="true"></span>
                <?php endif; ?>
                <?= e($pageAktion[0]) ?>
            </a>
        <?php endif; ?>
    </div>

    <?php $meldung = hole_meldung(); ?>
    <?php if ($meldung !== null): ?>
        <p class="alert alert--<?= e($meldung['art']) ?>">
            <?= e($meldung['text']) ?>
            <?php if (($meldung['link'] ?? null) !== null): ?>
                <a class="alert__link" href="<?= url($meldung['link'][1]) ?>"><?= e($meldung['link'][0]) ?></a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
