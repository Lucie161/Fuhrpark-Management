<?php
/**
 * Überblick nach der Anmeldung (Anwendungsfall 8), je Rolle.
 *
 * Beide Rollen: ganz oben ein rotes Banner, sobald eine Rückgabe überfällig
 * ist. Mitarbeiter: Banner nur für die eigenen Fahrten mit „Jetzt
 * zurückgeben“, darunter aktive Buchung mit „Fahrt beginnen“, laufende Fahrt
 * und höchstens drei noch nicht bestätigte Anträge. Fuhrparkleiter: Banner mit der Anzahl,
 * die Liste (Fahrer zuerst) hinter „Details“; dann „Zu erledigen“ mit den drei
 * ältesten offenen Anträgen zum direkten Entscheiden und den offenen
 * Schäden, zuletzt „Fuhrpark“ mit den Fahrzeugen nach Status. Er bucht nicht
 * selbst und hat daher keine eigenen Fahrten (siehe
 * docs/technisches-konzept.md, Regeln 5 und 8).
 *
 * Prototyp: Die Daten kommen aus includes/beispieldaten.php. Mit Datenbank:
 *
 *   Mitarbeiter:
 *   SELECT ... FROM buchungen WHERE (antragsteller_id = :ich OR fahrer_id = :ich)
 *      AND status IN ('offen', 'genehmigt', 'unterwegs')
 *   Fuhrparkleiter:
 *   SELECT status, COUNT(*) FROM fahrzeuge GROUP BY status
 *   SELECT ... FROM buchungen WHERE status = 'unterwegs'
 *   offene Anträge wie in genehmigungen.php
 *   SELECT COUNT(*) FROM schaeden
 *    WHERE schwere = 'wartung' AND behoben_am IS NULL
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Übersicht';

$ich = aktueller_nutzer();

// Feste Liste der Zwecke (siehe docs/user-stories.md).
$zweckText = [
    'montage'           => 'Montage',
    'service'           => 'Wartung/Service',
    'kundentermin'      => 'Kundentermin',
    'aufmass'           => 'Aufmaß',
    'materialtransport' => 'Materialtransport',
    'lieferant'         => 'Lieferantenbesuch',
    'schulung'          => 'Schulung',
    'sonstiges'         => 'Sonstiges',
];

// So viele offene Anträge stehen direkt auf der Übersicht: beim
// Fuhrparkleiter die ältesten zum Entscheiden (die übrigen hinter dem Link auf
// genehmigungen.php), beim Mitarbeiter die nächsten noch nicht bestätigten
// (die übrigen in meine-buchungen.php).
$antraegeAufUebersicht = 3;

$heute = new DateTimeImmutable('today');

// --- Meine Buchungen (nur Mitarbeiter) --------------------------------------
// Aktiv (Fahrt kann beginnen): genehmigt, heute im Zeitraum, ich bin Fahrer.
// Laufend: unterwegs. Beantragt: noch nicht bestätigt (Status „offen“).
// Genehmigte Fahrten, die noch nicht begonnen haben, stehen nur in
// meine-buchungen.php.

$aktive     = [];
$laufende   = [];
$beantragte = [];

if (!ist_fuhrparkleiter()) {
    foreach (beispiel_buchungen() as $buchung) {
        if (($buchung['antragsteller_id'] !== $ich && $buchung['fahrer_id'] !== $ich)
            || !in_array($buchung['status'], ['offen', 'genehmigt', 'unterwegs'], true)) {
            continue;
        }

        if ($buchung['status'] === 'unterwegs') {
            $buchung['ueberfaellig'] = $buchung['ende'] < $heute;
            $laufende[] = $buchung;
        } elseif ($buchung['status'] === 'genehmigt' && $buchung['fahrer_id'] === $ich
            && $buchung['start'] <= $heute && $heute <= $buchung['ende']) {
            $buchung['sperre'] = sperre_fahrtbeginn($ich, $buchung['fahrzeug_id']);
            $aktive[] = $buchung;
        } elseif ($buchung['status'] === 'offen') {
            $beantragte[] = $buchung;
        }
    }

    usort($beantragte, fn (array $a, array $b): int => $a['start'] <=> $b['start']);
}

$anzahlBeantragte = count($beantragte);
$beantragte = array_slice($beantragte, 0, $antraegeAufUebersicht);

// Eigene überfällige Rückgaben (nur Mitarbeiter), für das rote Banner.
$meineUeberfaelligen = array_filter($laufende, fn (array $b): bool => $b['ueberfaellig']);

// --- Fuhrpark (nur Fuhrparkleiter) ------------------------------------------

$fuhrpark = null;

if (ist_fuhrparkleiter()) {
    $fahrzeuge = beispiel_fahrzeuge();

    $unterwegs = array_filter(beispiel_buchungen(), fn (array $b): bool => $b['status'] === 'unterwegs');

    // Überfällige Rückgaben mit der Zahl der Tage seit dem Ende.
    $ueberfaellig = [];

    foreach (ueberfaellige_rueckgaben() as $fahrt) {
        $fahrt['tage'] = $fahrt['ende']->diff($heute)->days;
        $ueberfaellig[] = $fahrt;
    }

    // Offene Anträge, älteste zuerst, wie in genehmigungen.php. 'hindernis':
    // warum Genehmigen nicht geht, sonst null.
    $antraege = array_filter(beispiel_buchungen(), fn (array $b): bool => $b['status'] === 'offen');
    uasort($antraege, fn (array $a, array $b): int => $a['beantragt_am'] <=> $b['beantragt_am']);

    foreach ($antraege as &$antrag) {
        $antrag['hindernis'] = genehmigung_hindernis($antrag);
    }
    unset($antrag);

    // Zu erledigen sind nur Schäden, mit denen ein Fahrzeug in Wartung steht.
    // Kleinschäden sperren nichts und müssen nicht sofort behoben werden; sie
    // stehen in schaeden.php (siehe docs/technisches-konzept.md, Regel 10).
    $offeneSchaeden = array_filter(
        beispiel_schaeden(),
        fn (array $s): bool => $s['schwere'] === 'wartung' && $s['behoben_am'] === null,
    );

    // „Unterwegs“ ist kein gespeicherter Status, sondern ergibt sich aus den
    // laufenden Fahrten (siehe docs/technisches-konzept.md).
    $anzahlWartung   = count(array_filter($fahrzeuge, fn (array $f): bool => $f['status'] === 'wartung'));
    $anzahlUnterwegs = count(array_unique(array_column($unterwegs, 'fahrzeug_id')));

    $fuhrpark = [
        'status' => [
            ['wert' => count($fahrzeuge) - $anzahlWartung - $anzahlUnterwegs, 'label' => 'Fahrzeuge frei'],
            ['wert' => $anzahlUnterwegs, 'label' => 'unterwegs'],
            ['wert' => $anzahlWartung,   'label' => 'in Wartung'],
        ],
        'antraege'        => array_slice($antraege, 0, $antraegeAufUebersicht, true),
        'anzahl_antraege' => count($antraege),
        'schaeden'        => count($offeneSchaeden),
        'ueberfaellig'    => $ueberfaellig,
        // Wartungen mit offenem Ende: nach einer Rückgabe mit Schaden noch
        // nicht festgelegt oder das voraussichtliche Ende ist vorbei. Der
        // Fuhrparkleiter legt das Ende fest oder gibt das Fahrzeug frei.
        'wartung_ende_offen' => array_filter($fahrzeuge, fn (array $f): bool => wartung_ende_offen($f)),
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
                <?php foreach ($fuhrpark['ueberfaellig'] as $fahrt): ?>
                    <li>
                        <?= e(nutzer_name($fahrt['fahrer_id'])) ?>,
                        <a href="<?= url('fahrzeug.php?id=' . $fahrt['fahrzeug_id']) ?>"><?= e(fahrzeug_name($fahrt['fahrzeug_id'])) ?></a>,
                        fällig am <?= e($fahrt['ende']->format('d.m.Y')) ?>,
                        seit <?= e((string) $fahrt['tage']) ?> <?= $fahrt['tage'] === 1 ? 'Tag' : 'Tagen' ?> überfällig
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    </div>
<?php endif; ?>

<?php if ($fuhrpark === null): ?>
    <?php foreach ($meineUeberfaelligen as $buchung): ?>
        <div class="banner">
            <p class="banner__text">
                <strong>Ihre Rückgabe ist überfällig.</strong>
                <?= e(fahrzeug_name($buchung['fahrzeug_id'])) ?> war am <?= e($buchung['ende']->format('d.m.Y')) ?> fällig.
                Bis zur Rückgabe können Sie nichts Neues buchen und keine andere Fahrt beginnen.
            </p>
            <a class="button button--klein banner__knopf" href="<?= url('rueckgabe.php?buchung=' . $buchung['id']) ?>">Jetzt zurückgeben</a>
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
            <!-- Dasselbe Formular wie in genehmigungen.php (Regel 8); es
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
                    <?php foreach ($fuhrpark['antraege'] as $id => $antrag): ?>
                        <tr>
                            <td>
                                <?= e(nutzer_name($antrag['antragsteller_id'])) ?>
                                <?php if ($antrag['fahrer_id'] !== $antrag['antragsteller_id']): ?>
                                    <span class="table__zusatz">für <?= e(nutzer_name($antrag['fahrer_id'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= url('fahrzeug.php?id=' . $antrag['fahrzeug_id']) ?>"><?= e(fahrzeug_name($antrag['fahrzeug_id'])) ?></a>
                            </td>
                            <td><?= e(zeitraum_text($antrag['start'], $antrag['ende'])) ?></td>
                            <td><?= e($zweckText[$antrag['zweck']] ?? $antrag['zweck']) ?></td>
                            <td>
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
                <?php foreach ($fuhrpark['wartung_ende_offen'] as $id => $fahrzeug): ?>
                    <li>
                        <a href="<?= url('fahrzeug.php?id=' . $id) ?>"><?= e(fahrzeug_name($id)) ?></a>:
                        <?= e(wartung_text($fahrzeug)) ?>. Das Fahrzeug bleibt gesperrt, bis Sie das Ende
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

        <?php foreach ($aktive as $buchung): ?>
            <div class="aktuell">
                <p class="aktuell__text">
                    <strong>Ihre Fahrt mit <?= e(fahrzeug_name($buchung['fahrzeug_id'])) ?> kann beginnen.</strong>
                    <span class="table__zusatz">
                        <?= e(zeitraum_text($buchung['start'], $buchung['ende'])) ?>, <?= e($zweckText[$buchung['zweck']] ?? $buchung['zweck']) ?>
                    </span>
                </p>

                <?php require __DIR__ . '/includes/fahrt-beginnen.php'; ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($laufende as $buchung): ?>
            <div class="aktuell<?= $buchung['ueberfaellig'] ? ' aktuell--ueberfaellig' : '' ?>">
                <p class="aktuell__text">
                    <strong>Sie sind unterwegs mit <?= e(fahrzeug_name($buchung['fahrzeug_id'])) ?>.</strong>
                    <span class="table__zusatz">
                        Rückgabe <?= $buchung['ueberfaellig'] ? 'war' : 'ist' ?> fällig am
                        <?= e($buchung['ende']->format('d.m.Y')) ?>.
                    </span>
                    <?php if ($buchung['ueberfaellig']): ?>
                        <span class="badge badge--abgelehnt">Rückgabe überfällig</span>
                    <?php endif; ?>
                </p>

                <a class="button button--klein" href="<?= url('rueckgabe.php?buchung=' . $buchung['id']) ?>">Zurückgeben</a>
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
                <?php foreach ($beantragte as $buchung): ?>
                    <tr>
                        <td><?= e(zeitraum_text($buchung['start'], $buchung['ende'])) ?></td>
                        <td>
                            <a href="<?= url('fahrzeug.php?id=' . $buchung['fahrzeug_id']) ?>"><?= e(fahrzeug_name($buchung['fahrzeug_id'])) ?></a>
                            <?php if ($buchung['fahrer_id'] !== $ich): ?>
                                <span class="table__zusatz">Fahrer: <?= e(nutzer_name($buchung['fahrer_id'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($zweckText[$buchung['zweck']] ?? $buchung['zweck']) ?></td>
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
