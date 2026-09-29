# 🐳 Lab Docker: PHP 8.5 + Apache + MySQL + Caddy

Laboratorio autoalojado con arquitectura de **multi-proyecto Docker Compose**: un reverse proxy **Caddy** centralizado que sirve un stack **PHP 8.5 + Apache (mod_php) + MySQL 8.4**, con DNS local opcional vía **Pi-hole**.

Proyecto realizado para la asignatura de _Implantación de Aplicaciones Web_ (RA1), CFGS ASIR.

> [!IMPORTANT]
> Configurado para desplegar en un entorno real, no solo en local. Las decisiones de seguridad que lo sostienen están documentadas en [`SECURITY.md`](SECURITY.md).

---

## 🔐 Postura de seguridad

Todo el tráfico entra por un único punto: Caddy en 443. El resto no publica puertos.

| Servicio | Puerto en el host | Quién lo alcanza |
|---|---|---|
| `caddy` | 80, 443 (TCP y UDP) | Cualquiera, siempre por TLS |
| `web` | `127.0.0.1:8080` | Solo el propio servidor (depuración) |
| `db` | **ninguno** | Solo la red interna `appnet` |
| `phpmyadmin` | **ninguno** | Solo Caddy, y con contraseña |

Decisiones que sostienen lo anterior:

- **`phpinfo()` fuera del aire.** La raíz del sitio es una página de estado que solo comprueba la conexión a la base de datos. `phpinfo()` imprime las variables de entorno, **incluidas las contraseñas**; solo se activa con `PHPINFO=1` y nunca debería estar activo en un entorno accesible.
- **MySQL no sale de `appnet`.** La red es `internal: true`, así que la base de datos no tiene salida a Internet y no es alcanzable desde `proxy-net`, la red que comparten los demás proyectos.
- **Las credenciales no tienen valor por defecto.** Se usan `${DB_PASSWORD:?...}`, que **aborta el arranque** si falta la variable, en lugar de levantar MySQL con una contraseña conocida y publicada en este repositorio.
- **Los secretos no se suben.** `.env` está en `.gitignore`; `.dockerignore` lo saca del contexto de build para que no acabe en una capa de la imagen. Solo se versionan los `.env.example`.
- **La API de administración de Caddy** está atada a `localhost:2019` dentro del contenedor. Por defecto Caddy la deja escuchando en la red, y eso permite reconfigurar el proxy a cualquiera conectado a `proxy-net`.
- **phpMyAdmin** va tras Caddy con autenticación básica y con `PMA_ARBITRARY=0` (desactiva el "servidor arbitrario", que permitiría apuntarlo a otros hosts).

---

## 📐 Arquitectura

```
                    ┌──────────────────────────────────────────┐
  cliente HTTPS ──▶ │  Caddy  (proyecto: caddy/)               │
  php.lab.local     │  - 80 (redirect) · 443 TCP/UDP (HTTP/3)  │
                    │  - CA propia ("Caddy Local Authority")   │
                    │  - admin API → solo loopback             │
                    └───┬──────────────────────────┬───────────┘
                        │                          │
            proxy-net    │                          │ proxy-net
                        ▼                          ▼
  ┌───────────────────────────────────┐   ┌──────────────────────┐
  │  web   (php-apache-mysql/)        │   │  phpmyadmin          │
  │  php:8.5-apache (mod_php)         │◀──│  perfil "admin"      │
  │  código montado en solo lectura   │   │  solo por Caddy      │
  └───────────────┬───────────────────┘   └──────────┬───────────┘
                  │  appnet (internal: true)          │
                  ▼                                   ▼
            ┌──────────────────────────────────────────────┐
            │  db  mysql:8.4   · sin puertos · sin salida   │
            │  a Internet · volumen dbdata                  │
            └──────────────────────────────────────────────┘
```

