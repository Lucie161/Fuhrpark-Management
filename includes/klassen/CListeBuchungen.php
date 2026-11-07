<?php
/**
 * Eine Liste von Buchungen.
 */

declare(strict_types=1);

class CListeBuchungen
{
    // Belegende Buchungen im Zeitraum. Eine überfällige Fahrt (unterwegs, Ende
    // vorbei) belegt das Fahrzeug weiter; sie zählt nur, wenn der Zeitraum
    // spätestens heute beginnt, denn wann sie endet, ist unbekannt.
    private const SQL_BELEGUNG =
        ' WHERE b.status_id <= ?
            AND b.start <= ?
            AND (b.ende >= ? OR (b.status_id = ? AND ? <= CURDATE()))';

    private array $m_Liste = [];
    private bool $m_ListeGeladen = false;

    /**
     * Lädt die laufenden Buchungen des Nutzers, als Antragsteller oder Fahrer,
     * die nächste zuerst.
     */
    public function LadenAktuelle(int $in_NutzerID): void
    {
        $this->Laden(
            CBuchung::SQL_MIT_NAMEN . ' WHERE (b.antragsteller_id = ? OR b.fahrer_id = ?) AND b.status_id <= ?
             ORDER BY b.start, b.id',
            [$in_NutzerID, $in_NutzerID, STATUS_UNTERWEGS]
        );
    }

    /**
     * Lädt die erledigten Buchungen des Nutzers (abgeschlossen, abgelehnt,
     * storniert), die neueste zuerst.
     */
    public function LadenFruehere(int $in_NutzerID): void
    {
        $this->Laden(
            CBuchung::SQL_MIT_NAMEN . ' WHERE (b.antragsteller_id = ? OR b.fahrer_id = ?) AND b.status_id >= ?
             ORDER BY b.start DESC, b.id DESC',
            [$in_NutzerID, $in_NutzerID, STATUS_ABGESCHLOSSEN]
        );
    }

    /**
     * Lädt die laufenden Fahrten des Fahrers (unterwegs), für die Rückgabe,
     * die älteste zuerst.
     */
    public function LadenLaufendeFahrten(int $in_FahrerID): void
    {
        $this->Laden(
            CBuchung::SQL_OHNE_NAMEN . ' WHERE b.fahrer_id = ? AND b.status_id = ? ORDER BY b.start, b.id',
            [$in_FahrerID, STATUS_UNTERWEGS]
        );
    }

    /**
     * Lädt die offenen Anträge für den Fuhrparkleiter, die ältesten zuerst.
     */
    public function LadenOffeneAntraege(): void
    {
        $this->Laden(
            CBuchung::SQL_MIT_NAMEN . ' WHERE b.status_id = ? ORDER BY b.beantragt_am, b.id',
            [STATUS_OFFEN]
        );
    }

    /**
     * Lädt alle überfälligen Rückgaben, die älteste zuerst. Namen nur für den
     * Fuhrparkleiter.
     */
    public function LadenUeberfaellige(bool $in_MitNamen = false): void
    {
        $this->Laden(
            self::GetSelect($in_MitNamen) . ' WHERE b.status_id = ? AND b.ende < CURDATE() ORDER BY b.ende, b.id',
            [STATUS_UNTERWEGS]
        );
    }

    /**
     * Lädt die Belegung aller Fahrzeuge im Zeitraum. Namen nur für den
     * Fuhrparkleiter.
     */
    public function LadenBelegung(
        DateTimeImmutable $in_Von,
        DateTimeImmutable $in_Bis,
        bool $in_MitNamen = false
    ): void {
        $this->Laden(
            self::GetSelect($in_MitNamen) . self::SQL_BELEGUNG . ' ORDER BY b.start, b.id',
            self::GetBelegungParameter($in_Von, $in_Bis)
        );
    }

    /**
     * Lädt nur die eigenen Buchungen im Zeitraum, für den Kalender der
     * Mitarbeiter. Fremde Buchungen werden gar nicht erst abgefragt.
     */
    public function LadenEigeneBelegung(int $in_NutzerID, DateTimeImmutable $in_Von, DateTimeImmutable $in_Bis): void
    {
        $this->Laden(
            CBuchung::SQL_MIT_NAMEN . self::SQL_BELEGUNG . ' AND (b.antragsteller_id = ? OR b.fahrer_id = ?)
             ORDER BY b.start, b.id',
            [...self::GetBelegungParameter($in_Von, $in_Bis), $in_NutzerID, $in_NutzerID]
        );
    }

