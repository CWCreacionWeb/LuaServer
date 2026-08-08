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