Cada subcarpeta es un **proyecto Compose independiente**, unidos por la red externa `proxy-net`. Se pueden añadir más proyectos (Pi-hole, otros stacks) detrás del mismo Caddy sin tocar la configuración existente.

## 📁 Estructura

```
docker/
├── .editorconfig
├── .gitignore                # ignora .env, certificados, respaldos
├── LICENSE
├── SECURITY.md               # política de seguridad y rotación de secretos
├── README.md
├── caddy/
│   ├── compose.yml           # reverse proxy + TLS
│   ├── Caddyfile             # dos vhosts: app y phpMyAdmin
│   └── .env.example          # DOMAIN, TLS_MODE, PM_USER, PM_HASH
└── php-apache-mysql/
    ├── compose.yml           # servicios web + db (+ phpmyadmin opcional)
    ├── Dockerfile            # php:8.5-apache + extensiones MySQL + OPcache
    ├── .dockerignore         # ¡impide que el .env entre en la imagen!
    ├── .env.example
    ├── phpmyadmin/
    │   └── config.user.inc.php   # se monta en el contenedor phpmyadmin
    └── src/
        └── index.php         # página de estado (no expone secretos)
```

## ✅ Requisitos

- Docker Engine + Docker Compose v2
- Puertos 80 y 443 libres en el host
- (Opcional) Pi-hole como DNS de la red local

## 🚀 Montaje desde cero

Guía completa y verificada, en orden. Cada bloque se ejecuta en la carpeta indicada.

> Dos detalles que son la causa de casi todos los fallos iniciales:
> cada proyecto Compose lee el `.env` de **su propia** carpeta (por eso hay dos),
> y `proxy-net` debe existir **antes** de levantar nada.

### 1. Red compartida entre proyectos

Una sola vez por máquina:

```bash
docker network create proxy-net
```

### 2. Clonar el repositorio

```bash
git clone https://github.com/utatatx/docker-lab-lamp.git
cd docker-lab-lamp
```

### 3. Secretos del stack de aplicación

```bash
cd php-apache-mysql
cp .env.example .env
chmod 600 .env

# Genera las dos contraseñas: son independientes, no reutilices
openssl rand -hex 32    # -> DB_PASSWORD
openssl rand -hex 32    # -> DB_ROOT_PASSWORD
```

Abre `.env` y pega cada valor en su línea. Deja `PHPINFO=0`.

```bash
# Comprobación: el arranque debe abortar si falta algo
docker compose config >/dev/null && echo "configuración válida"
```

### 4. Secretos del reverse proxy

```bash
cd ../caddy
cp .env.example .env
chmod 600 .env
```

Genera el hash bcrypt de la contraseña de phpMyAdmin:

```bash
docker run --rm caddy:2.10-alpine caddy hash-password --plaintext 'TU_CONTRASEÑA'
# -> $2a$14$......................................................
```

En `.env`: `PM_HASH='$2a$14$......'`

> **Las comillas simples son obligatorias.** Compose interpreta el `$` inicial como inicio de variable, así que un hash escrito sin comillas se trunca en el primer `$` y el login falla.
>
> `PM_HASH` se deja **vacía** a propósito en la plantilla. Sin un hash real, Caddy se niega a arrancar con un error explícito, que es lo que se busca. No pongas texto de relleno: Caddy no lo reconocería como bcrypt e intentaría decodificarlo en base64.

### 5. Nombres de host

El navegador debe resolver ambos nombres **en el propio servidor y en cada cliente**:

```bash
echo "127.0.0.1 php.lab.local" | sudo tee -a /etc/hosts
echo "127.0.0.1 phpmyadmin.lab.local" | sudo tee -a /etc/hosts
```

En una red real, sustituye `127.0.0.1` por la IP del servidor y usa Pi-hole en lugar de `/etc/hosts` (ver *DNS local* más abajo).

### 6. Levantar el reverse proxy

