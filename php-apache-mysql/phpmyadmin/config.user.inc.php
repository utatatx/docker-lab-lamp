<?php

declare(strict_types=1);

/**
 * Configuración adicional de phpMyAdmin.
 *
 * Este fichero se monta en /etc/phpmyadmin/config.user.inc.php y lo
 * incluye phpMyAdmin al final de su config.inc.php.
 *
 * Declara 'db' como host de confianza. MySQL 8.4 ya tiene SSL
 * disponible con certificados autogenerados, pero este tramo
 * (phpMyAdmin -> MySQL) viaja por la red interna 'appnet', que
 * Docker marca como internal: true y por tanto no sale del host.
 * Es la situacion que el propio phpMyAdmin describe como
 * 'local connection or private network'.
 *
 * OJO: esto SILENCIA el aviso, no cifra el trafico. El tramo que
 * si va cifrado es el de la aplicacion web (ver src/index.php).
 * Para cifrar tambien este habria que montar la CA de MySQL y
 * activar PMA_SSL + PMA_SSL_CA en el compose.
 *
 * Se asigna el array completo en lugar de hacer append para no
 * depender del orden en que phpMyAdmin cargue sus valores por
 * defecto, y para no perder 127.0.0.1 ni localhost.
 */

$cfg['MysqlSslWarningSafeHosts'] = ['127.0.0.1', 'localhost', 'db'];
