# Servidor de actualizaciones de AHM Connect

`https://connect.aquihaymarketing.es` sirve las actualizaciones del plugin
**solo a webs autorizadas**. El público solo ve la landing (`public/index.html`)
y su formulario de contacto. PHP 8.3 puro, sin dependencias ni base de datos.

- cPanel **artemisa**, cuenta `aquihaymarketing`, PHP **ea-php83**, LiteSpeed,
  detrás de Cloudflare.
- Sin SSH: todo se administra desde **cPanel → Terminal**.
- Se despliega con GitHub Actions por FTPS (`.github/workflows/publish-updates.yml`).

## Estructura

```
/home/aquihaymarketing/connect/          ← raíz de la cuenta FTP de despliegue
├── public/                              ← docroot del subdominio
│   ├── index.html                       landing (otro proyecto; se despliega desde el repo)
│   ├── .htaccess                        sin listado, sin ficheros ocultos, HTTPS, cabeceras, solo 4 endpoints en /api
│   └── api/
│       ├── update.php                   consulta firmada
│       ├── register.php                 alta de una web
│       ├── download.php                 descarga firmada del zip
│       └── contact.php                  formulario de la landing
└── private/                             ← FUERA del docroot
    ├── lib.php                          bootstrap común (lo incluyen los endpoints)
    ├── admin.php                        administración por CLI
    ├── .htaccess                        "Require all denied" por si acaso
    ├── config.example.php               plantilla (la sube el despliegue)
    ├── config.php                       SOLO en el servidor, creado a mano, chmod 600
    ├── releases/
    │   ├── ahm-connect-<versión>.zip
    │   └── manifest.json                versión, changelog, requires, tested, sha256
    └── data/                            700; lo crea lib.php
        ├── sites.json                   registro de webs (600)
        ├── ratelimit/                   contadores por IP
        └── log/access-AAAA-MM.log       log mínimo; php-error.log
```

En el repo: `server/public` y `server/private` reflejan lo anterior (sin
`config.php`, `data/` ni `releases/`). `server/bin/build-manifest.php` lo usa
el workflow para generar `manifest.json` a partir de `ahm-connect.json`.

## Protocolo

**Identidad.** Cada web genera una vez un `site_secret` aleatorio de 32 bytes
(opción `rmai_update_site_secret`, sin autoload, nunca se muestra). La web se
identifica por su dominio: host de `home_url` en minúsculas y sin `www.`.

**Registro** — `POST /api/register.php`
`{home_url, site_secret, plugin_version, wp_version}`

1. El servidor valida la entrada y **comprueba que quien registra controla la
   web**: llama a
   `<home_url>/wp-json/ahm-connect/v1/update-verify?challenge=<nonce>` (y a
   `?rest_route=` si no hay enlaces permanentes) y espera
   `{"proof": HMAC-SHA256(site_secret, "verify|<nonce>|<home_url>")}`.
   La web solo contesta a esa ruta durante los minutos que dura su propio
   registro. Sin esta prueba, cualquiera podría registrar antes que el cliente
   un dominio preaprobado con su propio secret y descargarse el plugin.
   La llamada saliente solo va por https, a puertos por defecto, a IP pública
   fijada (sin DNS rebinding), sin seguir redirecciones, con tope de 64 KB y 10 s.
2. Resultado:
   - nuevo, verificado y en `preapproved_domains` → **approved**
   - nuevo (resto) → **pending** (sin verificar si la prueba falló; máximo 200)
   - mismo secret → refresca versión/fecha y devuelve su estado
   - otro secret sobre una web **approved/revoked** → **409 conflict**, no se
     toca nada (anti-secuestro). Si el nuevo secret sí verifica (reinstalación
     legítima del plugin) se guarda como *candidato* para el admin.
   - otro secret sobre una **pending** → solo la sustituye si el nuevo verifica
     o la anterior no estaba verificada.
