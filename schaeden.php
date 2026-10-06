<?php
/**
 * Schäden (Anwendungsfall 10), nur für den Fuhrparkleiter.
 *
 * Gemeldete Schäden und Bemerkungen aus den Rückgaben, neueste zuerst, mit
 * Link zum Steckbrief des Fahrzeugs. Steht das Fahrzeug noch in Wartung,
 * lässt es sich hier freigeben; das Formular schickt an fahrzeuge.php, das
 * die Statusänderung prüft und bestätigt.
 *
 * Prototyp: Fahrzeuge und Meldungen sind feste Beispieldaten. Mit Datenbank
 * (siehe docs/technisches-konzept.md, Regel 10):
 *
 *   SELECT ... FROM buchungen b JOIN fahrzeuge f ON f.id = b.fahrzeug_id
 *    WHERE b.status = 'abgeschlossen' AND (b.schaden = 1 OR b.bemerkung <> '')
 *    ORDER BY b.zurueckgegeben_am DESC
 *   SELECT buchung_id, datei FROM schadensfotos WHERE buchung_id IN (...)
 *
 * Schadensfotos sieht nur der Fuhrparkleiter (Datenschutz, siehe
 * docs/technisches-konzept.md, Regel 6). Im Prototyp gibt es noch keine
 * abgelegten Fotos, die Vorschau zeigt deshalb Platzhalter. Später die Fotos
 * nicht direkt aus uploads/schaeden/ verlinken, sondern über ein Skript
 * ausliefern, das die Rolle prüft.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle('fuhrparkleiter');

$pageTitle = 'Schäden';

// Beispiel-Fuhrpark, dieselben Fahrzeuge und derselbe gespeicherte Status
// wie in fahrzeuge.php.
$fahrzeuge = [
    1 => ['name' => 'Volkswagen Passat Variant (M-HS 101)', 'status' => 'verfuegbar'],
    2 => ['name' => 'Škoda Octavia Combi (M-HS 102)',       'status' => 'verfuegbar'],
    3 => ['name' => 'Ford Transit (M-HS 201)',              'status' => 'verfuegbar'],
    4 => ['name' => 'Mercedes-Benz Sprinter (M-HS 202)',    'status' => 'wartung'],
    5 => ['name' => 'Volkswagen ID.3 (M-HS 301E)',          'status' => 'verfuegbar'],
    6 => ['name' => 'Tesla Model 3 (M-HS 302E)',            'status' => 'verfuegbar'],
    7 => ['name' => 'Vespa Primavera 125 (M-HS 401)',       'status' => 'verfuegbar'],
    8 => ['name' => 'Riese & Müller Charger4 (Rad 1)',      'status' => 'verfuegbar'],
    9 => ['name' => 'Riese & Müller Charger4 (Rad 2)',      'status' => 'verfuegbar'],
];

// Meldungen aus Rückgaben (Schaden oder Bemerkung), neueste zuerst. Dieselben
// Bemerkungen wie in den Fahrten von fahrzeug.php. Fotos gibt es nur zu
// Schäden; die Dateinamen sind zufällig vergeben wie in rueckgabe.php.
$meldungen = [
    ['datum' => '30.09.2026', 'fahrzeug_id' => 8, 'fahrer' => 'Lucie Schneider', 'schaden' => false, 'bemerkung' => 'Akku nach Rückkehr wieder angeschlossen.',            'fotos' => []],
    ['datum' => '27.09.2026', 'fahrzeug_id' => 4, 'fahrer' => 'Larissa Wagner',  'schaden' => true,  'bemerkung' => 'Delle an der Schiebetür rechts, Tür schließt schwer.', 'fotos' => ['3f9c1a7e5b2d4086a1c7e9f03b6d2a58.jpg', 'b81e04d9c67a2f3e5d1b8a09c4f7e263.jpg']],
    ['datum' => '24.09.2026', 'fahrzeug_id' => 1, 'fahrer' => 'Larissa Wagner',  'schaden' => false, 'bemerkung' => 'Klappergeräusch hinten rechts bei Tempo über 100.',    'fotos' => []],
    ['datum' => '22.09.2026', 'fahrzeug_id' => 3, 'fahrer' => 'Kenneth Sander',  'schaden' => false, 'bemerkung' => 'Ladefläche verschmutzt, Spanngurt fehlt.',             'fotos' => []],
    ['datum' => '18.09.2026', 'fahrzeug_id' => 1, 'fahrer' => 'Finn Clausen',    'schaden' => false, 'bemerkung' => 'Innenraum könnte mal gereinigt werden.',              'fotos' => []],
];

$anzahlSchaeden = count(array_filter($meldungen, fn (array $m): bool => $m['schaden']));

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    Schäden und Bemerkungen aus den Rückgaben der Fahrer, neueste zuerst.
    <?= e((string) $anzahlSchaeden) ?> <?= $anzahlSchaeden === 1 ? 'Schaden' : 'Schäden' ?> gemeldet.
    Ein Fahrzeug mit Schaden steht nach der Rückgabe in Wartung, bis Sie es freigeben.
</p>

<p class="note">
    Prototyp &ndash; Beispieldaten. &bdquo;Freigeben&ldquo; führt zur Fahrzeugliste und wird dort
    geprüft und angezeigt, aber noch nicht gespeichert.
</p>

<section class="section">
    <table class="table">
        <thead>
            <tr>
                <th>Datum</th>
                <th>Fahrzeug</th>
                <th>Fahrer</th>
                <th>Art</th>
                <th>Bemerkung</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($meldungen as $meldung): ?>
                <?php $fahrzeug = $fahrzeuge[$meldung['fahrzeug_id']]; ?>
                <tr>
                    <td><?= e($meldung['datum']) ?></td>
                    <td>
                        <a href="<?= url('fahrzeug.php?id=' . $meldung['fahrzeug_id']) ?>"><?= e($fahrzeug['name']) ?></a>
                        <?php if ($fahrzeug['status'] === 'wartung'): ?>
                            <span class="table__zusatz">in Wartung</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($meldung['fahrer']) ?></td>
                    <td>
                        <?php if ($meldung['schaden']): ?>
                            <span class="badge badge--abgelehnt">Schaden</span>
                        <?php else: ?>
                            <span class="badge">Bemerkung</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e($meldung['bemerkung']) ?>

                        <?php if ($meldung['fotos'] !== []): ?>
                            <ul class="schadensfotos" aria-label="Fotos zum Schaden">
                                <?php foreach ($meldung['fotos'] as $nr => $datei): ?>
                                    <li class="schadensfotos__bild schadensfotos__bild--platzhalter">
                                        Foto <?= e((string) ($nr + 1)) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($fahrzeug['status'] === 'wartung'): ?>
                            <form method="post" action="<?= url('fahrzeuge.php') ?>">
                                <input type="hidden" name="fahrzeug" value="<?= e((string) $meldung['fahrzeug_id']) ?>">
                                <input type="hidden" name="aktion" value="freigeben">
                                <button class="button button--klein" type="submit">Freigeben</button>
                            </form>
                        <?php else: ?>
                            &ndash;
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($meldungen === []): ?>
                <tr>
                    <td colspan="6" class="table__empty">Keine Meldungen.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
