<?php
/**
 * Fahrzeug-Steckbrief für beide Rollen.
 *
 * Aufruf: fahrzeug.php?id=1
 *
 * Oben das Fahrzeug als großes Schild (der Name steht im Titel): Kopf mit
 * Kennzeichen und Stand, Bild und Merkmale als Kennwertfelder, rechts die Aktion
 * („Dieses Fahrzeug buchen“ für Mitarbeiter, das Wartungsformular für den
 * Fuhrparkleiter), darunter Hinweise zu Wartung und Luxusauto. Danach die
 * noch nicht behobenen Schäden, die Belegung der nächsten 14 Tage als Reihe
 * von Tagesfeldern und, nur für den Fuhrparkleiter, das Fahrtenbuch als
 * Tabelle mit den gefahrenen km je Fahrt, der Anzahl der Fahrten und der
 * Summe der km.
 *
 * Mitarbeiter sehen kein Fahrtenbuch und in der Belegung keine Fahrer; ihre
 * Namen werden gar nicht erst abgefragt (Datenschutz). Fotos von Schäden zeigt
 * die Seite nicht, die sieht der Fuhrparkleiter in schaeden.php. Fahrzeuge
 * ohne km-Stand (Fahrrad) zeigen im Fahrtenbuch keine km-Spalten.
 *
 * Ausgemusterte Fahrzeuge (geloescht_am gesetzt) sind über das Fahrtenbuch
 * weiter erreichbar, aber ohne Belegung und Buchen.
 *
 * Daten: CFahrzeug::LoadByID() mit laufender Wartung, die Belegung aus
 * CListeBuchungen::LadenBelegung() und die Fahrten aus
 * CListeBuchungen::LadenFahrtenbuch() (nur für den Fuhrparkleiter), die
 * Wartungen aus CListeWartungen::LadenZuFahrzeug(), die offenen Schäden aus
 * CListeSchaeden.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_angemeldet();

$fuhrparkleiter = ist_fuhrparkleiter();

$Fahrzeugarten = new CListeSchluessel('fahrzeugarten');
$Antriebe = new CListeSchluessel('antriebe');
$Zwecke = new CListeSchluessel('zwecke');
$Anlaesse = new CListeSchluessel('schadensanlaesse');

// Stand eines Fahrzeugs (CFahrzeug::GetStand()) => [Wort, Farbe] für das
// Feld „Stand“, wie in fahrzeuge.php
$standMarke = [
    'verfuegbar'   => ['Frei', 'gruen'],
    'unterwegs'    => ['Unterwegs', 'grau'],
    'wartung'      => ['In Wartung', 'gelb'],
    'ausgemustert' => ['Ausgemustert', 'grau'],
];

// Zurück zur Fahrzeugliste der Rolle: fahrzeuge.php ist für Mitarbeiter
// gesperrt, sie wählen Fahrzeuge in buchen.php.
$pageZurueck = $fuhrparkleiter
    ? ['Alle Fahrzeuge', 'fahrzeuge.php']
    : ['Zur Fahrzeugauswahl', 'buchen.php'];

// --- Fahrzeug ermitteln -----------------------------------------------------

$id = (int) ($_GET['id'] ?? 0);
$Fahrzeug = CFahrzeug::LoadByID($id);

if ($Fahrzeug === null) {
    http_response_code(404);
    $pageTitle = 'Fahrzeug nicht gefunden';

    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="schild schild--leer">
        <p>Zu dieser Angabe gibt es kein Fahrzeug.</p>
        <a class="button button--zweitrangig" href="<?= url($pageZurueck[1]) ?>"><?= e($pageZurueck[0]) ?></a>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = $Fahrzeug->GetHersteller() . ' ' . $Fahrzeug->GetModell();
$pageZusatz = 'Steckbrief';

$heute = new DateTimeImmutable('today');
$ausgemustert = $Fahrzeug->IstAusgemustert();
$ueberfaellig = $Fahrzeug->IstUeberfaellig();

// Stand im Schildkopf; eine überfällige Rückgabe ist rot. Wartung geht vor,
// die überfällige Rückgabe steht dann im Hinweis zur Wartung.
$stand = $Fahrzeug->GetStand();
$marke = $stand === 'unterwegs' && $ueberfaellig ? ['Überfällig', 'rot'] : $standMarke[$stand];

// --- Merkmale ---------------------------------------------------------------
// Beschriftung => Wert, in Anzeigereihenfolge. km-Stand und HU/AU nur, wenn
// das Fahrzeug sie hat (nicht beim Fahrrad).

$antrieb = $Fahrzeug->GetAntriebID();

$merkmale = [
    'Fahrzeugart'  => $Fahrzeugarten->GetBezeichnung($Fahrzeug->GetArtID()),
    'Typ'          => $Fahrzeug->GetTyp(),
    'Baujahr'      => $Fahrzeug->GetBaujahr() !== null ? (string) $Fahrzeug->GetBaujahr() : '–',
    'Sitzplätze'   => (string) $Fahrzeug->GetSitzplaetze(),
    'Antrieb'      => $antrieb !== null ? $Antriebe->GetBezeichnung($antrieb) : '–',
    'Führerschein' => $Fahrzeug->GetFuehrerschein() !== null ? 'Klasse ' . $Fahrzeug->GetFuehrerschein() : 'keiner nötig',
];

if ($Fahrzeug->GetKmStand() !== null) {
    $merkmale['km-Stand'] = km_text($Fahrzeug->GetKmStand()) . ' km';
}

if ($Fahrzeug->GetHuAu() !== null) {
    $merkmale['Nächste HU/AU'] = $Fahrzeug->GetHuAu()->format('m/Y');
}

// HU/AU in Ampelsprache wie in fahrzeuge.php
$huAuMarke = match ($Fahrzeug->GetHuAuStand()) {
    'ueberfaellig' => ['Überfällig', 'rot'],
    'faellig'      => ['Bald fällig', 'gelb'],
    default        => null,
};

// Bild aus assets/img/, sonst Platzhalter
$bildDatei = $Fahrzeug->GetBild();
$hatBild = $bildDatei !== null && is_file(__DIR__ . '/assets/img/' . $bildDatei);

// Laufende Wartung für den Hinweis im Schild (Grund und Ende)
$LaufendeWartung = CWartung::LoadLaufende($id);

// --- Belegung der nächsten 14 Tage ------------------------------------------
// Je Tag: 'frei', 'vergeben' oder 'wartung'. In Wartung sind die Tage bis zum
// voraussichtlichen Ende; ist das Ende offen, alle Tage (wie im Kalender).
// Ein Tag ist vergeben, wenn eine beantragte, genehmigte oder laufende
// Buchung ihn einschließt; eine überfällige Rückgabe belegt heute. Fahrer und
// Zweck sieht nur der Fuhrparkleiter.

$belegung = [];

if (!$ausgemustert) {
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
            'fahrer'  => null,
            'hinweis' => null,
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

                    if ($fuhrparkleiter) {
                        $eintrag['fahrer'] = $Buchung->GetFahrerName();
                        $eintrag['hinweis'] = $Buchung->GetFahrerName() . ': ' . $Zwecke->GetBezeichnung($Buchung->GetZweckID());
                    }
                    break;
                }
            }
        }

        $belegung[] = $eintrag;
    }
}

// Zustand eines Tages => [Wort, Farbe] für die Marke
$zustandMarke = [
    'frei'     => ['frei', 'gruen'],
    'vergeben' => ['vergeben', 'grau'],
    'wartung'  => ['in Wartung', 'gelb'],
];

$wochentage = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

$freieTage = count(array_filter($belegung, fn (array $tag): bool => $tag['zustand'] === 'frei'));

// --- Schäden ----------------------------------------------------------------
// Noch nicht behobene Schäden, für beide Rollen ohne Namen.

$ListeOffene = new CListeSchaeden();
$ListeOffene->LadenOffene($id);

// --- Fahrtenbuch des Fahrzeugs ----------------------------------------------
// Nur für den Fuhrparkleiter; für Mitarbeiter wird nichts davon geladen.

$ListeFahrten = new CListeBuchungen();
$ListeWartungen = new CListeWartungen();

if ($fuhrparkleiter) {
    $ListeFahrten->LadenFahrtenbuch($id, true);
    $ListeWartungen->LadenZuFahrzeug($id);
}

// Ohne km-Stand (Fahrrad) entfallen die km-Spalten und die Summe.
$mitKm = $Fahrzeug->GetKmStand() !== null;

$summeKm = 0;
foreach ($ListeFahrten->GetAlle() as $Fahrt) {
    $summeKm += $Fahrt->GetGefahreneKm() ?? 0;
}

// Wartungen stehen als eigene Zeilen zwischen den Fahrten. Sie zählen nicht
// zu den Fahrten und km. Sortiert wird nach dem Ende (Rückgabe bzw. Freigabe),
// eine laufende Wartung steht ganz oben.
$eintraege = [];

foreach ($ListeFahrten->GetAlle() as $Fahrt) {
    $eintraege[] = ['art' => 'fahrt', 'datum' => $Fahrt->GetZurueckgegebenAm(), 'daten' => $Fahrt];
}

foreach ($ListeWartungen->GetAlle() as $Wartung) {
    $eintraege[] = ['art' => 'wartung', 'datum' => $Wartung->GetFreigegebenAm() ?? $heute->modify('+1 day'), 'daten' => $Wartung];
}

usort($eintraege, fn (array $a, array $b): int => $b['datum'] <=> $a['datum']);

// Spalten des Fahrtenbuchs: Datum, Fahrer, Zweck; dazu drei km-Spalten
// (Anfang, Ende, gefahren).
$anzahlSpalten = 3 + ($mitKm ? 3 : 0);

$anzahlFahrten = $ListeFahrten->Count();
$anzahlWartungen = $ListeWartungen->Count();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Das Fahrzeug als großes Schild -->
<article class="schild steckbrief">
    <div class="schild__kopf">
        <div class="schild__name">
            <!-- Der Name steht schon im Titel, hier das Kennzeichen als Feld -->
            <p class="schild__kennwert">
                <span class="stand__label">Kennzeichen</span>
                <span class="schild__kennwert-wert"><?= e($Fahrzeug->GetKennzeichen()) ?></span>
            </p>
        </div>
        <p class="stand stand--<?= e($marke[1]) ?>">
            <span class="stand__label">Stand</span>
            <span class="stand__wert"><?= e($marke[0]) ?></span>
        </p>
    </div>

    <div class="schild__koerper">
        <div class="steckbrief__inhalt">
            <?php if ($hatBild): ?>
                <img class="steckbrief__bild" src="<?= url('assets/img/' . $bildDatei) ?>" alt="<?= e($pageTitle) ?>">
            <?php else: ?>
                <span class="steckbrief__bild ohne-bild">
                    <span class="icon icon--<?= art_icon($Fahrzeug->GetArtID()) ?>" aria-hidden="true"></span>
                    Noch kein Foto
                </span>
            <?php endif; ?>

            <dl class="felder felder--steckbrief">
                <?php foreach ($merkmale as $beschriftung => $wert): ?>
                    <div class="feld">
                        <dt><?= e($beschriftung) ?></dt>
                        <dd>
                            <?= e($wert) ?>
                            <?php if ($beschriftung === 'Nächste HU/AU' && $huAuMarke !== null): ?>
                                <small><span class="marke marke--<?= e($huAuMarke[1]) ?>"><?= e($huAuMarke[0]) ?></span></small>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </div>

        <!-- Aktion je Rolle; ohne mögliche Aktion ein Hinweis mit Grund -->
        <div class="schild__aktionen">
            <?php if ($ausgemustert): ?>
                <p class="schild__hinweis">Nicht mehr buchbar.</p>
            <?php elseif ($fuhrparkleiter): ?>
                <?php
                // Dasselbe Formular wie in fahrzeuge.php, dort unter dem Fahrzeug
                $knopf = match (true) {
                    !$Fahrzeug->IstInWartung()            => 'In Wartung setzen',
                    $Fahrzeug->GetWartungBis() === null   => 'Wartungsende eintragen',
                    default                               => 'Ende ändern',
                };
                ?>
                <a class="button button--klein" href="<?= url('fahrzeuge.php?formular=' . $id) ?>#formular"><?= e($knopf) ?></a>
                <p class="schild__hinweis">
                    <?= $Fahrzeug->IstInWartung() ? 'Freigeben unter Fahrzeuge.' : 'Sperrt das Fahrzeug für Wartung oder Reparatur.' ?>
                </p>
            <?php elseif ($Fahrzeug->IstWartungEndeOffen()): ?>
                <p class="schild__hinweis">In Wartung. Buchen ist erst wieder möglich, wenn der Fuhrparkleiter ein Ende festlegt.</p>
            <?php elseif ($Fahrzeug->IstInWartung()): ?>
                <?php $buchbarAb = $Fahrzeug->GetWartungBis()->modify('+1 day'); ?>
                <a class="button button--klein" href="<?= url('buchen.php?' . http_build_query(['fahrzeug' => $id, 'beginn' => $buchbarAb->format('Y-m-d')])) ?>">Ab <?= e($buchbarAb->format('d.m.')) ?> buchen</a>
                <p class="schild__hinweis">Bis <?= e($Fahrzeug->GetWartungBis()->format('d.m.Y')) ?> in Wartung.</p>
            <?php else: ?>
                <a class="button button--klein" href="<?= url('buchen.php?fahrzeug=' . $id) ?>">Dieses Fahrzeug buchen</a>
                <p class="schild__hinweis">Zeitraum und Zweck wählen Sie im nächsten Schritt.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($ausgemustert): ?>
        <div class="schild__text">
            <p class="schild__grund">Ausgemustert am <?= e($Fahrzeug->GetGeloeschtAm()->format('d.m.Y')) ?></p>
            <p>Das Fahrzeug kann nicht mehr gebucht werden; das Fahrtenbuch bleibt erhalten.</p>
        </div>
    <?php elseif ($LaufendeWartung !== null): ?>
        <div class="schild__text">
            <p class="schild__grund">In Wartung: <?= e($LaufendeWartung->GetGrund()) ?></p>
            <p>
                <?php if ($Fahrzeug->IstWartungEndeOffen()): ?>
                    Wann es wieder gebucht werden kann, steht noch nicht fest (<?= e($Fahrzeug->GetWartungText()) ?>).
                <?php else: ?>
                    Voraussichtlich bis <?= e($Fahrzeug->GetWartungBis()->format('d.m.Y')) ?>. Danach kann es wieder gebucht werden.
                <?php endif; ?>
                <?php if ($ueberfaellig): ?>
                    Die Rückgabe der laufenden Fahrt ist überfällig.
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($Fahrzeug->IstLuxus() && !$ausgemustert): ?>
        <div class="schild__text">
            <p class="schild__grund">Luxusauto</p>
            <p>
                Fahren dürfen es nur freigegebene Mitarbeiter, und schon ein Kleinschaden sperrt es bis
                zur Reparatur.
                <?php if (!$fuhrparkleiter && $Fahrzeug->FehltFreigabe(CNutzer::LoadByID(aktueller_nutzer()))): ?>
                    Sie sind dafür nicht freigegeben, können es aber für einen freigegebenen Kollegen buchen.
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>
</article>

<!-- Noch nicht behobene Schäden, ohne Namen -->
<section class="steckbrief__abschnitt" aria-labelledby="offene-schaeden">
    <?php $anzahlOffen = $ListeOffene->Count(); ?>
    <h2 class="abschnitt" id="offene-schaeden">
        Offene Schäden
        <span class="abschnitt__wert">
            <?= $anzahlOffen === 0 ? 'keine bekannten' : e((string) $anzahlOffen) . ($anzahlOffen === 1 ? ' Schaden' : ' Schäden') ?>
        </span>
    </h2>

    <?php if ($anzahlOffen > 0): ?>
        <table class="table table--stapel">
            <thead>
                <tr>
                    <th>Gemeldet</th>
                    <th>Stand</th>
                    <th>Beschreibung</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ListeOffene->GetAlle() as $Schaden): ?>
                    <?php $inWartung = $Schaden->HaeltInWartung(); ?>
                    <tr>
                        <td>
                            <?= e($Schaden->GetGemeldetAm()->format('d.m.Y')) ?>
                            <span class="table__zusatz"><?= e($Anlaesse->GetBezeichnung($Schaden->GetAnlassID())) ?></span>
                        </td>
                        <!-- Stand wie in schaeden.php; hält der Schaden das Fahrzeug in Wartung, steht die Art darunter -->
                        <td class="table__stand" data-label="Stand">
                            <?php if ($inWartung): ?>
                                <span class="marke marke--gelb">In Wartung</span>
                                <span class="table__zusatz"><?= $Schaden->GetSchwereID() === SCHWERE_WARTUNG ? 'Schaden mit Wartung' : 'Kleinschaden' ?></span>
                            <?php else: ?>
                                <span class="marke marke--grau">Kleinschaden</span>
                            <?php endif; ?>
                        </td>
                        <td class="table__breit" data-label="Beschreibung"><?= e($Schaden->GetBeschreibung()) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($fuhrparkleiter): ?>
            <p class="weiter"><a href="<?= url('schaeden.php') ?>">Zu den Schäden</a></p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if (!$ausgemustert): ?>
    <!-- Belegung: je Tag ein Feld, zwei Wochen -->
    <section class="steckbrief__abschnitt" aria-labelledby="belegung">
        <h2 class="abschnitt" id="belegung">
            Belegung
            <span class="abschnitt__wert">
                <?php if ($freieTage === 14): ?>
                    alle 14 Tage frei
                <?php elseif ($freieTage === 0): ?>
                    kein Tag frei
                <?php else: ?>
                    <?= e((string) $freieTage) ?> von 14 Tagen frei
                <?php endif; ?>
            </span>
            <span class="abschnitt__zusatz"><?= e(zeitraum_kurz($heute, $heute->modify('+13 day'))) ?></span>
        </h2>

        <div class="schild steckbrief__belegung">
            <ol class="steckbrief__tage">
                <?php foreach ($belegung as $nr => $tag): ?>
                    <?php $tagMarke = $zustandMarke[$tag['zustand']]; ?>
                    <li class="steckbrief__tag steckbrief__tag--<?= e($tag['zustand']) ?>"<?= $tag['hinweis'] !== null ? ' title="' . e($tag['hinweis']) . '"' : '' ?>>
                        <span class="steckbrief__datum">
                            <?= $nr === 0 ? 'Heute' : e($wochentage[(int) $tag['datum']->format('w')]) ?>
                            <?= e($tag['datum']->format('d.m.')) ?>
                        </span>
                        <span class="marke marke--<?= e($tagMarke[1]) ?>"><?= e($tagMarke[0]) ?></span>
                        <?php if ($tag['fahrer'] !== null): ?>
                            <small class="steckbrief__fahrer"><?= e($tag['fahrer']) ?></small>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>
<?php endif; ?>

<?php if ($fuhrparkleiter): ?>
    <!-- Fahrtenbuch, nur für den Fuhrparkleiter: abgeschlossene Fahrten und Wartungen, neueste zuerst -->
    <section class="steckbrief__abschnitt" id="fahrtenbuch" aria-labelledby="fahrtenbuch-titel">
        <h2 class="abschnitt" id="fahrtenbuch-titel">
            Fahrtenbuch
            <span class="abschnitt__wert">
                <?= $anzahlFahrten === 0 ? 'keine Fahrten' : e((string) $anzahlFahrten) . ($anzahlFahrten === 1 ? ' Fahrt' : ' Fahrten') ?><?php if ($mitKm && $anzahlFahrten > 0): ?>,
                    <?= e(km_text($summeKm)) ?> km<?php endif; ?>
            </span>
            <span class="abschnitt__zusatz">
                <?php if ($anzahlWartungen > 0): ?>
                    dazu <?= e((string) $anzahlWartungen) ?> <?= $anzahlWartungen === 1 ? 'Wartung' : 'Wartungen' ?> &middot;
                <?php endif; ?>
                neueste zuerst
            </span>
        </h2>

        <table class="table table--stapel fahrtenbuch">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Fahrer</th>
                    <th>Zweck</th>
                    <?php if ($mitKm): ?>
                        <th class="table__num">km Anfang</th>
                        <th class="table__num">km Ende</th>
                        <th class="table__num">Gefahren</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eintraege as $eintrag): ?>
                    <?php if ($eintrag['art'] === 'wartung'): ?>
                        <?php $Wartung = $eintrag['daten']; ?>
                        <!-- Wartung zwischen den Fahrten; der Grund steht statt des Zwecks -->
                        <tr>
                            <td>
                                <?php if ($Wartung->IstLaufend()): ?>
                                    seit <?= e($Wartung->GetBegonnenAm()->format('d.m.Y')) ?>
                                <?php else: ?>
                                    <?= e(zeitraum_text($Wartung->GetBegonnenAm(), $Wartung->GetFreigegebenAm())) ?>
                                <?php endif; ?>
                            </td>
                            <td class="table__leer">&ndash;</td>
                            <td class="table__breit" data-label="Zweck">
                                <?php if ($Wartung->IstLaufend()): ?>
                                    <span class="marke marke--gelb">In Wartung</span>
                                <?php else: ?>
                                    <span class="marke marke--grau">Wartung</span>
                                <?php endif; ?>
                                <span class="table__zusatz"><?= e($Wartung->GetGrund()) ?></span>
                                <?php if ($Wartung->IstLaufend()): ?>
                                    <span class="table__zusatz">läuft, <?= e($Fahrzeug->GetWartungText()) ?></span>
                                <?php endif; ?>
                            </td>
                            <?php if ($mitKm): ?>
                                <td class="table__num table__leer">&ndash;</td>
                                <td class="table__num table__leer">&ndash;</td>
                                <td class="table__num table__leer">&ndash;</td>
                            <?php endif; ?>
                        </tr>
                        <?php continue; ?>
                    <?php endif; ?>

                    <?php $Fahrt = $eintrag['daten']; ?>
                    <tr>
                        <td><?= e(zeitraum_text($Fahrt->GetStart(), $Fahrt->GetZurueckgegebenAm())) ?></td>
                        <td data-label="Fahrer"><?= e($Fahrt->GetFahrerName()) ?></td>
                        <td data-label="Zweck"><?= e($Zwecke->GetBezeichnung($Fahrt->GetZweckID())) ?></td>
                        <?php if ($mitKm): ?>
                            <td class="table__num" data-label="km Anfang"><?= e(km_text($Fahrt->GetKmStart())) ?></td>
                            <td class="table__num" data-label="km Ende"><?= e(km_text($Fahrt->GetKmEnde())) ?></td>
                            <td class="table__num" data-label="Gefahren"><?= e(km_text($Fahrt->GetGefahreneKm())) ?> km</td>
                        <?php endif; ?>
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