3. La respuesta lleva `server_time`; si el reloj de la web se desvía más de
   60 s, el plugin corrige la hora con la que firma.

**Por qué el secret se guarda cifrado y no como hash.** Para comprobar un HMAC
el servidor necesita el secret en claro, así que un hash no sirve. Se guarda
cifrado con AES-256-GCM con una clave derivada de `server_secret`
(config.php). `sites.json` y `config.php` están fuera del docroot con permisos
600; el cifrado añade que una copia suelta de `sites.json` (backup, descarga
por error) no basta para suplantar a una web. **No cambies `server_secret`**:
dejaría inservibles todos los registros (habría que hacer `reset` de cada web).

**Consulta** — `GET /api/update.php` con cabeceras

```
X-AHMC-Site:      <home_url>
X-AHMC-Timestamp: <unix>
X-AHMC-Signature: hex(HMAC-SHA256(site_secret, "GET|/api/update.php|<timestamp>|<home_url>"))
X-AHMC-Version:   <versión instalada>   (informativa)
```

La ruta firmada es el `REQUEST_URI` exacto (ruta + query). Ventana ±300 s.
También se aceptan `home_url`, `timestamp` y `signature` como parámetros.

- Sin firma, firma mala, reloj fuera de ventana o web desconocida → `401
  {"error":"unauthorized"}`, siempre igual (el motivo solo va al log).
- Firmada, no aprobada → `{"status":"pending"|"revoked","message":…}` sin descarga.
- Aprobada → `status, version, name, author, requires, requires_php, tested,
  last_updated, sections, download_url, package_sha256`.

**Descarga** — `GET /api/download.php?v=<versión>` con las mismas cabeceras
firmadas (la ruta firmada incluye `?v=`). Solo webs aprobadas; si no, 403. Sirve
el zip con `readfile`, `Content-Type: application/zip`, `Content-Length` y
nombre `ahm-connect.zip`; la ruta real no aparece nunca. El plugin la hace en
`upgrader_pre_download`, comprueba que es un zip y su sha256, y por eso
funciona en la actualización manual, en las automáticas y desde MainWP.

**Límites por IP** (ventana fija, ficheros): update 60/min, download 30/min,
register 10/min, contact 5 cada 10 min. 429 con `Retry-After`. La IP real se
toma de `CF-Connecting-IP` solo si la petición llega desde un rango de
Cloudflare (`trusted_proxies`).

**Log** (`private/data/log/access-AAAA-MM.log`, TSV): fecha UTC, IP, endpoint,
código, dominio, motivo. Nunca secretos. El formulario solo anota IP
anonimizada y resultado (ni nombre, ni email, ni mensaje).

**Formulario** — `POST /api/contact.php` con `name`, `email`, `company`
(opcional), `message`, `privacy=1` (obligatorio) y `website` (honeypot: si viene
relleno se responde OK y se descarta). Envía con `mail()` a `contact_to`, `From`
del dominio propio (`contact_from`, con `-f` para el sobre) y `Reply-To` del
usuario. Con `Accept: application/json` o `X-Requested-With` responde
`{"ok":true}` / `{"ok":false,"error":"…"}`; sin JS redirige (303) a
`/?enviado=1` o `/?error=1`. Rechaza Origin ajeno y saltos de línea en cabeceras.

## Instalación en cPanel (una vez)

1. **Subdominio.** cPanel → Dominios → Crear un dominio nuevo:
   `connect.aquihaymarketing.es`, desmarcar "compartir raíz de documentos",
   raíz `connect/public`.
2. **PHP.** MultiPHP Manager → `connect.aquihaymarketing.es` → **ea-php83**
   (no 8.4). En MultiPHP INI Editor: `display_errors = Off`. Extensiones
   necesarias: openssl, mbstring, json, curl (suelen venir).