```bash
docker compose up -d
docker compose logs --tail 5    # debe terminar sin "Error:"
```

### 7. Levantar el stack PHP + MySQL

```bash
cd ../php-apache-mysql
docker compose up -d --build
```

MySQL tarda unos segundos en quedar `healthy`; `web` espera a esa comprobación por `depends_on`.

### 8. Levantar phpMyAdmin (opcional)

```bash
docker compose --profile admin up -d
```

Va detrás de Caddy, sin puertos publicados y con autenticación básica.

### 9. Verificar

```bash
# Los cuatro contenedores arriba, MySQL healthy
docker compose ps
docker compose -f ../caddy/compose.yml ps

# El sitio responde y no filtra secretos
curl -sk -o /dev/null -w '%{http_code}\n' https://php.lab.local     # 200
curl -sk https://php.lab.local | grep -iE 'DB_PASS|MYSQL_PASSWORD|MYSQL_ROOT_PASSWORD|phpinfo\(\)|_ENV|_SERVER'
# sin salida: ninguna variable de entorno ni configuración expuesta

# La conexión con la BD va cifrada
curl -s http://127.0.0.1:8080/ | grep -oE 'TLS_[A-Za-z0-9_]+'         # TLS_AES_256_GCM_SHA384

# Cabeceras de seguridad
curl -skI https://php.lab.local | grep -iE 'strict-transport|x-frame|x-content-type'

# MySQL no está publicado en el host
ss -ltn | grep 3306 || echo '3306 cerrado: correcto'
```

Abre en el navegador:

| URL | Resultado esperado |
|---|---|
| `https://php.lab.local` | Página de estado, «correcto». Aviso de certificado la primera vez. |
| `https://phpmyadmin.lab.local` | Credenciales de phpMyAdmin |

Para quitar el aviso del certificado, instala la CA raíz de Caddy (ver *Confianza en el certificado*).

### Si ya tenías una versión anterior del laboratorio

El volumen `dbdata` conserva la contraseña con la que se inicializó, y **MySQL no aplica `MYSQL_PASSWORD` sobre un volumen existente**. Si cambias `DB_PASSWORD` en `.env` y la web responde *«Base de datos — fallo»*, aplica la nueva contraseña a mano:

```bash
docker compose exec db mysql -uroot -p'<CONTRASEÑA_ROOT_ANTIGUA>' \
  -e "ALTER USER 'appuser'@'%' IDENTIFIED BY '<DB_PASSWORD_NUEVA>';"
```

O, si no te importa perder los datos del laboratorio, bórralo y se reinicializa:

```bash
docker compose down && docker volume rm php-apache-mysql_dbdata
docker compose up -d
```

## ⚙️ Configuración

### TLS: del laboratorio a producción

`TLS_MODE` en `caddy/.env` es lo único que hay que cambiar. El parser de Caddy solo admite `internal`, `force_automate` o **una dirección de correo** como argumento de `tls`, por eso el valor no puede ser una palabra tipo `acme`.

| `TLS_MODE` | Comportamiento | Cuándo usarlo |
|---|---|---|
| `internal` | CA propia de Caddy, autofirmada. El navegador avisa. | Laboratorio |
| `admin@midominio.com` | Let's Encrypt / ACME, con aviso de caducidad a ese correo | Producción, con dominio real |

Para pasar a Let's Encrypt: registra un dominio apuntando a la IP del servidor, deja 80/443 accesibles desde Internet, pon `TLS_MODE=admin@midominio.com` y reinicia Caddy.

### Cifrado de la conexión con MySQL

MySQL 8.4 ya trae SSL activo con certificados autogenerados (`tls_version=TLSv1.2,TLSv1.3`), así que **no hay nada que configurar en el servidor**. Lo que cambia es cada cliente:

