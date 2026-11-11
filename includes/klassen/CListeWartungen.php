<?php
/**
 * Eine Liste von Wartungen.
 */

declare(strict_types=1);

class CListeWartungen
{
    private array $m_Liste = [];
    private bool $m_ListeGeladen = false;

    /**
     * Lädt alle Wartungen eines Fahrzeugs für den Steckbrief, die neueste
     * zuerst.
     */
    public function LadenZuFahrzeug(int $in_FahrzeugID): void
    {
        $this->Laden(
            'SELECT * FROM wartungen WHERE fahrzeug_id = ? ORDER BY begonnen_am DESC, id DESC',
            [$in_FahrzeugID]
        );
    }

    /**
     * Lädt die Wartungen, die den Zeitraum berühren, für Kalender und
     * Historie. Eine laufende Wartung berührt jeden Zeitraum ab ihrem Beginn.
     */
    public function LadenImZeitraum(DateTimeImmutable $in_Von, DateTimeImmutable $in_Bis): void
    {
        $this->Laden(
            'SELECT * FROM wartungen
              WHERE begonnen_am <= ? AND (freigegeben_am IS NULL OR freigegeben_am >= ?)
              ORDER BY begonnen_am, id',
            [$in_Bis->format('Y-m-d'), $in_Von->format('Y-m-d')]
        );
    }

    /**
     * Lädt die neuen Schäden für die Übersicht: laufende Wartungen ohne Ende.
     * Die gibt es nur nach einer Rückgabe mit Schaden; neu ist der Schaden,
     * bis der Fuhrparkleiter ein Wartungsende einträgt.
     */
    public function LadenNeueSchaeden(): void
    {
        $this->Laden(
            'SELECT * FROM wartungen
              WHERE freigegeben_am IS NULL AND voraussichtlich_bis IS NULL
              ORDER BY begonnen_am, id',
            []
        );
    }

    /**
     * Lädt die laufenden Wartungen, deren voraussichtliches Ende vorbei ist,
     * ohne dass das Fahrzeug freigegeben wurde. Für den Hinweis auf der
     * Übersicht.
     */
    public function LadenEndeUeberschritten(): void
    {
        $this->Laden(
            'SELECT * FROM wartungen
              WHERE freigegeben_am IS NULL AND voraussichtlich_bis < CURDATE()
              ORDER BY voraussichtlich_bis, id',
            []
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
            $this->m_Liste[] = new CWartung($Zeile);
        }

        $this->m_ListeGeladen = true;
    }

    /**
     * Alle Wartungen der Liste, für foreach.
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
     * Die i-te Wartung, gezählt ab 0.
     */
    public function Get(int $in_i): ?CWartung
    {
        return $this->m_Liste[$in_i] ?? null;
    }

    /**
     * Die Wartung mit dieser ID, sonst null.
     */
    public function GetByID(int $in_ID): ?CWartung
    {
        foreach ($this->m_Liste as $Wartung) {
            if ($Wartung->GetID() === $in_ID) {
                return $Wartung;
            }
        }

        return null;
    }
}
