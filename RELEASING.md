# Publicar una versión

Desde la 3.8.1 las webs se actualizan desde el servidor privado
`https://connect.aquihaymarketing.es` (ver [server/README.md](server/README.md)).
Publicar es empujar un tag `vX.Y.Z`: el workflow
`.github/workflows/publish-updates.yml` construye el zip, genera
`manifest.json` y lo sube por FTPS.

## Pasos

1. **Subir la versión en el plugin** — dos sitios en `ahm-connect.php`:
   - cabecera `Version:`
   - `define( 'RMAI_VERSION', ... )`

2. **Actualizar `ahm-connect.json`** (es la fuente del `manifest.json` del servidor):
   - `version` → la nueva
   - `last_updated` → fecha de hoy
   - `sections.changelog` → qué cambia

3. **Construir y comprobar en local**:

   ```bash
   ./build.sh
   ```

   Aborta si la cabecera `Version:`, `RMAI_VERSION` y el `version` del JSON no
   coinciden.

4. **Publicar**:

   ```bash
   git add -A
   git commit -m "release: v3.8.2"
   git tag v3.8.2
   git push origin main v3.8.2
   ```

   El workflow vuelve a comprobar que el tag coincide con la versión, sube
   primero el zip y después el manifiesto (por renombrado), así que nunca se
   anuncia una versión cuyo zip no está.

5. **Comprobar** en la Terminal de cPanel: `php private/admin.php release`.

## Cómo lo ven las webs

WordPress consulta las actualizaciones cada 12 h. Para forzarlo: en **Plugins**,
en la fila de AHM Connect, enlace **"Buscar actualizaciones"** (si la web aún
no estaba aprobada, reintenta también el registro).

El resultado se cachea 12 h en el transient de red `rmai_update_manifest`; un
fallo o un "pendiente" se cachea 1 h.

## Webs nuevas

Añadir el dominio (sin `www.`) a `preapproved_domains` en
`private/config.php` del servidor antes de instalar, o aprobarla después con
`php private/admin.php approve <dominio>`.

## Versiones anteriores

- **≤ 3.5.x**: sin actualizador; subir el zip a mano una vez.
- **3.6.0 – 3.8.0**: leen `ahm-connect.json` y `dist/ahm-connect.zip` de la rama
  `main` de GitHub. La 3.8.1 es la última que se anuncia por esa vía; cuando el
  repositorio pase a privado dejarán de ver versiones y habrá que subirles el
  zip a mano una vez.

## Probar contra staging

En el `wp-config.php` del sitio de pruebas:

```php
define( 'RMAI_UPDATE_SERVER', 'https://staging.example' );
```