| Tramo | Estado | Cómo |
|---|---|---|
| App web → MySQL | **Cifrado** (TLS 1.3) | `Pdo\Mysql::ATTR_SSL_CIPHER` en `src/index.php` |
| phpMyAdmin → MySQL | **Sin cifrar** | El aviso se silencia declarando `db` host de confianza |

En `src/index.php`:

```php
\Pdo\Mysql::ATTR_SSL_CIPHER             => 'DHE-RSA-AES256-GCM-SHA384',
\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false,
```

Dos trampas que conviene conocer:

- **`sslmode` en el DSN no sirve con PDO.** Es una característica de `mysqli`; PDO lo ignora en silencio y la conexión sigue en claro sin avisar.
- **`ATTR_SSL_CIPHER` solo rompe la conexión**: activa el cifrado pero deja la verificación activa y falla con `Cannot connect to MySQL using SSL`. Necesita el `VERIFY_SERVER_CERT => false` que lo acompaña.

Comprobación rápida:

```bash
curl -s http://127.0.0.1:8080/ | grep -oE 'TLS_[A-Za-z0-9_]+'
# TLS_AES_256_GCM_SHA384
```

> ⚠️ **Lo que esto no protege.** El certificado de MySQL es autofirmado y su CA no se distribuye, así que `VERIFY_SERVER_CERT` es `false`: el tráfico va cifrado, pero **no se autentica el servidor**. Bloquea el eavesdropping pasivo, no un MITM activo.
>
> El tramo de phpMyAdmin **no va cifrado en absoluto**: `phpmyadmin/config.user.inc.php` solo declara `db` en `MysqlSslWarningSafeHosts`, que es la vía que recomienda el propio phpMyAdmin para red privada. Silencia el aviso, no lo arregla. Ambos contenedores viajan por `appnet`, una red `internal: true` que no sale del host.
>
> Por eso `require_secure_transport` debe **seguir en `OFF`**: si se activara, rompería la conexión de phpMyAdmin.

#### Por qué un certificado autofirmado no deja verificar

Las tres situaciones posibles, de menos a más segura:

| Estado | Cifrado | Autentica al servidor | Protege de |
|---|---|---|---|
| Sin SSL | No | No | nada |
| **El de este proyecto** | Sí | **No** | eavesdrops pasivo |
| Con CA distribuida | Sí | **Sí** | eavesdrops **y MITM activo** |

La diferencia está en una sola línea: `VERIFY_SERVER_CERT`. Ponerla a `true` es lo que obliga a que el cliente compruebe que el certificado lo emitió una CA de fiar **y** que el nombre del certificado corresponde al host por el que te conectas.

Un certificado autofirmado no permite ninguna de las dos cosas: no hay tercera parte en la que confiar, así que el certificado no se puede autenticar a sí mismo.

En este proyecto el bloqueo es doble, y conviene ser preciso porque no es solo el certificado:

- **MySQL sí genera una CA** (`ca.pem`, con `CA:TRUE`) y el certificado del servidor está correctamente firmado por ella — `openssl verify -CAfile ca.pem server-cert.pem` devuelve `OK`. O sea, la cadena criptográfica es válida.
- **Pero el certificado no lleva `subjectAltName`**, y su único nombre es `CN=MySQL_Server_8.4.11_Auto_Generated_Server_Certificate`. La aplicación se conecta al host `db`, y ese nombre no corresponde a nada que se pueda verificar.

Por eso, incluso con la CA correcta, `VERIFY_SERVER_CERT => true` falla con `Cannot connect to MySQL using SSL`. **Tener CA no basta: el certificado del servidor además tiene que llevar el nombre por el que se conectan los clientes.**

#### Con una CA propia sí funciona

Está comprobado, no es teórico. Generando una CA propia y un certificado de servidor con `CN=db` y `SAN: DNS:db`, la misma conexión con `VERIFY_SERVER_CERT => true` funciona:

```
CA propia + verify=true  -> CONEXION OK, TLS_AES_256_GCM_SHA384
```

