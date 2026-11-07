<?php
/**
 * Fahrzeug-Steckbrief für beide Rollen.
 *
 * Aufruf: fahrzeug.php?id=1
 *
 * Stammdaten, noch nicht behobene Schäden, Belegung der nächsten 14 Tage und
 * darunter das Fahrtenbuch des Fahrzeugs mit Summe der km und Anzahl der
 * Fahrten. Den Fahrer sieht nur der Fuhrparkleiter, im Fahrtenbuch wie in der
 * Belegung; für Mitarbeiter wird der Name gar nicht erst abgefragt
 * (Datenschutz). Fahrzeuge ohne km-Stand (Fahrrad)
 * zeigen im Fahrtenbuch keine km-Spalten.
 *
 * Ausgemusterte Fahrzeuge (geloescht_am gesetzt) sind über das Fahrtenbuch
 * weiter erreichbar, aber ohne Belegung und Buchen.
 *
 * Daten: CFahrzeug::LoadByID() mit laufender Wartung, die Belegung aus
 * CListeBuchungen::LadenBelegung() und die Fahrten aus
 * CListeBuchungen::LadenFahrtenbuch() (Namen jeweils nur für den
 * Fuhrparkleiter), die Wartungen im Fahrtenbuch aus
 * CListeWartungen::LadenZuFahrzeug(), die Schäden aus CListeSchaeden.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_angemeldet();

$fuhrparkleiter = ist_fuhrparkleiter();

$Fahrzeugarten = new CListeSchluessel('fahrzeugarten');
$Antriebe = new CListeSchluessel('antriebe');
$Zwecke = new CListeSchluessel('zwecke');
$Schweregrade = new CListeSchluessel('schweregrade');
$Anlaesse = new CListeSchluessel('schadensanlaesse');

// Angezeigter Fahrzeugstatus => Beschriftung (wie in fahrzeuge.php); zugleich
// CSS-Klasse (.badge--wartung usw.), siehe CFahrzeug::GetStand().
$statusText = [
    'verfuegbar'   => 'verfügbar',
    'unterwegs'    => 'unterwegs',
    'wartung'      => 'in Wartung',
    'ausgemustert' => 'ausgemustert',
];

// --- Fahrzeug ermitteln -----------------------------------------------------

$id = (int) ($_GET['id'] ?? 0);
$Fahrzeug = CFahrzeug::LoadByID($id);

if ($Fahrzeug === null) {
    http_response_code(404);
}

$pageTitle = $Fahrzeug !== null
    ? $Fahrzeug->GetHersteller() . ' ' . $Fahrzeug->GetModell()
    : 'Fahrzeug nicht gefunden';

$heute = new DateTimeImmutable('today');

$ausgemustert = $Fahrzeug !== null && $Fahrzeug->IstAusgemustert();

// Typabhängige Merkmale: Beschriftung => Wert, in Anzeigereihenfolge.
// Ein Fahrzeug zeigt nur die Felder, die für seine Art belegt sind, z. B.
// keine HU/AU und keinen km-Stand beim Fahrrad.
$merkmale = [];

if ($Fahrzeug !== null) {
    // Felder ohne Wert stehen als null in der Liste; array_filter entfernt
    // sie am Ende.
    $merkmale = array_filter([
        'Kennzeichen'   => $Fahrzeug->GetKennzeichen(),
        'Fahrzeugart'   => $Fahrzeugarten->GetBezeichnung($Fahrzeug->GetArtID()),
        'Typ'           => $Fahrzeug->GetTyp(),
        'Baujahr'       => $Fahrzeug->GetBaujahr() !== null ? (string) $Fahrzeug->GetBaujahr() : null,
        'Sitzplätze'    => (string) $Fahrzeug->GetSitzplaetze(),
        'Antrieb'       => $Fahrzeug->GetAntriebID() !== null ? $Antriebe->GetBezeichnung($Fahrzeug->GetAntriebID()) : null,
        'km-Stand'      => $Fahrzeug->GetKmStand() !== null ? km_text($Fahrzeug->GetKmStand()) . ' km' : null,
        'Führerschein'  => $Fahrzeug->GetFuehrerschein() !== null ? 'Klasse ' . $Fahrzeug->GetFuehrerschein() : null,
        'Nächste HU/AU' => $Fahrzeug->GetHuAu()?->format('m/Y'),
    ], fn (?string $wert): bool => $wert !== null);
}

// --- Belegung der nächsten 14 Tage ------------------------------------------
// Je Tag: 'frei', 'vergeben' oder 'wartung'. In Wartung sind die Tage bis zum
// voraussichtlichen Ende; ist das Ende offen, alle Tage (wie im Kalender).
// Ein Tag ist vergeben, wenn eine beantragte, genehmigte oder laufende
// Buchung ihn einschließt; eine überfällige Rückgabe belegt heute. Fahrer und
// Zweck stehen nur für den Fuhrparkleiter im Hinweis (title-Attribut).

$belegung = [];

if ($Fahrzeug !== null && !$ausgemustert) {
    // Belegende Buchungen der 14 Tage laden (für alle Fahrzeuge) und auf
    // dieses Fahrzeug beschränken. Namen nur für den Fuhrparkleiter.
    $ListeBelegung = new CListeBuchungen();
    $ListeBelegung->LadenBelegung($heute, $heute->modify('+13 day'), $fuhrparkleiter);

    $buchungen = array_filter(
        $ListeBelegung->GetAlle(),
        fn (CBuchung $Buchung): bool => $Buchung->GetFahrzeugID() === $id
    );

    // Tag für Tag den Zustand bestimmen
    for ($tag = 0; $tag < 14; $tag++) {
        // Wartung geht vor: An diesen Tagen zählt keine Buchung.
        $datum = $heute->modify("+$tag day");
        $inWartung = $Fahrzeug->SperrtWartung($datum, $datum);
        $eintrag = [
            'datum'   => $datum,
            'zustand' => $inWartung ? 'wartung' : 'frei',
            'hinweis' => $inWartung ? 'in Wartung' : 'frei',
        ];

        // Sonst die erste Buchung suchen, die den Tag einschließt. Eine
        // überfällige Fahrt (unterwegs, Ende vorbei) belegt nur heute, denn
        // wann sie zurückkommt, weiß niemand.
        if (!$inWartung) {
            foreach ($buchungen as $Buchung) {
                $belegt = $Buchung->GetStart() <= $datum
                    && ($datum <= $Buchung->GetEnde() || ($Buchung->GetStatusID() === STATUS_UNTERWEGS && $tag === 0));

                if ($belegt) {
                    $eintrag['zustand'] = 'vergeben';
                    $eintrag['hinweis'] = $fuhrparkleiter
                        ? $Buchung->GetFahrerName() . ': ' . $Zwecke->GetBezeichnung($Buchung->GetZweckID())
                        : 'vergeben';
                    break;
                }
            }
        }

        $belegung[] = $eintrag;
    }
}

$wochentage = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

$zustandText = [
    'frei'     => 'frei',
    'vergeben' => 'vergeben',
    'wartung'  => 'in Wartung',
];

// --- Schäden ----------------------------------------------------------------
// Noch nicht behobene Schäden, für beide Rollen ohne Namen. Schäden aus der
// Rückgabe stehen außerdem im Fahrtenbuch an der jeweiligen Fahrt.

$ListeOffene = new CListeSchaeden();
$ListeOffene->LadenOffene($id);

$ListeSchaeden = new CListeSchaeden();
$ListeSchaeden->LadenZuFahrzeug($id);

// --- Fahrtenbuch des Fahrzeugs ----------------------------------------------
// Den Fahrer fragt die Abfrage nur für den Fuhrparkleiter ab; für Mitarbeiter
// liefert GetFahrerName() null.

$ListeFahrten = new CListeBuchungen();
$ListeFahrten->LadenFahrtenbuch($id, $fuhrparkleiter);

// Ohne km-Stand (Fahrrad) entfallen die km-Spalten und die Summe.
$mitKm = $Fahrzeug !== null && $Fahrzeug->GetKmStand() !== null;

$summeKm = 0;
foreach ($ListeFahrten->GetAlle() as $Fahrt) {
    $summeKm += $Fahrt->GetKmEnde() !== null ? $Fahrt->GetKmEnde() - $Fahrt->GetKmStart() : 0;
}

// Wartungen stehen als eigene Zeilen zwischen den Fahrten. Sie zählen nicht
// zu den Fahrten und km. Sortiert wird nach dem Ende (Rückgabe bzw. Freigabe),
// eine laufende Wartung steht ganz oben.
$ListeWartungen = new CListeWartungen();
$ListeWartungen->LadenZuFahrzeug($id);

$eintraege = [];

foreach ($ListeFahrten->GetAlle() as $Fahrt) {
    $eintraege[] = ['art' => 'fahrt', 'datum' => $Fahrt->GetZurueckgegebenAm(), 'daten' => $Fahrt];
}

foreach ($ListeWartungen->GetAlle() as $Wartung) {
    $eintraege[] = ['art' => 'wartung', 'datum' => $Wartung->GetFreigegebenAm() ?? $heute->modify('+1 day'), 'daten' => $Wartung];
}

usort($eintraege, fn (array $a, array $b): int => $b['datum'] <=> $a['datum']);

// Spalten des Fahrtenbuchs: Datum, Zweck, Zustand; dazu Fahrer (nur
// Fuhrparkleiter) und zwei km-Spalten.
$anzahlSpalten = 3 + ($fuhrparkleiter ? 1 : 0) + ($mitKm ? 2 : 0);

// Bild aus assets/img/, sonst Platzhalter.
$bildDatei = $Fahrzeug?->GetBild();
$hatBild = $bildDatei !== null && is_file(__DIR__ . '/assets/img/' . $bildDatei);

// Zurück zur Fahrzeugliste der Rolle: fahrzeuge.php ist für Mitarbeiter
// gesperrt, sie wählen Fahrzeuge in buchen.php.
$zurueckZurListe = $fuhrparkleiter
    ? ['datei' => 'fahrzeuge.php', 'text' => 'Alle Fahrzeuge']
    : ['datei' => 'buchen.php',    'text' => 'Zur Fahrzeugauswahl'];

/**
 * Kilometer mit Tausenderpunkt, Strich ohne km-Stand.
 */
