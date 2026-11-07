<?php
/**
 * Eine Liste von Nutzern, nach Nachname und Vorname sortiert.
 */

declare(strict_types=1);

class CListeNutzer
{
    private array $m_Liste = [];
    private bool $m_ListeGeladen = false;

    /**
     * Lädt alle Nutzer ohne die Ausgeschiedenen, für Anmeldung und „Buchen für“.
     */
    public function LadenAktive(): void
    {
        $this->Laden('SELECT * FROM nutzer WHERE geloescht_am IS NULL ORDER BY nachname, vorname');
    }

    /**
     * Lädt alle Nutzer mit den Ausgeschiedenen, für den Filter der Historie.
     */
    public function LadenAlle(): void
    {
        $this->Laden('SELECT * FROM nutzer ORDER BY nachname, vorname');
    }

    /**
     * Füllt die Liste einmalig mit dem Ergebnis der Abfrage.
     */
    private function Laden(string $in_SQL): void
    {
        if ($this->m_ListeGeladen) {
            return;
        }

        $Statement = db()->prepare($in_SQL);
        $Statement->execute();

        foreach ($Statement->fetchAll() as $Zeile) {
            $this->m_Liste[] = new CNutzer($Zeile);
        }

        $this->m_ListeGeladen = true;
    }

    /**
     * Alle Nutzer der Liste, für foreach.
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
     * Der i-te Nutzer, gezählt ab 0.
     */
    public function Get(int $in_i): ?CNutzer
    {
        return $this->m_Liste[$in_i] ?? null;
    }

    /**
     * Der Nutzer mit dieser ID, sonst null.
     */
    public function GetByID(int $in_ID): ?CNutzer
    {
        foreach ($this->m_Liste as $Nutzer) {
            if ($Nutzer->GetID() === $in_ID) {
                return $Nutzer;
            }
        }

        return null;
    }
}
