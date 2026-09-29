# 🐳 Lab Docker: PHP 8.5 + Apache + MySQL + Caddy (TLS interno)

Laboratorio autoalojado con arquitectura de **multi-proyecto Docker Compose**: un reverse proxy **Caddy** centralizado con TLS interno, sirviendo un stack **PHP 8.5 + Apache (mod_php) + MySQL 8.4**, con DNS local opcional vía **Pi-hole**.

Proyecto realizado para la asignatura de _Implantación de Aplicaciones Web_ (RA1), CFGS ASIR.

---

## 📐 Arquitectura

```
                    ┌──────────────────────────────────────────┐
  cliente HTTPS ──▶ │  Caddy  (proyecto: caddy/)               │
  php.lab.local     │  - Puerto 80 (redirect) y 443 (TLS)      │
                    │  - CA interna ("Caddy Local Authority")  │
                    │  - tls internal → cert autofirmado       │
                    └───────────────┬──────────────────────────┘
                                    │  red externa compartida: proxy-net
                                    ▼
                    ┌──────────────────────────────────────────┐
                    │  web (proyecto: php-apache-mysql/)       │
                    │  php:8.5-apache (mod_php)                │
                    │  extensiones: pdo_mysql, mysqli          │
                    │  OPcache integrada (nativa desde 8.5)    │
                    └───────────────┬──────────────────────────┘
                                    │  red interna: appnet
                                    ▼
                    ┌──────────────────────────────────────────┐
                    │  db: mysql:8.4 (puerto 3306 interno)     │
                    │  (opcional) phpmyadmin → perfil "admin"  │
                    └──────────────────────────────────────────┘
```

Cada subcarpeta es un **proyecto Compose independiente**, unidos por la red Docker externa `proxy-net`. Esto permite añadir más proyectos (Pi-hole, futuros stacks) detrás del mismo Caddy sin tocar configuración.

## 📁 Estructura del repositorio

```
docker/
├── caddy/
│   ├── compose.yml        # Reverse proxy + TLS
│   └── Caddyfile          # php.lab.local { tls internal; reverse_proxy web:80 }
├── php-apache-mysql/
│   ├── compose.yml        # Servicios web + db (+ phpmyadmin opcional)
│   ├── Dockerfile         # php:8.5-apache + extensiones MySQL + ini custom
│   ├── src/
│   │   └── index.php      # Código de la aplicación (montado como volumen)
│   └── .env.example       # Plantilla de variables de entorno
└── pi-hole/               # (pendiente) DNS local para el laboratorio
```

## ✅ Requisitos

- Docker Engine + Docker Compose v2
- Puertos 80 y 443 libres en el host
- (Opcional) Pi-hole como DNS de la red local

## 🚀 Puesta en marcha

> **Orden importante:** la red compartida debe existir **antes** de levantar los proyectos.

```bash
# 1. Crear la red externa compartida (una sola vez)
docker network create proxy-net

# 2. Clonar el repositorio
git clone https://github.com/<usuario>/<repo>.git
cd <repo>

# 3. Variables de entorno (credenciales de la BD)
cd php-apache-mysql
cp .env.example .env       # edita con contraseñas reales
chmod 600 .env

# 4. Levantar el reverse proxy
cd ../caddy
docker compose up -d

# 5. Levantar el stack PHP + MySQL
cd ../php-apache-mysql
docker compose up -d
```

## ⚙️ Configuración

### Caddyfile

```
php.lab.local {
    tls internal
    reverse_proxy web:80
}
```

- `tls internal`: Caddy crea su propia CA local y emite el certificado —ideal para laboratorio, sin dominio público ni Let's Encrypt.
- `reverse_proxy web:80`: `web` es el nombre del servicio PHP, resoluble por el DNS interno de Docker gracias a la red compartida `proxy-net`.

### DNS local (dos opciones)

**Opción A — Pi-hole** (recomendada si actúa como DNS de tu red):
Panel de Pi-hole → *Local DNS* → añadir registro `php.lab.local → <IP del servidor>`.

**Opción B — /etc/hosts** en cada cliente:

```
192.168.1.50   php.lab.local   # IP del servidor Docker
```

### Confianza en el certificado

Los navegadores no conocen la CA interna de Caddy. Para evitar el aviso de seguridad, exporta la CA raíz e impórtala en cada cliente:

```bash
docker exec caddy cat /data/caddy/pki/authorities/local/root.crt > root.crt
```