3. **Cuenta FTP de despliegue.** cPanel → Cuentas FTP: usuario p. ej.
   `deploy-connect`, directorio `connect` (queda
   `/home/aquihaymarketing/connect`), contraseña larga generada.
4. **DNS en Cloudflare.** Registro `A connect → 92.60.33.18` (IP web de
   artemisa). Para emitir el certificado, déjalo primero en **DNS only** (nube
   gris), lanza **SSL/TLS → AutoSSL** en cPanel y, cuando el certificado esté,
   pásalo a **Proxied** (Cloudflare intercepta `.well-known` y AutoSSL falla con
   la nube naranja). Alternativa: Origin Certificate de Cloudflare instalado en
   cPanel → SSL/TLS.
5. **Cloudflare, ajustes de la zona:**
   - SSL/TLS: **Full (strict)**.
   - Reglas de caché: `URI Path starts with /api/` → **Bypass cache**.
   - Seguridad → WAF → regla personalizada: `URI Path starts with /api/` →
     **Skip** (Browser Integrity Check, Security Level, User Agent Blocking).
     Las peticiones vienen de servidores WordPress, no de navegadores.
   - **Bot Fight Mode** apagado (en plan gratuito no se puede saltar por ruta y
     bloquearía a las webs).
   - Las webs cliente que estén tras Cloudflare con Bot Fight Mode pueden
     bloquear la llamada de verificación: quedarán *pendientes sin verificar*
     (ver "Comandos").
6. **Primer despliegue** (ver "Corte" abajo): sube `public/`, `private/lib.php`,
   `admin.php` y `config.example.php`.
7. **config.php** desde cPanel → Terminal, **una línea cada vez** (la Terminal
   web funde las líneas pegadas juntas):

   ```bash
   cd ~/connect
   cp private/config.example.php private/config.php
   sed -i "s/CAMBIA-ESTO-por-64-hex-generados-con-random_bytes/$(php -r 'echo bin2hex(random_bytes(32));')/" private/config.php
   sed -i "s/CAMBIA-ESTO@aquihaymarketing.es/BUZON@aquihaymarketing.es/" private/config.php
   chmod 600 private/config.php
   mkdir -p private/data/log private/releases
   chmod 700 private private/data private/data/log
   grep -c "CAMBIA-ESTO" private/config.php
   ```

   (sustituye `BUZON` por el buzón real; el último comando debe dar `0`). El
   secret se genera dentro del servidor y no aparece en pantalla.
   `contact_from` (`no-reply@aquihaymarketing.es`) debe ser un buzón o alias
   del dominio que pueda enviar desde este servidor (SPF/DKIM del cPanel).
8. **Comprobar:**

   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' https://connect.aquihaymarketing.es/api/update.php
   ```

   → `401` (sin firma). `503` = falta o está mal `config.php`.
   `curl -s -o /dev/null -w '%{http_code}\n' https://connect.aquihaymarketing.es/api/lib.php` → `404`.

## Comandos (`admin.php`, solo CLI)

Desde `cd ~/connect` en la Terminal de cPanel (usa el `php` de la cuenta; si
dice otra versión, `/opt/cpanel/ea-php83/root/usr/bin/php`):

```bash
php private/admin.php list                 # todas las webs registradas
php private/admin.php list pending         # solo pendientes (o approved / revoked)
php private/admin.php list --all           # + preaprobados que aún no se han registrado
php private/admin.php show panesyco.es     # ficha (sin el secret)
php private/admin.php approve gys.es       # aprobar una pendiente verificada
php private/admin.php approve gys.es --force   # aprobar aunque no pasara la verificación (compruébalo antes)
php private/admin.php revoke gys.es        # quitar acceso (no ve ni descarga versiones)
php private/admin.php reset gys.es         # borrar el registro; la web se registra sola en su próxima consulta
php private/admin.php accept-candidate gys.es  # aceptar el secret nuevo de una web aprobada que reinstaló el plugin
php private/admin.php preapprove-sync      # tras añadir dominios a preapproved_domains en config.php
php private/admin.php log 50               # últimas 50 líneas del log del mes
php private/admin.php release              # versión publicada y su sha256
```

