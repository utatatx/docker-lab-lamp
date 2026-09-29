# Política de seguridad

Este repositorio es público. Gracias por ayudar a mantenerlo seguro.

## Qué contiene este repositorio

Solo la **configuración** del laboratorio (Dockerfiles, ficheros de Compose,
Caddyfile y ficheros de ejemplo). Ningún secreto real debe subirse nunca:

- Los ficheros `.env` están en `.gitignore`. Sube solo `.env.example`.
- Los certificados, claves privadas y material PKI están ignorados.
- `php-apache-mysql/.dockerignore` impide que el `.env` entre en el contexto
  de build y acabe en una capa de la imagen.

Si alguna vez has commiteado un secreto, borrarlo del fichero actual **no
basta**: sigue en el historial. Rota la credencial y luego reescribe el
historial (ver más abajo).

## Reportar una vulnerabilidad

No abras un *issue* público. Envía el detalle por correo privado a
**unaiarnau@iesmontsia.org** e incluye:

- Qué servicio y puerto están afectados.
- Pasos para reproducirlo.
- Impacto que has observado.

Responderé en un plazo razonable y, tras la corrección, mencionaré el problema
de forma genérica en el historial de commits sin revelar detalles exploits.

## Buenas prácticas para quien use este repositorio

```bash
# 1. Genera secretos aleatorios, nunca los escribas a mano
openssl rand -base64 32

# 2. Copia las plantillas y rellénalas
cp php-apache-mysql/.env.example php-apache-mysql/.env
cp caddy/.env.example caddy/.env
chmod 600 php-apache-mysql/.env caddy/.env

# 3. Comprueba antes de commitear que ningún secreto se cuela
git status --porcelain
git check-ignore -v php-apache-mysql/.env
```

## Rotar un secreto filtrado

```bash
# Tras cambiar la contraseña en el .env, recrea los contenedores
docker compose -f php-apache-mysql/compose.yml up -d --force-recreate db web

# Y si llegó a subirse al repositorio, reescribe el historial:
#   git filter-repo --invert-paths --path php-apache-mysql/.env
#   git push --force --follow-tags
```

## Configuración por defecto que conviene revisar

| Ajuste | Valor por defecto | Comentario |
|---|---|---|
| `TLS_MODE` | `internal` | CA propia de Caddy; el navegador avisa. Para producción pública hace falta un dominio real. |
| `DB_PASSWORD` / `DB_ROOT_PASSWORD` | sin valor | `${VAR:?}` aborta el arranque si faltan: nunca arranca con contraseña Known. |
| `PHPINFO` | `0` | Con `1` se expone la configuración completa, **incluidas las variables de entorno con contraseñas**. No lo actives en un entorno accesible. |
| Puertos 3306 / 8081 | no publicados | MySQL y phpMyAdmin solo son alcanzables a través de Caddy. |
