<?php
/**
 * Verstoß gegen eine Fachregel beim Speichern, z. B. „Zeitraum nicht mehr frei“.
 *
 * Ändernde Methoden der Fachklassen werfen ihn, die Seite fängt ihn und zeigt
 * den Text an. Die Meldung ist deshalb immer ein Text für den Nutzer:
 *   try {
 *       $Buchung->Stornieren($ich);
 *   } catch (CFachlicherFehler $Fehler) {
 *       merke_meldung($Fehler->getMessage(), 'hinweis');
 *   }
 */

declare(strict_types=1);

class CFachlicherFehler extends Exception
{
}
