<?php
    // ---------------- Notas (post-its) de la ficha de proyecto ----------------
    // Mismo patron que actions/env.php: resolver la clave real del proyecto, operar, y dejar
    // $msg + $redirName para el redirect del PRG.
    if ($action === 'note_add') {
        $tab = 'proyecto'; $name = $_POST['name'] ?? ''; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        $title = notes_clean_text($_POST['title'] ?? '', NOTES_MAX_TITLE);
        $body  = notes_clean_text($_POST['body'] ?? '', NOTES_MAX_BODY);
        if ($siteKey === null) { $msg = 'error:Proyecto no válido.'; }
        elseif (trim($title) === '' && trim($body) === '') { $msg = 'error:La nota está vacía.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $notes = notes_read($ROOT, $name);
            // Al principio: la ultima nota escrita es la que se quiere ver primero, sin tener
            // que bajar hasta el final del tablero.
            array_unshift($notes, [
                'id'      => notes_new_id(),
                'title'   => $title,
                'body'    => $body,
                'color'   => notes_valid_color($_POST['color'] ?? 'amber'),
                'created' => time(),
                'updated' => 0,
            ]);
            $msg = notes_write($ROOT, $name, $notes)
                ? 'applied:Nota añadida.'
                : 'error:No se pudo guardar la nota (¿permisos en data\\notes?).';
        }
    }
    elseif ($action === 'note_save') {
        $tab = 'proyecto'; $name = $_POST['name'] ?? ''; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        $id = (string)($_POST['id'] ?? '');
        if ($siteKey === null) { $msg = 'error:Proyecto no válido.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $notes = notes_read($ROOT, $name);
            $i = notes_index_of($notes, $id);
            if ($i === null) { $msg = 'error:La nota ya no existe (¿la borraste en otra pestaña?).'; }
            // Con la nota bloqueada, el titulo/cuerpo que llega en el POST es el de los campos
            // vacios que pinta la vista (nunca se manda el contenido real de una nota bloqueada
            // al HTML) -- si se dejara seguir, "Guardar" sobrescribiria la nota real con blanco
            // de forma irrecuperable (solo se guarda el hash, no hay forma de reconstruir el
            // texto). Bloqueada = congelada hasta desbloquearla, ni titulo, ni cuerpo, ni color.
            elseif (!empty($notes[$i]['locked'])) { $msg = 'error:Esta nota está bloqueada: desbloquéala antes de editarla.'; }
            else {
                $title = notes_clean_text($_POST['title'] ?? '', NOTES_MAX_TITLE);
                $body  = notes_clean_text($_POST['body'] ?? '', NOTES_MAX_BODY);
                $color = notes_valid_color($_POST['color'] ?? $notes[$i]['color']);
                // Sin cambios reales -> no se toca el archivo ni se mueve la fecha "Editada".
                // Guardar sin querer (Ctrl+S, doble clic en Guardar) no debe reordenar nada.
                if ($title === $notes[$i]['title'] && $body === $notes[$i]['body'] && $color === $notes[$i]['color']) {
                    $msg = 'info:La nota no ha cambiado.';
                } else {
                    $notes[$i]['title'] = $title;
                    $notes[$i]['body']  = $body;
                    $notes[$i]['color'] = $color;
                    $notes[$i]['updated'] = time();
                    $msg = notes_write($ROOT, $name, $notes)
                        ? 'applied:Nota guardada.'
                        : 'error:No se pudo guardar la nota (¿permisos en data\\notes?).';
                }
            }
        }
    }
    elseif ($action === 'note_delete') {
        $tab = 'proyecto'; $name = $_POST['name'] ?? ''; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        $id = (string)($_POST['id'] ?? '');
        if ($siteKey === null) { $msg = 'error:Proyecto no válido.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $notes = notes_read($ROOT, $name);
            $i = notes_index_of($notes, $id);
            if ($i === null) { $msg = 'error:La nota ya no existe.'; }
            else {
                array_splice($notes, $i, 1);
                $msg = notes_write($ROOT, $name, $notes)
                    ? 'applied:Nota eliminada.'
                    : 'error:No se pudo eliminar la nota (¿permisos en data\\notes?).';
            }
        }
    }
    elseif ($action === 'note_lock_set') {
        $tab = 'proyecto'; $name = $_POST['name'] ?? ''; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        $id = (string)($_POST['id'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        if ($siteKey === null) { $msg = 'error:Proyecto no válido.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $notes = notes_read($ROOT, $name);
            $i = notes_index_of($notes, $id);
            if ($i === null) { $msg = 'error:La nota ya no existe.'; }
            elseif (trim($pass) === '') { $msg = 'error:Escribe una contraseña.'; }
            else {
                $notes[$i]['locked'] = true;
                $notes[$i]['hash'] = notes_hash_pass($pass);
                $msg = notes_write($ROOT, $name, $notes)
                    ? 'applied:Nota bloqueada.'
                    : 'error:No se pudo guardar la nota (¿permisos en data\\notes?).';
            }
        }
    }
    elseif ($action === 'note_lock_remove') {
        $tab = 'proyecto'; $name = $_POST['name'] ?? ''; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        $id = (string)($_POST['id'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        if ($siteKey === null) { $msg = 'error:Proyecto no válido.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $notes = notes_read($ROOT, $name);
            $i = notes_index_of($notes, $id);
            if ($i === null) { $msg = 'error:La nota ya no existe.'; }
            elseif (empty($notes[$i]['locked'])) { $msg = 'info:Esta nota no está bloqueada.'; }
            elseif (!notes_check_pass($notes[$i], $pass)) { $msg = 'error:Contraseña incorrecta.'; }
            else {
                $notes[$i]['locked'] = false;
                $notes[$i]['hash'] = '';
                $msg = notes_write($ROOT, $name, $notes)
                    ? 'applied:Nota desbloqueada.'
                    : 'error:No se pudo guardar la nota (¿permisos en data\\notes?).';
            }
        }
    }
    elseif ($action === 'note_reorder') {
        // Disparado por drag&drop (JS ya movio las tarjetas en el DOM): solo persiste el orden
        // nuevo, siempre por ajax=1 -- nunca navega, para no interrumpir el gesto de arrastrar.
        $tab = 'proyecto'; $name = $_POST['name'] ?? ''; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        if ($siteKey === null) { $msg = 'error:Proyecto no válido.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $notes = notes_read($ROOT, $name);
            $order = array_filter(explode(',', (string)($_POST['order'] ?? '')), function($v){ return $v !== ''; });
            $byId = [];
            foreach ($notes as $n) { $byId[$n['id']] = $n; }
            $new = [];
            foreach ($order as $oid) {
                if (isset($byId[$oid])) { $new[] = $byId[$oid]; unset($byId[$oid]); }
            }
            // Cualquier nota que faltara en 'order' (JS desincronizado, otra pestaña abierta a
            // la vez) se añade al final en vez de perderse silenciosamente.
            foreach ($byId as $rest) { $new[] = $rest; }
            $msg = notes_write($ROOT, $name, $new)
                ? 'applied:Orden guardado.'
                : 'error:No se pudo guardar el orden (¿permisos en data\\notes?).';
        }
    }
