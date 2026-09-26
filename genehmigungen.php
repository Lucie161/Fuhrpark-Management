<?php
/**
 * Anträge genehmigen (Anwendungsfall 7) — nur für den Fuhrparkleiter.
 *
 * Prototyp ohne Funktion: Die Liste zeigt feste Beispieldaten.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Anträge genehmigen';

// Offene Anträge, älteste zuerst.
$antraege = [
    ['antragsteller' => 'Ella Luppold',    'fahrzeug' => 'Ford Transit (M-HS 201)',          'beginn' => '28.09.2026', 'ende' => '28.09.2026', 'zweck' => 'Montage PV-Anlage, Baustelle Lindenweg 4', 'personen' => 3, 'beantragt_am' => '23.09.2026, 14:12'],
    ['antragsteller' => 'Kenneth Sander',  'fahrzeug' => 'VW ID.3 (M-HS 301E)',              'beginn' => '29.09.2026', 'ende' => '29.09.2026', 'zweck' => 'Kundentermin Angebotsbesprechung',         'personen' => 1, 'beantragt_am' => '24.09.2026, 08:45'],
    ['antragsteller' => 'Finn Clausen',    'fahrzeug' => 'VW Passat Variant (M-HS 101)',     'beginn' => '30.09.2026', 'ende' => '01.10.2026', 'zweck' => 'Wartung Wechselrichter, zwei Standorte',   'personen' => 2, 'beantragt_am' => '25.09.2026, 16:30'],
    ['antragsteller' => 'Lucie Schneider', 'fahrzeug' => 'Riese & Müller Charger4 (Rad 1)', 'beginn' => '29.09.2026', 'ende' => '29.09.2026', 'zweck' => 'Aufmaß Dachfläche, Innenstadt',            'personen' => 1, 'beantragt_am' => '26.09.2026, 10:05'],
];

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    <?= count($antraege) ?> offene Anträge warten auf Ihre Entscheidung.
</p>

<p class="note">
    Prototyp &ndash; Beispieldaten, noch ohne Funktion.
</p>

<table class="table">
    <thead>
        <tr>
            <th>Beantragt am</th>
            <th>Antragsteller</th>
            <th>Fahrzeug</th>
            <th>Zeitraum</th>
            <th>Zweck</th>
            <th class="table__num">Personen</th>
            <th>Entscheidung</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($antraege as $antrag): ?>
            <tr>
                <td><?= e($antrag['beantragt_am']) ?></td>
                <td><?= e($antrag['antragsteller']) ?></td>
                <td><?= e($antrag['fahrzeug']) ?></td>
                <td>
                    <?= e($antrag['beginn']) ?><br>
                    bis <?= e($antrag['ende']) ?>
                </td>
                <td><?= e($antrag['zweck']) ?></td>
                <td class="table__num"><?= e((string) $antrag['personen']) ?></td>
                <td>
                    <div class="aktionen">
                        <button class="button button--klein" type="button">Genehmigen</button>

                        <details class="ablehnen">
                            <summary class="button button--klein button--gefahr">Ablehnen</summary>

                            <div class="ablehnen__form">
                                <label class="form__label">
                                    Begründung
                                    <textarea class="form__input" rows="3"></textarea>
                                </label>
                                <button class="button button--klein button--gefahr" type="button">Ablehnung speichern</button>
                            </div>
                        </details>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