function km_text(?int $km): string
{
    return $km === null ? '–' : number_format($km, 0, ',', '.');
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($Fahrzeug === null): ?>

    <p class="alert">Zu dieser Angabe gibt es kein Fahrzeug.</p>
    <p><a href="<?= url($zurueckZurListe['datei']) ?>"><?= e($zurueckZurListe['text']) ?></a></p>

<?php else: ?>

    <?php $stand = $Fahrzeug->GetStand(); ?>

    <p><a href="<?= url($zurueckZurListe['datei']) ?>">&larr; <?= e($zurueckZurListe['text']) ?></a></p>

    <section class="steckbrief">
        <?php if ($hatBild): ?>
            <img class="steckbrief__bild" src="<?= url('assets/img/' . $bildDatei) ?>"
                 alt="<?= e($pageTitle) ?>">
        <?php else: ?>
            <div class="steckbrief__bild steckbrief__bild--platzhalter"><?= e($Fahrzeug->GetTyp()) ?></div>
        <?php endif; ?>

        <div class="steckbrief__daten">
            <p>
                <span class="badge badge--<?= e($stand) ?>">
                    <?= e($statusText[$stand]) ?>
                </span>
                <?php if ($Fahrzeug->IstUeberfaellig()): ?>
                    <span class="badge badge--abgelehnt">Rückgabe überfällig</span>
                <?php endif; ?>
            </p>

            <dl class="merkmale">
                <?php foreach ($merkmale as $beschriftung => $wert): ?>
                    <dt><?= e($beschriftung) ?></dt>
                    <dd><?= e($wert) ?></dd>
                <?php endforeach; ?>
            </dl>

            <?php if ($Fahrzeug->IstLuxus() && !$ausgemustert): ?>
                <p class="note">
                    Luxusauto: Fahren dürfen es nur freigegebene Mitarbeiter, und schon ein
                    Kleinschaden sperrt es bis zur Reparatur.
                    <?php if (!$fuhrparkleiter && $Fahrzeug->FehltFreigabe(CNutzer::LoadByID(aktueller_nutzer()))): ?>
                        Sie sind dafür nicht freigegeben, können es aber für einen freigegebenen
                        Kollegen buchen.
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if ($ausgemustert): ?>
                <p class="note">
                    Ausgemustert am <?= e($Fahrzeug->GetGeloeschtAm()->format('d.m.Y')) ?>. Das Fahrzeug kann
                    nicht mehr gebucht werden; das Fahrtenbuch bleibt erhalten.
                </p>
            <?php elseif ($Fahrzeug->IstWartungEndeOffen()): ?>
                <p class="note">
                    Das Fahrzeug ist in Wartung. Wann es wieder gebucht werden kann, steht noch nicht
                    fest.
                </p>
            <?php elseif ($Fahrzeug->IstInWartung()): ?>
                <?php $buchbarAb = $Fahrzeug->GetWartungBis()->modify('+1 day'); ?>
                <p class="note">
                    Das Fahrzeug ist in Wartung, voraussichtlich bis
                    <?= e($Fahrzeug->GetWartungBis()->format('d.m.Y')) ?>. Danach kann es wieder gebucht werden.
                </p>
                <?php if (!$fuhrparkleiter): ?>
                    <a class="button" href="<?= url('buchen.php?' . http_build_query(['fahrzeug' => $id, 'beginn' => $buchbarAb->format('Y-m-d')])) ?>">Ab <?= e($buchbarAb->format('d.m.')) ?> buchen</a>
                <?php endif; ?>
            <?php elseif (!$fuhrparkleiter): ?>
                <a class="button" href="<?= url('buchen.php?fahrzeug=' . $id) ?>">Dieses Fahrzeug buchen</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="section">
        <h2>Offene Schäden</h2>

        <?php if ($ListeOffene->Count() === 0): ?>
            <p class="note">Keine bekannten Schäden.</p>
        <?php else: ?>
            <ul class="schadensliste">
                <?php foreach ($ListeOffene->GetAlle() as $Schaden): ?>
                    <li>
                        <span class="badge<?= $Schaden->GetSchwereID() === SCHWERE_WARTUNG ? ' badge--abgelehnt' : '' ?>">
                            <?= e($Schweregrade->GetBezeichnung($Schaden->GetSchwereID())) ?>
                        </span>
                        <?= e($Schaden->GetBeschreibung()) ?>
                        <span class="table__zusatz">
                            gemeldet am <?= e($Schaden->GetGemeldetAm()->format('d.m.Y')) ?>
                            <?= e($Anlaesse->GetBezeichnung($Schaden->GetAnlassID())) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if (!$ausgemustert): ?>
    <section class="section">
        <h2>Belegung der nächsten 14 Tage</h2>

        <ol class="belegung">
            <?php foreach ($belegung as $tag): ?>
                <li class="belegung__tag belegung__tag--<?= e($tag['zustand']) ?>"
                    title="<?= e($tag['hinweis']) ?>">
                    <span class="belegung__wochentag"><?= e($wochentage[(int) $tag['datum']->format('w')]) ?></span>
                    <span class="belegung__datum"><?= e($tag['datum']->format('d.m.')) ?></span>
                    <span class="belegung__zustand"><?= e($zustandText[$tag['zustand']]) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
    <?php endif; ?>

    <section class="section" id="fahrtenbuch">
        <h2>Fahrtenbuch</h2>

        <?php if ($eintraege !== []): ?>
            <p class="lead">
                <?= e((string) $ListeFahrten->Count()) ?> <?= $ListeFahrten->Count() === 1 ? 'Fahrt' : 'Fahrten' ?><?php if ($mitKm): ?>,
                    zusammen <?= e(km_text($summeKm)) ?> km<?php endif; ?><?php if ($ListeWartungen->Count() > 0): ?>,
                    dazu <?= e((string) $ListeWartungen->Count()) ?> <?= $ListeWartungen->Count() === 1 ? 'Wartung' : 'Wartungen' ?><?php endif; ?>.
                Neueste zuerst.
            </p>
        <?php endif; ?>

        <table class="table">
            <thead>
                <tr>
                    <th>Datum</th>
                    <?php if ($fuhrparkleiter): ?>
                        <th>Fahrer</th>
                    <?php endif; ?>
                    <th>Zweck</th>
                    <?php if ($mitKm): ?>
                        <th class="table__num">km-Stand Anfang</th>
                        <th class="table__num">km-Stand Ende</th>
                    <?php endif; ?>
                    <th>Zustand</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eintraege as $eintrag): ?>
                    <?php if ($eintrag['art'] === 'wartung'): ?>
                        <?php $Wartung = $eintrag['daten']; ?>
                        <tr class="table__zeile--wartung">
                            <td>
                                <?php if (!$Wartung->IstLaufend()): ?>
                                    <?= e(zeitraum_text($Wartung->GetBegonnenAm(), $Wartung->GetFreigegebenAm())) ?>
                                <?php else: ?>
                                    seit <?= e($Wartung->GetBegonnenAm()->format('d.m.Y')) ?>
                                <?php endif; ?>
                            </td>
                            <?php if ($fuhrparkleiter): ?>
                                <td>&ndash;</td>
                            <?php endif; ?>
                            <td>Wartung</td>
                            <?php if ($mitKm): ?>
                                <td class="table__num">&ndash;</td>
                                <td class="table__num">&ndash;</td>
                            <?php endif; ?>
                            <td>
                                <?= e($Wartung->GetGrund()) ?>
                                <?php if ($Wartung->IstLaufend()): ?>
                                    <span class="table__zusatz">läuft, <?= e($Fahrzeug->GetWartungText()) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php continue; ?>
                    <?php endif; ?>

                    <?php
                    $Fahrt = $eintrag['daten'];
                    $Schaden = $ListeSchaeden->GetBeiRueckgabe($Fahrt->GetID());
                    ?>
                    <tr>
                        <td><?= e(zeitraum_text($Fahrt->GetStart(), $Fahrt->GetZurueckgegebenAm())) ?></td>
                        <?php if ($fuhrparkleiter): ?>
                            <td><?= e($Fahrt->GetFahrerName()) ?></td>
                        <?php endif; ?>
                        <td><?= e($Zwecke->GetBezeichnung($Fahrt->GetZweckID())) ?></td>
                        <?php if ($mitKm): ?>
                            <td class="table__num"><?= e(km_text($Fahrt->GetKmStart())) ?></td>
                            <td class="table__num"><?= e(km_text($Fahrt->GetKmEnde())) ?></td>
                        <?php endif; ?>
                        <td>
                            <?php if ($Schaden !== null): ?>
                                <span class="badge<?= $Schaden->GetSchwereID() === SCHWERE_WARTUNG ? ' badge--abgelehnt' : '' ?>"><?= e($Schweregrade->GetBezeichnung($Schaden->GetSchwereID())) ?></span>
                                <span class="table__zusatz"><?= e($Schaden->GetBeschreibung()) ?></span>
                            <?php elseif ($Fahrt->GetBemerkung() !== ''): ?>
                                <span class="badge">Bemerkung</span>
                            <?php else: ?>
                                &ndash;
                            <?php endif; ?>

                            <?php if ($Fahrt->GetBemerkung() !== ''): ?>
                                <span class="table__zusatz"><?= e($Fahrt->GetBemerkung()) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($eintraege === []): ?>
                    <tr>
                        <td colspan="<?= e((string) $anzahlSpalten) ?>" class="table__empty">Noch keine Fahrten erfasst.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
