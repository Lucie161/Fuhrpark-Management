/**
 * Allgemeines Skript der Anwendung.
 * Aufklappen der Navigation auf schmalen Bildschirmen und die Rollenauswahl.
 */

document.addEventListener('DOMContentLoaded', function () {
    // Rollenauswahl (nur Prototyp): wechselt sofort beim Auswählen. Ohne
    // JavaScript bleibt der Knopf „Wechseln“ sichtbar.
    var rollenwahl = document.querySelector('.rollenwahl');

    if (rollenwahl) {
        rollenwahl.querySelector('.rollenwahl__knopf').hidden = true;
        rollenwahl.querySelector('select').addEventListener('change', function () {
            rollenwahl.submit();
        });
    }

    var toggle = document.querySelector('.nav-toggle');
    var nav = document.getElementById('hauptnavigation');

    if (!toggle || !nav) {
        return;
    }

    toggle.addEventListener('click', function () {
        var offen = nav.classList.toggle('nav--offen');
        toggle.setAttribute('aria-expanded', offen ? 'true' : 'false');
    });
});