Las dos condiciones son necesarias:

1. **Una CA propia**, con su `ca.crt` disponible para los clientes.
2. **El certificado del servidor debe incluir el nombre `db`**, en `SAN: DNS:db` (y en `CN`).

Lo que aportaría frente al estado actual:

- Se podría poner `VERIFY_SERVER_CERT => true` y **verificar de verdad** al servidor.
- El tramo de phpMyAdmin pasaría a ir **cifrado y verificado**, y el aviso de SSL desaparecería por el motivo correcto en lugar de silenciarse.
- Sobrevive a que se recree el volumen `dbdata`, que es lo que invalida los certificados autogenerados.

No se ha aplicado a este proyecto porque exige generar y custodiar claves privadas, y mantener `appnet` sin salida a Internet ya cubre el riesgo de fondo. Si se quisiera, el cambio concreto sería:

```bash
# Certificados en ./certs/mysql/ (ya está en .gitignore por *.pem)
openssl req -x509 -newkey rsa:2048 -nodes -keyout certs/mysql/ca.key \
  -out certs/mysql/ca.crt -days 3650 -subj "/CN=RA1 MySQL CA"
openssl req -newkey rsa:2048 -nodes -keyout certs/mysql/server.key \
  -out certs/mysql/server.csr -subj "/CN=db"
printf "subjectAltName=DNS:db\nextendedKeyUsage=serverAuth\n" > certs/mysql/ext.cnf
openssl x509 -req -in certs/mysql/server.csr -CA certs/mysql/ca.crt \
  -CAkey certs/mysql/ca.key -CAcreateserial -out certs/mysql/server.crt \
  -days 3650 -extfile certs/mysql/ext.cnf
```

Y después: `db` con `--ssl-ca=`, `--ssl-cert=`, `--ssl-key=`; `web` y `phpmyadmin` montando **solo** `ca.crt` y poniendo la verificación a `true`. Nunca se monta `ca.key` ni `server.key` en un cliente.

### DNS local

**Opción A — Pi-hole** (si actúa como DNS de tu red): *Local DNS* → `php.lab.local` y `phpmyadmin.lab.local` → `<IP del servidor>`.

**Opción B — /etc/hosts** en cada cliente (y en el propio servidor):

```
192.168.1.50   php.lab.local
192.168.1.50   phpmyadmin.lab.local
```

> La segunda línea es obligatoria para usar phpMyAdmin. Si falta, el navegador dará «This site can't be reached» y parecerá que Caddy está caído cuando en realidad es el nombre de host.

### Confianza en el certificado

La CA que emite Caddy no la conocen los navegadores. **No vuelques el certificado en la raíz del repositorio** (quedaría versionado si no, y `.gitignore` lo cubre, pero es mejor sacarlo de ahí):

```bash
# En un directorio fuera del repositorio, p. ej. /tmp
docker compose -f caddy/compose.yml exec caddy \
  cat /data/caddy/pki/authorities/local/root.crt > /tmp/root.crt
```

- **Linux**: `sudo cp /tmp/root.crt /usr/local/share/ca-certificates/caddy-local.crt && sudo update-ca-certificates`
- **Windows**: doble clic → Instalar → *Entidades de certificación raíz de confianza*
- **Firefox**: Ajustes → Privacidad y seguridad → Certificados → Importar (almacén propio)

## 🧩 Servicios

| Servicio | Imagen | Perfil | Función |
|---|---|---|---|
| `caddy` | `caddy:2.10-alpine` | — | Reverse proxy, TLS, cabeceras de seguridad |
| `web` | `mi-app-php:8.5-apache` (build) | — | PHP 8.5 + Apache mod_php |
| `db` | `mysql:8.4` | — | MySQL 8.4 LTS, red interna |
| `phpmyadmin` | `phpmyadmin:5` | `admin` | Gestión web de la BD, tras Caddy |