    /**
     * Lädt alle belegenden Buchungen, die heute oder später enden, dazu jede
     * laufende Fahrt, auch eine überfällige. Für fahrzeuge.php, was
     * eine neue Wartung betrifft. Namen nur für den Fuhrparkleiter.
     */
    public function LadenAbHeute(bool $in_MitNamen = false): void
    {
        $this->Laden(
            self::GetSelect($in_MitNamen) . ' WHERE b.status_id <= ? AND (b.ende >= CURDATE() OR b.status_id = ?)
             ORDER BY b.start, b.id',
            [STATUS_UNTERWEGS, STATUS_UNTERWEGS]
        );
    }

    /**
     * Lädt das Fahrtenbuch eines Fahrzeugs: seine abgeschlossenen Fahrten, die
     * zuletzt zurückgegebene zuerst. Namen nur für den Fuhrparkleiter.
     */
    public function LadenFahrtenbuch(int $in_FahrzeugID, bool $in_MitNamen = false): void
    {
        $this->Laden(
            self::GetSelect($in_MitNamen) . ' WHERE b.fahrzeug_id = ? AND b.status_id = ?
             ORDER BY b.zurueckgegeben_am DESC, b.id DESC',
            [$in_FahrzeugID, STATUS_ABGESCHLOSSEN]
        );
    }

    /**
     * Lädt die abgeschlossenen Fahrten aller Fahrzeuge, die im Zeitraum
     * zurückgegeben wurden, die zuletzt zurückgegebene zuerst. Mit Namen, für
     * die Historie des Fuhrparkleiters.
     */
    public function LadenHistorie(DateTimeImmutable $in_Von, DateTimeImmutable $in_Bis): void
    {
        $this->Laden(
            CBuchung::SQL_MIT_NAMEN . ' WHERE b.status_id = ? AND DATE(b.zurueckgegeben_am) BETWEEN ? AND ?
             ORDER BY b.zurueckgegeben_am DESC, b.id DESC',
            [STATUS_ABGESCHLOSSEN, $in_Von->format('Y-m-d'), $in_Bis->format('Y-m-d')]
        );
    }

    private static function GetSelect(bool $in_MitNamen): string
    {
        return $in_MitNamen ? CBuchung::SQL_MIT_NAMEN : CBuchung::SQL_OHNE_NAMEN;
    }

    /**
     * Die Parameter zu SQL_BELEGUNG.
     */
    private static function GetBelegungParameter(DateTimeImmutable $in_Von, DateTimeImmutable $in_Bis): array
    {
        $Von = $in_Von->format('Y-m-d');

        return [STATUS_UNTERWEGS, $in_Bis->format('Y-m-d'), $Von, STATUS_UNTERWEGS, $Von];
    }

    /**
     * Füllt die Liste einmalig mit dem Ergebnis der Abfrage.
     */
    private function Laden(string $in_SQL, array $in_Parameter): void
    {
        if ($this->m_ListeGeladen) {
            return;
        }

        $Statement = db()->prepare($in_SQL);
        $Statement->execute($in_Parameter);

        foreach ($Statement->fetchAll() as $Zeile) {
            $this->m_Liste[] = new CBuchung($Zeile);
        }

        $this->m_ListeGeladen = true;
    }

    /**
     * Alle Buchungen der Liste, für foreach.
     */
    public function GetAlle(): array
    {
        return $this->m_Liste;
    }

    public function Count(): int
    {
        return count($this->m_Liste);
    }

    /**
     * Die i-te Buchung, gezählt ab 0.
     */
    public function Get(int $in_i): ?CBuchung
    {
        return $this->m_Liste[$in_i] ?? null;
    }

    /**
     * Die Buchung mit dieser ID, sonst null.
     */
    public function GetByID(int $in_ID): ?CBuchung
    {
        foreach ($this->m_Liste as $Buchung) {
            if ($Buchung->GetID() === $in_ID) {
                return $Buchung;
            }
        }

        return null;
    }

    /**
     * Die IDs der Fahrzeuge in der Liste, z. B. um belegte Fahrzeuge zu finden.
     */
    public function GetFahrzeugIDs(): array
    {
        return array_map(fn (CBuchung $Buchung): int => $Buchung->GetFahrzeugID(), $this->m_Liste);
    }
}
