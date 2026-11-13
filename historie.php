<?php
/**
 * Historie: Fahrtenbuch aller Fahrzeuge, nur für den Fuhrparkleiter.
 *
 * Nur die Liste, ohne Kennzahlen; ausgewertet wird im Dashboard
 * (dashboard.php). Über der Tabelle eine Suche und die Filter als
 * GET-Formular, damit sich eine Auswahl als Link weitergeben lässt.
 *
 * Aufruf: historie.php, mit Zeitraum historie.php?von=2026-09-01&bis=2026-09-30,
 * gefiltert mit &auswahl=art:1 (Kategorie, ID der Fahrzeugart) oder &auswahl=id:3
 * (ein Fahrzeug), mit &fahrer=2 (ein Mitarbeiter) und mit &suche=Transit (Text
 * in Fahrzeug, Fahrer, Zweck oder Beschreibung); alles lässt sich kombinieren.
 *
 * Wartungen stehen als eigene Zeilen zwischen den Fahrten, wenn sie den
 * Zeitraum berühren; &nur_fahrten=1 blendet sie aus, ebenso der Filter nach
 * einem Mitarbeiter (eine Wartung hat keinen Fahrer).
 *
 * Es gibt kein eigenes Fahrtenbuch: Jede Buchung im Status „abgeschlossen“ ist
 * ein Eintrag. Er entsteht automatisch bei der Rückgabe. Eine Fahrt zählt zu
 * dem Tag, an dem sie zurückgegeben wurde.
 *
 * Ausgeschiedene Mitarbeiter und ausgemusterte Fahrzeuge bleiben in Liste und
 * Filter, mit einem Zusatz im Namen: Die Fahrten haben stattgefunden.
 *
 * Daten: Zeitraum und Filter aus includes/auswertung-filter.php, die Fahrten
 * aus CListeBuchungen::LadenHistorie(), ihre Schäden aus
 * CListeSchaeden::LadenRueckgabenImZeitraum(), die Wartungen aus
 * CListeWartungen::LadenImZeitraum().
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle(ROLLE_FUHRPARKLEITER);

$pageTitle = 'Historie';

$Zwecke = new CListeSchluessel('zwecke');

// Zeitraum und Filter; ohne Angabe vom Ersten des Vormonats bis heute
$filterStandardVon = (new DateTimeImmutable('today'))->modify('first day of last month');
require __DIR__ . '/includes/auswertung-filter.php';

// Suche und „nur Fahrten“ gibt es nur hier
$wert = $_GET['suche'] ?? '';
$suche = is_string($wert) ? trim($wert) : '';
$nurFahrten = ($_GET['nur_fahrten'] ?? '') === '1';

// Ob etwas vom Standard abweicht: dann gibt es „Filter zurücksetzen“.
$gefiltert = $listeGefiltert || $nurFahrten || $suche !== ''
    || $eingabe['von'] !== $filterStandardVon->format('Y-m-d')
    || $eingabe['bis'] !== $heute->format('Y-m-d');

// --- Laden ------------------------------------------------------------------
// Alle Fahrten im Zeitraum mit ihren Schäden. Bei einem Fehler in der Eingabe
// bleibt alles leer.

$ListeFahrten = new CListeBuchungen();
$ListeSchaeden = new CListeSchaeden();
$ListeWartungen = new CListeWartungen();

if ($fehler === []) {
    $ListeFahrten->LadenHistorie($von, $bis);
    $ListeSchaeden->LadenRueckgabenImZeitraum($von, $bis);

    if (!$nurFahrten && $fahrerFilter === null) {
        $ListeWartungen->LadenImZeitraum($von, $bis);
    }
}

// --- Filtern und suchen -----------------------------------------------------
// Fahrten: Auswahl, Mitarbeiter und Suchbegriff. Wartungen: Auswahl und
// Suchbegriff.

$fahrtenliste = array_values(array_filter(
    $ListeFahrten->GetAlle(),
    function (CBuchung $Fahrt) use ($eingabe, $Fahrzeuge, $fahrerFilter, $suche, $Zwecke, $ListeSchaeden): bool {
        $Schaden = $ListeSchaeden->GetBeiRueckgabe($Fahrt->GetID());
        $text = [
            $Fahrt->GetFahrzeugName(),
            $Fahrt->GetFahrerName(),
            $Zwecke->GetBezeichnung($Fahrt->GetZweckID()),
            $Fahrt->GetBemerkung(),
            $Schaden !== null ? $Schaden->GetBeschreibung() : '',
        ];

        return passt_zur_auswahl($Fahrt->GetFahrzeugID(), $eingabe['auswahl'], $Fahrzeuge)
            && ($fahrerFilter === null || $Fahrt->GetFahrerID() === $fahrerFilter)
            && passt_zur_suche($suche, $text);
    }
));

$wartungsliste = array_values(array_filter(
    $ListeWartungen->GetAlle(),
    function (CWartung $Wartung) use ($eingabe, $Fahrzeuge, $suche): bool {
        $text = [$Fahrzeuge->GetByID($Wartung->GetFahrzeugID())->GetName(), 'Wartung', $Wartung->GetGrund()];

        return passt_zur_auswahl($Wartung->GetFahrzeugID(), $eingabe['auswahl'], $Fahrzeuge)
            && passt_zur_suche($suche, $text);
    }
));

// Fahrten und Wartungen in einer Liste, nach dem Ende sortiert (Rückgabe bzw.
// Freigabe), eine laufende Wartung ganz oben.
$eintraege = [];

foreach ($fahrtenliste as $Fahrt) {
    $eintraege[] = ['art' => 'fahrt', 'datum' => $Fahrt->GetZurueckgegebenAm(), 'daten' => $Fahrt];
}

foreach ($wartungsliste as $Wartung) {
    $eintraege[] = ['art' => 'wartung', 'datum' => $Wartung->GetFreigegebenAm() ?? $heute->modify('+1 day'), 'daten' => $Wartung];
}

usort($eintraege, fn (array $a, array $b): int => $b['datum'] <=> $a['datum']);

// Spalten der Tabelle: Überschrift und CSS-Klasse. Der Zustand steht hinter
// den Kennwerten, damit er auf dem Handy rechts neben dem Kennzeichen sitzt
// (.table--stapel). Die km-Köpfe sind kurz, damit acht Spalten auf die Seite
// passen; auf dem Handy heißen die Felder ausführlich „km-Stand …“.
$spalten = [
    ['Fahrzeug',     ''],
    ['Datum',        ''],
    ['Fahrer',       ''],
    ['Zweck',        ''],
    ['km Anfang',    'table__num'],
    ['km Ende',      'table__num'],
    ['Zustand',      ''],
    ['Beschreibung', 'historie-tabelle__beschreibung'],
];

/**
 * Kommt der Suchbegriff in einem der Texte vor? Groß- und Kleinschreibung
 * zählt nicht; ohne Suchbegriff passt alles.
 */
