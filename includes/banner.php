<?php
/**
 * Meldungen über dem Seitentitel (index.php, meine-buchungen.php,
 * rueckgabe.php, kalender.php). header.php bindet sie ein.
 *
 * Rot, sobald eine Rückgabe überfällig ist. Fuhrparkleiter: die Anzahl, die
 * Liste mit Fahrern erst hinter „Details“, ohne JavaScript. Mitarbeiter: je
 * eigene Fahrt eine Meldung mit „Zurückgeben“.
 *
 * Weiß, nur Mitarbeiter: je Ablehnung oder automatischer Stornierung eine
 * Benachrichtigung mit Link zur Buchung und, solange der Zeitraum nicht vorbei
 * ist, zur Suche nach einem Ersatzfahrzeug, bis sie mit „Gelesen“ weggeklickt
 * wird (POST an index.php).
 *
 * Erwartet von der einbindenden Seite:
 *   $bannerFahrten         überfällige Fahrten (CBuchung), die älteste zuerst
 *   $bannerMitNamen        true für den Fuhrparkleiter
 *   $bannerEntscheidungen  optional: ungelesene Entscheidungen (CBuchung)
 *   $bannerOhneKnopf       optional: ID der Fahrt, die gerade zurückgegeben
 *                          wird; sie bekommt keinen Knopf „Zurückgeben“
 */

declare(strict_types=1);

$bannerHeute = new DateTimeImmutable('today');
?>
<div class="container">
    <?php if ($bannerFahrten !== [] && $bannerMitNamen): ?>
        <?php $bannerAnzahl = count($bannerFahrten); ?>
        <details class="banner">
            <summary class="banner__zeile">
                <span class="banner__text">
                    <span class="icon icon--warnung" aria-hidden="true"></span><strong><?= e((string) $bannerAnzahl) ?> <?= $bannerAnzahl === 1 ? 'Rückgabe' : 'Rückgaben' ?> überfällig.</strong>
                    <span class="banner__zusatz">Bitte mit den Fahrern klären.</span>
                </span>
                <span class="button button--klein banner__knopf banner__knopf--details">Details</span>
            </summary>

            <ul class="banner__liste">
                <?php foreach ($bannerFahrten as $Fahrt): ?>
                    <?php $tage = $Fahrt->GetEnde()->diff($bannerHeute)->days; ?>
                    <li>
                        <strong><?= e($Fahrt->GetFahrerName()) ?></strong>
                        <a href="<?= url('fahrzeug.php?id=' . $Fahrt->GetFahrzeugID()) ?>"><?= e($Fahrt->GetFahrzeugName()) ?></a>
                        <span>fällig am <?= e($Fahrt->GetEnde()->format('d.m.Y')) ?>, seit <?= e((string) $tage) ?> <?= $tage === 1 ? 'Tag' : 'Tagen' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php elseif ($bannerFahrten !== []): ?>
        <?php foreach ($bannerFahrten as $Fahrt): ?>
            <?php $BannerFahrzeug = CFahrzeug::LoadByID($Fahrt->GetFahrzeugID()); ?>
            <div class="banner banner__zeile">
                <p class="banner__text">
                    <span class="icon icon--warnung" aria-hidden="true"></span><strong>Rückgabe überfällig:</strong>
                    <?= e($BannerFahrzeug->GetHersteller() . ' ' . $BannerFahrzeug->GetModell()) ?>, fällig am <?= e($Fahrt->GetEnde()->format('d.m.Y')) ?>.
                    <span class="banner__zusatz">Buchen und neue Fahrten sind bis dahin gesperrt.</span>
                </p>
                <?php if ($Fahrt->GetID() !== ($bannerOhneKnopf ?? null)): ?>
                    <a class="button button--klein banner__knopf" href="<?= url('rueckgabe.php?buchung=' . $Fahrt->GetID()) ?>"><span class="icon icon--zurueck" aria-hidden="true"></span>Zurückgeben</a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php foreach ($bannerEntscheidungen ?? [] as $Entscheidung): ?>
        <?php
        $abgelehnt = $Entscheidung->GetStatusID() === STATUS_ABGELEHNT;
        $BannerFahrzeug = CFahrzeug::LoadByID($Entscheidung->GetFahrzeugID());

        // Überschrift je Grund der Absage
        $bannerTitel = match ($Entscheidung->GetEntscheidungKommentar()) {
            CWartung::STORNO_GRUND             => 'Wegen Wartung storniert:',
            CFahrzeug::STORNO_AUSGEMUSTERT     => 'Fahrzeug ausgemustert, storniert:',
            CBuchung::STORNO_NICHT_ANGETRETEN  => 'Nicht angetreten, storniert:',
            CBuchung::STORNO_NICHT_ENTSCHIEDEN => 'Antrag verfallen:',
            default                            => 'Antrag abgelehnt:',
        };
        ?>
        <div class="banner banner--hinweis banner--<?= $abgelehnt ? 'abgelehnt' : 'storniert' ?> banner__zeile">
            <p class="banner__text">
                <strong><?= e($bannerTitel) ?></strong>
                <?= e($BannerFahrzeug->GetHersteller() . ' ' . $BannerFahrzeug->GetModell()) ?>, <?= e(zeitraum_kurz($Entscheidung->GetStart(), $Entscheidung->GetEnde())) ?>.
                <?php if ($abgelehnt): ?>
                    <span class="banner__zusatz">„<?= e($Entscheidung->GetEntscheidungKommentar()) ?>“</span>
                <?php endif; ?>
                <a href="<?= url('fruehere-buchungen.php#buchung-' . $Entscheidung->GetID()) ?>">Zur Buchung</a>
                <?php $ersatz = ersatz_link($Entscheidung, aktueller_nutzer()); ?>
                <?php if ($ersatz !== null): ?>
                    &middot; <a href="<?= e(url($ersatz)) ?>">Ersatzfahrzeug buchen</a>
                <?php endif; ?>
            </p>
            <form method="post" action="<?= url('index.php') ?>">
                <input type="hidden" name="aktion" value="gelesen">
                <input type="hidden" name="buchung" value="<?= e((string) $Entscheidung->GetID()) ?>">
                <button class="button button--klein banner__knopf" type="submit">Gelesen</button>
            </form>
        </div>
    <?php endforeach; ?>
</div>
