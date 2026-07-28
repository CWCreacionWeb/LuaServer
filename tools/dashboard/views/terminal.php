  <?php if ($tab==='terminal'): /* ---------- PESTAÑA TERMINAL ---------- */
      $termOn = is_file($ROOT.'/config/terminal.on'); ?>

    <?php if (!$termOn): ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:6px">La terminal está desactivada</div>
        <div class="muted" style="margin-bottom:14px">Por seguridad, la terminal viene apagada. Permite ejecutar cualquier comando en esta máquina con los permisos de Apache. Actívala solo si confías en quién puede acceder a este panel.</div>
        <form method="post">
          <input type="hidden" name="action" value="terminal">
          <input type="hidden" name="enable" value="1">
          <button class="btn" type="submit">Activar terminal</button>
        </form>
      </div>
    <?php else: ?>
      <?= render_terminal_widget('term', term_default_cwd($ROOT)) ?>
      <div class="muted" style="margin-top:10px;font-size:12px">
        Sesión con directorio persistente (<code>cd</code> se mantiene). No es un PTY:
        programas interactivos a pantalla completa (vim, nano, prompts) no funcionan.
        Historial con ↑/↓ · Ctrl+L limpia.
      </div>
    <?php endif; ?>


<?php endif; ?>