- **Linux**: `sudo cp root.crt /usr/local/share/ca-certificates/caddy-local.crt && sudo update-ca-certificates`
- **Windows**: doble clic → Instalar certificado → *Entidades de certificación raíz de confianza*
- **Firefox**: Ajustes → Privacidad y seguridad → Certificados → Importar (almacén propio)

## 🧩 Servicios

| Servicio | Imagen | Puerto host | Función |
|---|---|---|---|
| `caddy` | `caddy:latest` | 80, 443 | Reverse proxy + TLS interno |
| `web` | `mi-app-php:8.5-apache` (build local) | — (vía Caddy) | PHP 8.5 + Apache mod_php |
| `db` | `mysql:8.4` | — (interno) | Base de datos MySQL 8.4 LTS |
| `phpmyadmin` | `phpmyadmin:latest` | — (perfil `admin`) | Gestión web de la BD |

phpMyAdmin es opcional y se levanta con:

```bash
docker compose --profile admin up -d
```

## 🔍 Verificación

```bash
# Estado de contenedores
docker ps

# Caddy alcanza al PHP por la red compartida
docker exec caddy wget -qO- http://web:80 | head -5

# Extensiones PHP activas (OPcache, mysqli, pdo_mysql)
docker exec php85-apache php -m | grep -iE 'opcache|mysqli|pdo_mysql'

# Cadena completa desde el host
curl -Ik https://php.lab.local     # → HTTP/2 200
```

## 🛠️ Comandos útiles

```bash
docker compose up -d          # Levantar un proyecto (en su carpeta)
docker compose down           # Parar y eliminar contenedores (volumen BD persiste)
docker compose logs -f caddy  # Logs en directo
docker compose config         # Validar sintaxis del compose sin arrancar
docker compose build --no-cache   # Reconstruir imagen PHP desde cero
```

## 🐛 Problemas encontrados y soluciones

Notas de depuración reales del montaje del laboratorio:

| Error | Causa | Solución |
|---|---|---|
| `failed to connect to the docker API ... docker.sock` | Daemon `dockerd` no iniciado | `sudo systemctl enable --now docker` |
| `pull access denied for mi-app-php` | Dockerfile con nombre incorrecto (`Dockerfile.` con punto) | Renombrar a `Dockerfile` exacto, sin extensión |
| `cp: cannot stat 'modules/*'` al compilar OPcache | **PHP 8.5 integra OPcache**; ya no es extensión instalable | Eliminar `opcache` de `docker-php-ext-install` |
| `403 Forbidden` en Apache | `./src` no existía; Docker la creó vacía montando el volumen | `mkdir src`, crear `index.php` |
| `Permission denied` al escribir en `src/` | Directorio creado por Docker como root | `sudo chown -R $USER:$USER src` |
| `additional properties 'caddy' not allowed` | Bloque de servicio en raíz del YAML | Anidar bajo la clave `services:` |
| `container name "/mysql84" already in use` | Contenedor viejo de ejecución anterior | `docker rm -f mysql84` (o `docker compose down` en el proyecto antiguo) |
| Sitio inalcanzable tras levantar Caddy | Caddy levantado con `up` (primer plano) y detenido al cerrar terminal | Usar siempre `docker compose up -d` |

## 📝 Notas técnicas

- **OPcache en PHP 8.5**: desde esta versión es parte del binario de PHP (no opcional). Se ajusta solo con directivas `opcache.*` en un `.ini`, sin cargar ningún `.so`.
- **`depends_on` con `service_healthy`**: `web` espera al healthcheck de MySQL antes de arrancar, evitando errores de conexión en el primer inicio.
- **Persistencia**: los datos de MySQL viven en el volumen `dbdata`; la configuración y certificados de Caddy en `caddydata`/`caddyconfig`. Sobreviven a `docker compose down` (salvo `-v`).
- **Producción**: para un entorno real habría que sustituir `tls internal` por certificados de Let's Encrypt (requiere dominio + puertos 80/443 expuestos), eliminar los valores por defecto de las contraseñas y considerar copiar el código **dentro** de la imagen (`COPY src/ /var/www/html`) en lugar de montarlo como volumen.

## 🗺️ Roadmap

- [ ] Desplegar Pi-hole como DNS local del laboratorio
- [ ] Proxy host para phpMyAdmin (`db.lab.local`) vía Caddy
- [ ] Reglas de firewall (ufw) limitando acceso a la VLAN del laboratorio

---

⌨️ _Laboratorio de RA1 — Implantación de Aplicaciones Web · CFGS ASIR_
