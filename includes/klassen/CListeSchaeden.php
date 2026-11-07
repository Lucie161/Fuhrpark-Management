<?php
/**
 * Eine Liste von Schäden.
 */

declare(strict_types=1);

class CListeSchaeden
{
    private array $m_Liste = [];
    private bool $m_ListeGeladen = false;

    /**
     * Lädt die offenen Schäden eines Fahrzeugs, der neueste zuerst. Für den
     * Steckbrief und „Fahrt beginnen“, ohne Namen.
     */
    public function LadenOffene(int $in_FahrzeugID): void
    {
        $this->Laden(
            CSchaden::SQL_OHNE_NAMEN . ' WHERE b.fahrzeug_id = ? AND s.behoben_am IS NULL
             ORDER BY s.gemeldet_am DESC, s.id DESC',
            [$in_FahrzeugID]
        );
    }

    /**
     * Lädt alle Schäden für schaeden.php: zuerst die offenen, dann die
     * behobenen, jeweils der neueste zuerst. Mit Namen, nur für den
     * Fuhrparkleiter.
     */
    public function LadenAlle(): void
    {
        $this->Laden(
            CSchaden::SQL_MIT_NAMEN . ' ORDER BY s.behoben_am IS NOT NULL, s.gemeldet_am DESC, s.id DESC',
            []
        );
    }

    /**
     * Lädt alle Schäden eines Fahrzeugs, für sein Fahrtenbuch. Ohne Namen.
     */
    public function LadenZuFahrzeug(int $in_FahrzeugID): void
    {
        $this->Laden(
            CSchaden::SQL_OHNE_NAMEN . ' WHERE b.fahrzeug_id = ? ORDER BY s.gemeldet_am DESC, s.id DESC',
            [$in_FahrzeugID]
        );
    }

    /**
     * Lädt die Schäden aus den Rückgaben im Zeitraum, für die Historie. Wie
     * dort zählt der Tag der Rückgabe.
     */
    public function LadenRueckgabenImZeitraum(DateTimeImmutable $in_Von, DateTimeImmutable $in_Bis): void
    {
        $this->Laden(
            CSchaden::SQL_OHNE_NAMEN . ' WHERE s.anlass_id = ? AND DATE(b.zurueckgegeben_am) BETWEEN ? AND ?
             ORDER BY s.gemeldet_am DESC, s.id DESC',
            [ANLASS_RUECKGABE, $in_Von->format('Y-m-d'), $in_Bis->format('Y-m-d')]
        );
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
            $this->m_Liste[] = new CSchaden($Zeile);
        }

        $this->m_ListeGeladen = true;
    }

    /**
     * Wie viele offene Schäden der Liste halten ihr Fahrzeug in Wartung? Für
     * die Übersicht und schaeden.php.
     */
    public function CountSperrende(): int
    {
        $Sperrende = array_filter(
            $this->m_Liste,
            fn (CSchaden $Schaden): bool => $Schaden->IstOffen() && $Schaden->HaeltInWartung()
        );

        return count($Sperrende);
    }

    /**
     * Der Schaden, der bei der Rückgabe dieser Buchung gemeldet wurde, sonst
     * null. Je Rückgabe gibt es höchstens einen.
     */
    public function GetBeiRueckgabe(int $in_BuchungID): ?CSchaden
    {
        foreach ($this->m_Liste as $Schaden) {
            if ($Schaden->GetBuchungID() === $in_BuchungID && $Schaden->GetAnlassID() === ANLASS_RUECKGABE) {
                return $Schaden;
            }
        }

        return null;
    }

    /**
     * Alle Schäden der Liste, für foreach.
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
     * Der i-te Schaden, gezählt ab 0.
     */
    public function Get(int $in_i): ?CSchaden
    {
        return $this->m_Liste[$in_i] ?? null;
    }

    /**
     * Der Schaden mit dieser ID, sonst null.
     */
    public function GetByID(int $in_ID): ?CSchaden
    {
        foreach ($this->m_Liste as $Schaden) {
            if ($Schaden->GetID() === $in_ID) {
                return $Schaden;
            }
        }

        return null;
    }
}
