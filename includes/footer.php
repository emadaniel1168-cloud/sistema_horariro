        </main>
    </div>
</div>
<div class="modal-overlay" id="modal">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-head">
            <h2 id="modal-title">Formulario</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Cerrar">✕</button>
        </div>
        <div class="modal-body" id="modal-body"></div>
    </div>
</div>
<script src="<?= e(APP_BASE) ?>/js/app.js?v=<?= (int) filemtime(dirname(__DIR__) . '/js/app.js') ?>-<?= (int) filesize(dirname(__DIR__) . '/js/app.js') ?>"></script>
</body>
</html>