Versiones fijadas: `latest` puede cambiar de comportamiento entre despliegues sin que nada avise.

```bash
docker compose --profile admin up -d   # levanta también phpMyAdmin
```

## 🔍 Verificación

```bash
# Estado de contenedores
docker compose -f caddy/compose.yml ps
docker compose -f php-apache-mysql/compose.yml ps

# El sitio responde y NO filtra secretos
curl -Ik https://php.lab.local
curl -sk https://php.lab.local | grep -iE 'DB_PASS|MYSQL_PASSWORD|MYSQL_ROOT_PASSWORD|phpinfo\(\)|_ENV|_SERVER'

# Cabeceras de seguridad que aplica Caddy
curl -sIk https://php.lab.local | grep -iE 'strict-transport|x-frame|x-content-type|referrer'

# Extensiones PHP activas
docker compose -f php-apache-mysql/compose.yml exec web php -m | grep -iE 'opcache|mysqli|pdo_mysql'

# MySQL no está publicado en el host
docker compose -f php-apache-mysql/compose.yml exec db mysql -uappuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" appdb

# phpMyAdmin exige credenciales (401 sin ellas)
curl -sIk https://phpmyadmin.lab.local | head -1
```

## 🛠️ Comandos útiles

Los nombres de contenedor los genera Compose (`<proyecto>-<servicio>-1`); ya no se fuerzan con `container_name`, que además provocaba colisiones entre despliegues.

> En `docker ps`, Caddy muestra `2019/tcp` sin IP del host: **no está publicado**. Es la API de administración, que el Caddyfile ata a `localhost` dentro del contenedor (`admin localhost:2019`).

```bash
docker compose up -d --build       # construir y levantar
docker compose down                 # parar (el volumen de la BD persiste)
docker compose logs -f web          # logs en directo
docker compose config               # validar sin arrancar
docker compose exec web sh          # terminal dentro del contenedor
```

### Respaldo de la base de datos

```bash
mkdir -p backups      # ya está en .gitignore
docker compose -f php-apache-mysql/compose.yml exec -T db \
  mysqldump -uappuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" appdb \
  > "backups/appdb-$(date +%F).sql"
```

Restaurar: `docker compose -f php-apache-mysql/compose.yml exec -T db mysql -uappuser -p'<PASSWORD>' appdb < backups/<fichero>.sql`

> ⚠️ Cambiar `DB_PASSWORD` en `.env` **no** cambia la contraseña dentro de una base ya inicializada: MySQL solo la aplica en el primer arranque. Tras editarla hay que aplicarla a mano con `ALTER USER` o tirar el volumen `dbdata`.

## 🐛 Problemas encontrados y soluciones