En `list`, NOTAS indica `sin-verificar`, `preaprobado` o `CANDIDATO`
(conflicto pendiente de decidir). Ejecutado por web, `admin.php` responde 404.

**Webs nuevas:** añade su dominio (sin `www.`) a `preapproved_domains` en
`private/config.php` antes de instalar el plugin, o apruébala después con
`approve`.

## Publicar una versión

Ver [RELEASING.md](../RELEASING.md). En resumen: subir versión en
`ahm-connect.php` (cabecera y `RMAI_VERSION`) y `ahm-connect.json`, `./build.sh`,
commit, `git tag vX.Y.Z` y `git push origin main vX.Y.Z`. El workflow comprueba
que tag, cabecera y constante coinciden, construye, genera `manifest.json` y
sube zip + manifiesto (el manifiesto el último, por renombrado).

Para desplegar solo cambios del servidor (landing, endpoints): Actions →
**Publicar actualizaciones** → Run workflow (con `release` sin marcar).

**Certificado del FTP.** El workflow exige FTPS y verifica el certificado. Usa
como `UPDATES_FTP_HOST` el **nombre del servidor** (el que aparece en cPanel →
"Información general" / el de la cuenta FTP), cuyo certificado es válido, y
nunca un nombre proxied por Cloudflare (Cloudflare no pasa FTP). Si aun así no
valida, crea la *variable* de repositorio `UPDATES_FTP_VERIFY_CERT=false`: el
canal sigue cifrado, pero sin comprobar el certificado (menos seguro; úsalo
solo de forma temporal).

## Corte (de GitHub público a servidor privado)

Las webs en **3.8.0** leen `ahm-connect.json` de GitHub (raw). La **3.8.1** es
el puente: GitHub la anuncia por última vez y, una vez instalada, cada web pasa
a hablar solo con connect.

1. **Desplegar el servidor y verificar** (repo aún público, `main` sin tocar):
   - pasos de instalación 1–5 y secretos de GitHub;
   - `git push origin v3.8.1` (solo el tag; `main` todavía no) → el workflow
     sube servidor + release 3.8.1;
   - paso 7 (`config.php`) y paso 8 (comprobar);
   - `php private/admin.php release` → 3.8.1;
   - en una web de prueba preaprobada, subir a mano `dist/ahm-connect.zip`
     (Plugins → Añadir → Subir → Reemplazar): en Ajustes → AHM Connect →
     Sitio debe salir **Actualizaciones: Registrada** y en el servidor
     `php private/admin.php list` debe mostrarla `approved`.
2. **Publicar 3.8.1 con el repo público:** `git push origin main` (con
   `release/3.8.1` ya integrada). Las 3.8.0 ven la 3.8.1 en GitHub, la instalan
   y en la siguiente visita al escritorio o comprobación de actualizaciones se
   registran en connect.
3. **Comprobar que las webs se registran:** `php private/admin.php list --all`
   durante uno o dos días. Las preaprobadas verificadas salen `approved`; las
   `sin-verificar` (Cloudflare, auth básica, sin https) se revisan a mano y se
   aprueban con `--force`. Las webs con `RMAI_DISABLE_UPDATES` (paquete Heleo)
   no aparecerán nunca: es lo esperado. Si alguna sigue en 3.8.0, forzar
   "Buscar actualizaciones" en su fila de Plugins.
4. **Pasar el repo a privado** (GitHub → Settings → General → Change
   visibility). Desde ese momento la URL raw devuelve 404 y las webs que se
   hayan quedado en ≤ 3.8.0 dejan de ver versiones: a esas se les sube el zip a
   mano una vez. Las 3.8.1+ siguen actualizándose desde connect.
