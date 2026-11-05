<?php
/**
 * Eine Liste von Buchungen.
 *
 * Erst eine der Laden-Methoden aufrufen, dann zugreifen:
 *   $ListeAntraege = new CListeBuchungen();
 *   $ListeAntraege->LadenOffeneAntraege();
 *   foreach ($ListeAntraege->GetAlle() as $Antrag) { … }
 *
 * Personennamen lädt nur, was der Fuhrparkleiter oder der Nutzer selbst für
 * seine eigenen Buchungen sieht (siehe CBuchung).
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
