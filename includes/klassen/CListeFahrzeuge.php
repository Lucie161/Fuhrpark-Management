<?php
/**
 * Eine Liste von Fahrzeugen.
 */

declare(strict_types=1);

class CListeFahrzeuge
{
    // Erlaubte Sortierungen für LadenMitFilter(), zugleich die Spalten in
    // ORDER BY. Eine Spalte lässt sich nicht als Parameter binden, deshalb
    // kommt sie nur aus dieser Liste.
    public const SORTIERUNGEN = ['kennzeichen', 'kmstand', 'hu_au', 'baujahr'];

    private array $m_Liste = [];
    private bool $m_ListeGeladen = false;

    /**
     * Lädt die Fahrzeuge im Bestand, ohne ausgemusterte, nach ID. Für
     * Kalender, Übersicht und die Zahl der Fahrzeuge.
     */
    public function LadenImBestand(): void
    {
        $this->Laden(CFahrzeug::SQL . ' WHERE f.geloescht_am IS NULL ORDER BY f.id', []);
    }

    /**
     * Lädt alle Fahrzeuge mit den ausgemusterten, für die Filter der Historie.
     */
    public function LadenAlle(): void
    {
        $this->Laden(CFahrzeug::SQL . ' ORDER BY f.id', []);
    }

    /**
     * Lädt die Fahrzeuge im Bestand, die für die Suche in buchen.php in Frage
     * kommen: genug Sitzplätze, die gewählte Art (0 = alle) und passend zum
     * Zweck. Ob sie im Zeitraum frei sind, prüft die Seite.
     */
    public function LadenFuerBuchung(int $in_Personen, int $in_ArtID, int $in_ZweckID): void
    {
        // Sitzplätze immer, die Art nur, wenn eine gewählt ist
        $SQL = CFahrzeug::SQL . ' WHERE f.geloescht_am IS NULL AND f.sitzplaetze >= ?';
        $Parameter = [$in_Personen];

        if ($in_ArtID !== 0) {
            $SQL .= ' AND f.art_id = ?';
            $Parameter[] = $in_ArtID;
        }

        $this->Laden($SQL . ' ORDER BY f.id', $Parameter);

        // Ob der Zweck passt, entscheidet CBuchung, deshalb erst nach dem Laden
        $this->Filtern(fn (CFahrzeug $Fahrzeug): bool => CBuchung::PasstZweck($in_ZweckID, $Fahrzeug->GetArtID()));
    }

    /**
     * Lädt die Fahrzeuge im Bestand für fahrzeuge.php.
     *
     * Die Art (0 = alle) filtert die Datenbank, Status und HU/AU danach PHP,
     * denn beides wird berechnet. $in_Status ist '' (alle), 'verfuegbar',
     * 'unterwegs' oder 'wartung'; 'unterwegs' zählt auch Fahrzeuge, die
     * zugleich in Wartung sind. Ohne Wert (Fahrrad: kein km-Stand, keine
     * HU/AU) stehen die Fahrzeuge in beiden Richtungen am Ende, bei
     * Gleichstand entscheidet das Kennzeichen.
     */
    public function LadenMitFilter(
        int $in_ArtID,
        string $in_Status,
        bool $in_NurHuAuFaellig,
        string $in_Sortierung,
        bool $in_Absteigend
    ): void {
        // Die Spalte kommt direkt in den SQL-Text, deshalb nur aus der festen
        // Liste. Die Seite prüft das schon; das hier fängt Programmierfehler ab.
        if (!in_array($in_Sortierung, self::SORTIERUNGEN, true)) {
            throw new InvalidArgumentException('Unbekannte Sortierung: ' . $in_Sortierung);
        }

        // Nur Fahrzeuge im Bestand, die Art nur, wenn eine gewählt ist
        $SQL = CFahrzeug::SQL . ' WHERE f.geloescht_am IS NULL';
        $Parameter = [];

        if ($in_ArtID !== 0) {
            $SQL .= ' AND f.art_id = ?';
            $Parameter[] = $in_ArtID;
        }

        // Sortieren: „spalte IS NULL“ ist 0 mit Wert und 1 ohne, so stehen die
        // Fahrzeuge ohne Wert immer am Ende. Danach die Spalte in der
        // gewählten Richtung, bei Gleichstand das Kennzeichen.
        $Spalte = 'f.' . $in_Sortierung;
        $SQL .= ' ORDER BY ' . $Spalte . ' IS NULL, ' . $Spalte . ($in_Absteigend ? ' DESC' : ' ASC') . ', f.kennzeichen';

        $this->Laden($SQL, $Parameter);

        // Status und HU/AU werden berechnet und erst jetzt gefiltert
        $this->Filtern(fn (CFahrzeug $Fahrzeug): bool =>
            match ($in_Status) {
                ''          => true,
                'unterwegs' => $Fahrzeug->IstUnterwegs(),
                default     => $Fahrzeug->GetStand() === $in_Status,
            }
            && (!$in_NurHuAuFaellig || $Fahrzeug->GetHuAuStand() !== null)
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
            $this->m_Liste[] = new CFahrzeug($Zeile);
        }

        $this->m_ListeGeladen = true;
    }

    /**
     * Behält nur die Fahrzeuge, für die $in_Bedingung zutrifft.
     */
    private function Filtern(callable $in_Bedingung): void
    {
        $this->m_Liste = array_values(array_filter($this->m_Liste, $in_Bedingung));
    }

    /**
     * Alle Fahrzeuge der Liste, für foreach.
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
     * Das i-te Fahrzeug, gezählt ab 0.
     */
    public function Get(int $in_i): ?CFahrzeug
    {
        return $this->m_Liste[$in_i] ?? null;
    }

    /**
     * Das Fahrzeug mit dieser ID, sonst null.
     */
    public function GetByID(int $in_ID): ?CFahrzeug
    {
        foreach ($this->m_Liste as $Fahrzeug) {
            if ($Fahrzeug->GetID() === $in_ID) {
                return $Fahrzeug;
            }
        }

        return null;
    }
}