function passt_zur_suche(string $suche, array $texte): bool
{
    if ($suche === '') {
        return true;
    }

    foreach ($texte as $text) {
        if ($text !== null && mb_stripos($text, $suche) !== false) {
            return true;
        }
    }

    return false;
}

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    Fahrtenbuch aller Fahrzeuge: abgeschlossene Fahrten mit km-Ständen, gemeldeten Schäden und
    Bemerkungen, dazu die Wartungen. Eine Fahrt zählt zum Tag ihrer Rückgabe. Auswertungen stehen
    im <a href="<?= url('dashboard.php') ?>">Dashboard</a>.
</p>

<?php
// Suche und Filter in einer Leiste
$filterZiel = 'historie.php';
require __DIR__ . '/includes/auswertung-filterleiste.php';
?>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php else: ?>

    <!-- Fahrten und Wartungen, neueste zuerst -->
    <section aria-labelledby="fahrten">
        <?php $anzahl = count($fahrtenliste); ?>
        <h2 class="abschnitt" id="fahrten">
            Fahrtenbuch
            <span class="abschnitt__wert">
                <?= e((string) $anzahl) ?> <?= $anzahl === 1 ? 'Fahrt' : 'Fahrten' ?>
            </span>
            <span class="abschnitt__zusatz">
                <?= e(zeitraum_kurz($von, $bis)) ?>
                <?php if ($wartungsliste !== []): ?>
                    &middot; dazu <?= e((string) count($wartungsliste)) ?> <?= count($wartungsliste) === 1 ? 'Wartung' : 'Wartungen' ?>
                <?php endif; ?>
                <?php if ($suche !== ''): ?>
                    &middot; Suche „<?= e($suche) ?>“
                <?php endif; ?>
                &middot; neueste zuerst
            </span>
        </h2>

        <table class="table table--stapel historie-tabelle">
            <thead>
                <tr>
                    <?php foreach ($spalten as [$titel, $klasse]): ?>
                        <th<?= $klasse !== '' ? ' class="' . e($klasse) . '"' : '' ?>><?= e($titel) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eintraege as $eintrag): ?>
                    <?php $Fahrzeug = $Fahrzeuge->GetByID($eintrag['daten']->GetFahrzeugID()); ?>
                    <tr>
                        <td class="table__fahrzeug">
                            <a href="<?= url('fahrzeug.php?id=' . $Fahrzeug->GetID()) ?>#fahrtenbuch"><?= e($Fahrzeug->GetHersteller() . ' ' . $Fahrzeug->GetModell()) ?></a>
                            <span class="table__zusatz"><?= e($Fahrzeug->GetKennzeichen()) ?></span>
                        </td>

                        <?php if ($eintrag['art'] === 'wartung'): ?>
                            <?php
                            // Wartung: kein Fahrer, kein Zweck, keine km; der Grund steht in der Beschreibung
                            $Wartung = $eintrag['daten'];
                            $laeuft = $Wartung->IstLaufend();
                            ?>
                            <td class="historie-tabelle__datum" data-label="Datum">
                                <?php if ($laeuft): ?>
                                    seit <?= e($Wartung->GetBegonnenAm()->format('d.m.Y')) ?>
                                <?php else: ?>
                                    <?= e(zeitraum_kurz(nur_tag($Wartung->GetBegonnenAm()), nur_tag($Wartung->GetFreigegebenAm()))) ?>
                                <?php endif; ?>
                            </td>
                            <td class="table__leer">&ndash;</td>
                            <td class="table__leer">&ndash;</td>
                            <td class="table__num table__leer">&ndash;</td>
                            <td class="table__num table__leer">&ndash;</td>
                            <td class="table__stand" data-label="Zustand">
                                <?php if ($laeuft): ?>
                                    <span class="marke marke--gelb">In Wartung</span>
                                <?php else: ?>
                                    <span class="marke marke--grau">Wartung</span>
                                <?php endif; ?>
                            </td>
                            <td class="table__breit" data-label="Beschreibung">
                                <?= e($Wartung->GetGrund()) ?>
                                <?php if ($laeuft): ?>
                                    <span class="table__zusatz">läuft, <?= e($Fahrzeug->GetWartungText()) ?></span>
                                <?php else: ?>
                                    <span class="table__zusatz">freigegeben am <?= e($Wartung->GetFreigegebenAm()->format('d.m.Y')) ?></span>
                                <?php endif; ?>
                            </td>
                        <?php else: ?>
                            <?php
                            // Fahrt: Zustand bei der Rückgabe aus Schaden und Bemerkung
                            $Fahrt = $eintrag['daten'];
                            $Schaden = $ListeSchaeden->GetBeiRueckgabe($Fahrt->GetID());
                            $bemerkung = $Fahrt->GetBemerkung();

                            $marke = match (true) {
                                $Schaden !== null && $Schaden->GetSchwereID() === SCHWERE_WARTUNG => ['Schaden mit Wartung', 'gelb'],
                                $Schaden !== null => ['Kleinschaden', 'grau'],
                                $bemerkung !== '' => ['Bemerkung', 'grau'],
                                default           => null,
                            };
                            ?>
                            <td class="historie-tabelle__datum" data-label="Datum"><?= e(zeitraum_kurz(nur_tag($Fahrt->GetStart()), nur_tag($Fahrt->GetZurueckgegebenAm()))) ?></td>
                            <td data-label="Fahrer"><?= e($Fahrt->GetFahrerName()) ?></td>
                            <td class="historie-tabelle__rechts" data-label="Zweck"><?= e($Zwecke->GetBezeichnung($Fahrt->GetZweckID())) ?></td>
                            <td class="table__num" data-label="km-Stand Anfang"><?= e(km_text($Fahrt->GetKmStart())) ?></td>
                            <td class="table__num historie-tabelle__rechts" data-label="km-Stand Ende"><?= e(km_text($Fahrt->GetKmEnde())) ?></td>

                            <?php if ($marke === null): ?>
                                <td class="table__leer">&ndash;</td>
                                <td class="table__leer">&ndash;</td>
                            <?php else: ?>
                                <td class="table__stand" data-label="Zustand">
                                    <span class="marke marke--<?= e($marke[1]) ?>"><?= e($marke[0]) ?></span>
                                </td>
                                <td class="table__breit" data-label="Beschreibung">
                                    <?php if ($Schaden !== null): ?>
                                        <?= e($Schaden->GetBeschreibung()) ?>
                                        <?php if ($bemerkung !== ''): ?>
                                            <span class="table__zusatz">Bemerkung: <?= e($bemerkung) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?= e($bemerkung) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>

                <?php if ($eintraege === []): ?>
                    <tr>
                        <td colspan="<?= e((string) count($spalten)) ?>" class="table__empty">
                            <?= $suche !== '' ? 'Nichts gefunden für diese Suche im Zeitraum.' : 'Keine abgeschlossenen Fahrten für diese Auswahl im Zeitraum.' ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
