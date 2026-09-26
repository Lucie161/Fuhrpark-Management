<?php
/**
 * Fußbereich jeder Seite: schließt die Layout-Container und lädt das Skript.
 */

declare(strict_types=1);
?>
</main>

<footer class="site-footer">
    <div class="container">
        <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></p>
    </div>
</footer>

<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
