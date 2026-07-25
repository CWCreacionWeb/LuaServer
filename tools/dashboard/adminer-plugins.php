<?php

// Permite entrar a Adminer con contraseña vacía (root sin clave en MariaDB/PostgreSQL)
// solo cuando la petición viene del propio equipo. Ver tools/dashboard/adminer-plugins/login-ip.php
return array(
	new AdminerLoginIp(array('127.0.0.1', '::1')),
);
