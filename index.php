<?php
/**
 * Überblick nach der Anmeldung, je Rolle.
 *
 * Beide Rollen: ganz oben ein rotes Banner, sobald eine Rückgabe überfällig
 * ist. Mitarbeiter: Banner nur für die eigenen Fahrten mit „Jetzt
 * zurückgeben“, darunter aktive Buchung mit „Fahrt beginnen“, laufende Fahrt
 * und höchstens drei noch nicht bestätigte Anträge. Fuhrparkleiter: Banner mit der Anzahl,
 * die Liste (Fahrer zuerst) hinter „Details“; dann „Zu erledigen“ mit den drei
 * ältesten offenen Anträgen zum direkten Entscheiden und den offenen
 * Schäden, zuletzt „Fuhrpark“ mit den Fahrzeugen nach Status. Er bucht nicht
 * selbst und hat daher keine eigenen Fahrten.
 *
 * Mitarbeiter: eigene Buchungen aus CListeBuchungen::LadenAktuelle().
 * Fuhrparkleiter: Fahrzeuge aus CListeFahrzeuge::LadenImBestand(),
 * überfällige Rückgaben aus CListeBuchungen::LadenUeberfaellige(), offene
 * Anträge aus LadenOffeneAntraege() mit CBuchung::GetGenehmigungHindernis(),
 * die Schäden aus CListeSchaeden::CountSperrende().
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_angemeldet();

$pageTitle = 'Übersicht';

$ich = aktueller_nutzer();

// So viele offene Anträge stehen direkt auf der Übersicht: beim
// Fuhrparkleiter die ältesten zum Entscheiden (die übrigen hinter dem Link auf
// genehmigungen.php), beim Mitarbeiter die nächsten noch nicht bestätigten
// (die übrigen in meine-buchungen.php).
$antraegeAufUebersicht = 3;

$heute = new DateTimeImmutable('today');

$Zwecke = new CListeSchluessel('zwecke');

// --- Meine Buchungen (nur Mitarbeiter) --------------------------------------
// Aktiv (Fahrt kann beginnen): genehmigt, heute im Zeitraum, ich bin Fahrer.
// Laufend: unterwegs, ich bin Fahrer (nur er gibt zurück). Beantragt: noch
// nicht bestätigt (Status „offen“). Genehmigte Fahrten, die noch nicht
// begonnen haben, und Fahrten, die ich für andere gebucht habe, stehen nur in
// meine-buchungen.php.

$aktive     = [];
$laufende   = [];
$beantragte = [];

if (!ist_fuhrparkleiter()) {
    // Eigene Buchungen, die nächste zuerst; Namen sind erlaubt
    $ListeMeine = new CListeBuchungen();
    $ListeMeine->LadenAktuelle($ich);

    foreach ($ListeMeine->GetAlle() as $Buchung) {
        if ($Buchung->GetStatusID() === STATUS_UNTERWEGS && $Buchung->GetFahrerID() === $ich) {
            $laufende[] = $Buchung;
        } elseif ($Buchung->KannBeginnen($ich)) {
            $aktive[] = $Buchung;
        } elseif ($Buchung->GetStatusID() === STATUS_OFFEN) {
            $beantragte[] = $Buchung;
        }
    }
}

$anzahlBeantragte = count($beantragte);
$beantragte = array_slice($beantragte, 0, $antraegeAufUebersicht);

// Eigene überfällige Rückgaben (nur Mitarbeiter), für das rote Banner.
$meineUeberfaelligen = array_filter($laufende, fn (CBuchung $Buchung): bool => $Buchung->IstUeberfaellig());

// --- Fuhrpark (nur Fuhrparkleiter) ------------------------------------------

$fuhrpark = null;

if (ist_fuhrparkleiter()) {
    $ListeFahrzeuge = new CListeFahrzeuge();
    $ListeFahrzeuge->LadenImBestand();
    $fahrzeuge = $ListeFahrzeuge->GetAlle();

    // Überfällige Rückgaben mit Fahrernamen, die älteste zuerst
    $ListeUeberfaellige = new CListeBuchungen();
    $ListeUeberfaellige->LadenUeberfaellige(true);

    // Offene Anträge, älteste zuerst, wie in genehmigungen.php
    $ListeAntraege = new CListeBuchungen();
    $ListeAntraege->LadenOffeneAntraege();

    // Zu erledigen sind nur Schäden, mit denen ein Fahrzeug in Wartung steht.
    // Kleinschäden sperren nichts und müssen nicht sofort behoben werden; sie
    // stehen in schaeden.php. Bei Luxusautos sperrt auch ein Kleinschaden,
    // aber erst ab der Rückgabe; gezählt wird, was das Fahrzeug schon in
    // Wartung hält.
    $ListeSchaeden = new CListeSchaeden();
    $ListeSchaeden->LadenAlle();

    // Status je Fahrzeug wie in fahrzeuge.php: Wartung geht vor unterwegs,
    // so zählt jedes Fahrzeug genau einmal.
    $proStand = ['verfuegbar' => 0, 'unterwegs' => 0, 'wartung' => 0];

    foreach ($fahrzeuge as $Fahrzeug) {
        $proStand[$Fahrzeug->GetStand()]++;
    }

    $fuhrpark = [
        'status' => [
            ['wert' => $proStand['verfuegbar'], 'label' => 'Fahrzeuge frei'],
            ['wert' => $proStand['unterwegs'],  'label' => 'unterwegs'],
            ['wert' => $proStand['wartung'],    'label' => 'in Wartung'],
        ],
        'antraege'        => array_slice($ListeAntraege->GetAlle(), 0, $antraegeAufUebersicht),
        'anzahl_antraege' => $ListeAntraege->Count(),
        'schaeden'        => $ListeSchaeden->CountSperrende(),
        'ueberfaellig'    => $ListeUeberfaellige->GetAlle(),
        // Wartungen mit offenem Ende: nach einer Rückgabe mit Schaden noch
        // nicht festgelegt oder das voraussichtliche Ende ist vorbei. Der
        // Fuhrparkleiter legt das Ende fest oder gibt das Fahrzeug frei.
        'wartung_ende_offen' => array_filter($fahrzeuge, fn (CFahrzeug $Fahrzeug): bool => $Fahrzeug->IstWartungEndeOffen()),
    ];

    // Für das Formular aus includes/antrag-entscheidung.php: nach der
    // Entscheidung zurück zur Übersicht.
    $zurueck = 'index';
    $offenerAntrag = null;
    $kommentar = '';
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($fuhrpark !== null && $fuhrpark['ueberfaellig'] !== []): ?>
    <?php $anzahl = count($fuhrpark['ueberfaellig']); ?>
    <!-- Rotes Banner nur bei überfälligen Rückgaben; die Liste erst hinter
         „Details“, ohne JavaScript. -->
    <div class="banner">
        <p class="banner__text">
            <strong><?= e((string) $anzahl) ?> <?= $anzahl === 1 ? 'Rückgabe ist' : 'Rückgaben sind' ?> überfällig.</strong>
            Bitte mit den Fahrern klären.
        </p>

        <details class="banner__details">
            <summary>Details</summary>

            <ul class="banner__liste">
                <?php foreach ($fuhrpark['ueberfaellig'] as $Fahrt): ?>
                    <?php $tage = $Fahrt->GetEnde()->diff($heute)->days; ?>
                    <li>
                        <?= e($Fahrt->GetFahrerName()) ?>,
                        <a href="<?= url('fahrzeug.php?id=' . $Fahrt->GetFahrzeugID()) ?>"><?= e($Fahrt->GetFahrzeugName()) ?></a>,
                        fällig am <?= e($Fahrt->GetEnde()->format('d.m.Y')) ?>,
                        seit <?= e((string) $tage) ?> <?= $tage === 1 ? 'Tag' : 'Tagen' ?> überfällig
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    </div>
<?php endif; ?>

<?php if ($fuhrpark === null): ?>
    <?php foreach ($meineUeberfaelligen as $Buchung): ?>
        <div class="banner">
            <p class="banner__text">
                <strong>Ihre Rückgabe ist überfällig.</strong>
                <?= e($Buchung->GetFahrzeugName()) ?> war am <?= e($Buchung->GetEnde()->format('d.m.Y')) ?> fällig.
                Bis zur Rückgabe können Sie nichts Neues buchen und keine andere Fahrt beginnen.
            </p>
            <a class="button button--klein banner__knopf" href="<?= url('rueckgabe.php?buchung=' . $Buchung->GetID()) ?>">Jetzt zurückgeben</a>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<p class="lead">
    <?php if ($fuhrpark !== null): ?>
        Der Fuhrpark auf einen Blick: Fahrzeuge und was zu erledigen ist.
    <?php else: ?>
        Ihre Fahrten und Anträge auf einen Blick.
    <?php endif; ?>
</p>

<?php if ($fuhrpark !== null): ?>

    <section class="section">
        <h2>Zu erledigen</h2>

        <?php $anzahl = $fuhrpark['anzahl_antraege']; ?>
        <h3>
            <?= $anzahl === 0 ? 'Keine offenen Anträge' : e((string) $anzahl) . ($anzahl === 1 ? ' offener Antrag' : ' offene Anträge') ?>
        </h3>

        <?php if ($anzahl > 0): ?>
            <!-- Dasselbe Formular wie in genehmigungen.php; es
                 schickt dorthin und kehrt danach hierher zurück. -->
            <table class="table">
                <thead>
                    <tr>
                        <th>Antragsteller</th>
                        <th>Fahrzeug</th>
                        <th>Zeitraum</th>
                        <th>Zweck</th>
                        <th>Entscheidung</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fuhrpark['antraege'] as $Antrag): ?>
                        <tr>
                            <td>
                                <?= e($Antrag->GetAntragstellerName()) ?>
                                <?php if ($Antrag->GetFahrerID() !== $Antrag->GetAntragstellerID()): ?>
                                    <span class="table__zusatz">für <?= e($Antrag->GetFahrerName()) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= url('fahrzeug.php?id=' . $Antrag->GetFahrzeugID()) ?>"><?= e($Antrag->GetFahrzeugName()) ?></a>
                            </td>
                            <td><?= e(zeitraum_text($Antrag->GetStart(), $Antrag->GetEnde())) ?></td>
                            <td><?= e($Zwecke->GetBezeichnung($Antrag->GetZweckID())) ?></td>
                            <td>
                                <?php
                                // Für das Formular: Nummer und Hindernis des Antrags
                                $id = $Antrag->GetID();
                                $hindernis = $Antrag->GetGenehmigungHindernis();
                                ?>
                                <?php require __DIR__ . '/includes/antrag-entscheidung.php'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($anzahl > count($fuhrpark['antraege'])): ?>
                <p>
                    Die <?= e((string) count($fuhrpark['antraege'])) ?> ältesten stehen hier.
                    <a href="<?= url('genehmigungen.php') ?>">Alle <?= e((string) $anzahl) ?> Anträge</a>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <h3>Schäden</h3>

        <div class="cards">
            <div class="card<?= $fuhrpark['schaeden'] > 0 ? ' card--offen' : '' ?>">
                <p class="card__value"><?= e((string) $fuhrpark['schaeden']) ?></p>
                <p class="card__label"><?= $fuhrpark['schaeden'] === 1 ? 'Fahrzeug wegen Schaden in Wartung' : 'Fahrzeuge wegen Schaden in Wartung' ?></p>
                <?php if ($fuhrpark['schaeden'] > 0): ?>
                    <a class="card__link" href="<?= url('schaeden.php') ?>">Zu den Schäden</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($fuhrpark['wartung_ende_offen'] !== []): ?>
            <h3>Wartung: Ende festlegen</h3>

            <ul>
                <?php foreach ($fuhrpark['wartung_ende_offen'] as $Fahrzeug): ?>
                    <li>
                        <a href="<?= url('fahrzeug.php?id=' . $Fahrzeug->GetID()) ?>"><?= e($Fahrzeug->GetName()) ?></a>:
                        <?= e($Fahrzeug->GetWartungText()) ?>. Das Fahrzeug bleibt gesperrt, bis Sie das Ende
                        festlegen oder es freigeben.
                    </li>
                <?php endforeach; ?>
            </ul>

            <p><a href="<?= url('fahrzeuge.php?status=wartung') ?>">Zu den Fahrzeugen in Wartung</a></p>
        <?php endif; ?>
    </section>

    <section class="section">
        <h2>Fuhrpark</h2>

        <div class="cards">
            <?php foreach ($fuhrpark['status'] as $kennzahl): ?>
                <div class="card">
                    <p class="card__value"><?= e((string) $kennzahl['wert']) ?></p>
                    <p class="card__label"><?= e($kennzahl['label']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <p>
            <a href="<?= url('fahrzeuge.php') ?>">Alle Fahrzeuge</a> &middot;
            <a href="<?= url('kalender.php') ?>">Kalender</a>
        </p>
    </section>

<?php else: ?>

    <section class="section">
        <h2>Meine Fahrten</h2>

        <?php foreach ($aktive as $Buchung): ?>
            <div class="aktuell">
                <p class="aktuell__text">
                    <strong>Ihre Fahrt mit <?= e($Buchung->GetFahrzeugName()) ?> kann beginnen.</strong>
                    <span class="table__zusatz">
                        <?= e(zeitraum_text($Buchung->GetStart(), $Buchung->GetEnde())) ?>, <?= e($Zwecke->GetBezeichnung($Buchung->GetZweckID())) ?>
                    </span>
                </p>

                <?php
                // Das Formular schickt nach dem Fahrtbeginn hierher zurück.
                $zurueck = 'index';
                require __DIR__ . '/includes/fahrt-beginnen.php';
                ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($laufende as $Buchung): ?>
            <?php $ueberfaellig = $Buchung->IstUeberfaellig(); ?>
            <div class="aktuell<?= $ueberfaellig ? ' aktuell--ueberfaellig' : '' ?>">
                <p class="aktuell__text">
                    <strong>Sie sind unterwegs mit <?= e($Buchung->GetFahrzeugName()) ?>.</strong>
                    <span class="table__zusatz">
                        Rückgabe <?= $ueberfaellig ? 'war' : 'ist' ?> fällig am
                        <?= e($Buchung->GetEnde()->format('d.m.Y')) ?>.
                    </span>
                    <?php if ($ueberfaellig): ?>
                        <span class="badge badge--abgelehnt">Rückgabe überfällig</span>
                    <?php endif; ?>
                </p>

                <a class="button button--klein" href="<?= url('rueckgabe.php?buchung=' . $Buchung->GetID()) ?>">Zurückgeben</a>
            </div>
        <?php endforeach; ?>

        <h3>Noch nicht bestätigt</h3>

        <table class="table">
            <thead>
                <tr>
                    <th>Zeitraum</th>
                    <th>Fahrzeug</th>
                    <th>Zweck</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($beantragte as $Buchung): ?>
                    <tr>
                        <td><?= e(zeitraum_text($Buchung->GetStart(), $Buchung->GetEnde())) ?></td>
                        <td>
                            <a href="<?= url('fahrzeug.php?id=' . $Buchung->GetFahrzeugID()) ?>"><?= e($Buchung->GetFahrzeugName()) ?></a>
                            <?php if ($Buchung->GetFahrerID() !== $ich): ?>
                                <span class="table__zusatz">Fahrer: <?= e($Buchung->GetFahrerName()) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($Zwecke->GetBezeichnung($Buchung->GetZweckID())) ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($beantragte === []): ?>
                    <tr>
                        <td colspan="3" class="table__empty">Keine Anträge, die auf den Fuhrparkleiter warten.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($anzahlBeantragte > count($beantragte)): ?>
            <p>
                Die <?= e((string) count($beantragte)) ?> nächsten stehen hier.
                <a href="<?= url('meine-buchungen.php') ?>">Alle <?= e((string) $anzahlBeantragte) ?> unter Meine Buchungen</a>
            </p>
        <?php endif; ?>

        <div class="aktionen section">
            <a class="button" href="<?= url('buchen.php') ?>">Fahrzeug buchen</a>
            <a class="button button--zweitrangig" href="<?= url('meine-buchungen.php') ?>">Alle meine Buchungen</a>
        </div>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