| Error | Causa | Solución |
|---|---|---|
| `Access denied for user 'appuser'@...` | El volumen `dbdata` ya existía: MySQL **solo** aplica `MYSQL_PASSWORD` al inicializar, así que la contraseña del `.env` nueva nunca se aplicó | `ALTER USER 'appuser'@'%' IDENTIFIED BY '<la del .env>';` desde dentro del contenedor, o borrar el volumen `dbdata` y reiniciar |
| `base64-decoding password: illegal base64 data` | `PM_HASH` conserva el texto de relleno. Caddy, al no reconocer formato bcrypt, lo interpreta como base64 | Pegar el hash real de `caddy hash-password`, entre comillas simples |
| `basic_auth: username and password cannot be empty` | `PM_HASH` vacía en `caddy/.env` | Rellena el hash y reinicia Caddy |
| Caddy reinicia en bucle y el sitio da error | Caddy no arrancó, así que nada escucha en 80/443 | `docker compose -f caddy/compose.yml logs` y lee el error |
| `phpmyadmin.lab.local` no resuelve | Falta su entrada en el DNS local | Añádelo en Pi-hole o en `/etc/hosts` junto a `php.lab.local` |
| Vuelve el aviso «SSL is not being used» | Falta el montaje de `phpmyadmin/config.user.inc.php` | `docker compose --profile admin up -d` para recrear el contenedor |
| `Cannot connect to MySQL using SSL` | O falta `VERIFY_SERVER_CERT => false`, o el certificado no lleva el nombre `db` en `SAN` | Con los certificados autogenerados, ambos van juntos: cifrar sí, verificar no |
| `Base de datos — fallo` con TLS puesto | MySQL no ofrece SSL en ese arranque | Quita las dos opciones de `Pdo\Mysql` de `src/index.php` |
| La BD avisa «no se está haciendo uso de SSL» en la web | `sslmode` en el DSN, que PDO ignora | Usa `Pdo\Mysql::ATTR_SSL_*`, no `sslmode` |
| La contraseña de la BD aparece en la web pública | `index.php` era `phpinfo()`, que imprime las variables de entorno | Página de estado; `phpinfo()` tras `PHPINFO=1` |
| MySQL accesible desde la red local | `ports: 3306:3306` publica en `0.0.0.0` | Sin `ports`; solo red interna `appnet` |
| El hash bcrypt de phpMyAdmin no funciona | Compose truncó el hash al ver el `$` | Entrecomillarlo en `.env` |
| `env file ... .env not found` | Falta el `.env` de ese proyecto | `cp .env.example .env` en la carpeta del proyecto |
| `DB_PASSWORD no definida` | Falta una variable en `.env` | Fallo intencionado: rellénala |
| `additional properties 'caddy' not allowed` | Bloque de servicio en la raíz del YAML | Anidar bajo `services:` |
| `container name "/mysql84" already in use` | Nombres fijos de la versión anterior | `docker compose down` y usar `compose exec` |
| `failed to connect to the docker API` | `dockerd` sin iniciar | `sudo systemctl enable --now docker` |
| Sitio inalcanzable tras levantar Caddy | Caddy levantado en primer plano | Siempre `docker compose up -d` |

## 📝 Notas técnicas

- **OPcache en PHP 8.5**: desde esta versión forma parte del binario de PHP (ya no es una extensión opcional). Se ajusta solo con directivas `opcache.*` en un `.ini`, sin cargar ningún `.so`.
- **`depends_on` con `service_healthy`**: `web` espera al healthcheck de MySQL antes de arrancar. Sin esto, la primera petición tras un `up` desde cero falla porque MySQL todavía está inicializando el volumen.
- **Persistencia**: los datos de MySQL viven en el volumen `dbdata`; los certificados y la configuración de Caddy, en `caddydata` y `caddyconfig`. Sobreviven a `docker compose down`, pero `down -v` los borra. Al recrear `dbdata`, MySQL genera certificados nuevos y se pierde la CA interna de la base de datos.
- **Montar vs. copiar el código**: aquí `./src` se monta en `/var/www/html` porque es un laboratorio y interesa editar sin reconstruir. En producción lo contrario: `COPY src/ /var/www/html` dentro de la imagen, para que el despliegue sea reproducible y no dependa del sistema de ficheros del host.
- **Producción**: sustituir `tls internal` por certificados reales (Let's Encrypt o CA corporativa), poner `display_errors=Off` y credenciales fuera del repositorio. Ver la sección de TLS y el roadmap.

## 🗺️ Roadmap

- [ ] Desplegar Pi-hole como DNS local
- [ ] Límites de recursos (`mem_limit` / `cpus`) y rotación de logs por servicio
- [ ] `ini` de producción: `display_errors=Off`, `log_errors=On`, `expose_php=Off`
- [ ] Copiar el código dentro de la imagen en lugar de montarlo como volumen
- [ ] Reglas de firewall (ufw) limitando el acceso a la VLAN del laboratorio

---

⌨️ _Laboratorio de RA1 — Implantación de Aplicaciones Web · CFGS ASIR_
