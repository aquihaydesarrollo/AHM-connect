# Publicar una versión

Las webs con AHM Connect instalado leen `ahm-connect.json` de la rama `main` y
descargan `dist/ahm-connect.zip` del mismo repositorio. Publicar una versión es
un `git push`: no hacen falta releases de GitHub, ni `gh`, ni tokens.

## Pasos

1. **Subir la versión en el plugin** — dos sitios en `ahm-connect.php`:
   - cabecera `Version:` (línea 6)
   - `define( 'RMAI_VERSION', ... )`

2. **Actualizar `ahm-connect.json`**:
   - `version` → la nueva
   - `last_updated` → fecha de hoy
   - `sections.changelog` → qué cambia

   `download_url` no cambia nunca: siempre apunta a `main`.

3. **Construir el zip**:

   ```bash
   ./build.sh
   ```

   Aborta si la cabecera `Version:`, `RMAI_VERSION` y el `version` del JSON no
   coinciden. Es el fallo que rompería el flujo en silencio: las webs verían una
   versión nueva y se instalarían la vieja.

4. **Publicar**:

   ```bash
   git add -A
   git commit -m "release: v3.6.0"
   git tag v3.6.0
   git push origin main --tags
   ```

El manifiesto y el zip viajan en el mismo commit, así que nunca hay una ventana
en la que se anuncie una versión cuya descarga todavía no existe.

## Cómo lo ven las webs

WordPress consulta las actualizaciones cada 12 h. Para forzarlo: en **Plugins**,
en la fila de AHM Connect, enlace **"Buscar actualizaciones"**.

El manifiesto se cachea 12 h en un transient de red (`rmai_update_manifest`); si
falla la descarga, el fallo se cachea 1 h para no reintentar en cada pantalla del
admin. `raw.githubusercontent.com` además cachea unos minutos por su cuenta, así
que tras el push puede tardar un poco en propagarse.

## El primer salto hay que hacerlo a mano

Las versiones anteriores a la 3.6.0 no llevan updater: no saben mirar el
manifiesto. En cada web ya instalada hay que subir `dist/ahm-connect.zip` una vez
por **Plugins → Añadir nuevo → Subir plugin → Reemplazar actual**. A partir de
ahí ya se actualizan solas.

## Probar contra staging

En el `wp-config.php` del sitio de pruebas:

```php
define( 'RMAI_UPDATE_MANIFEST', 'https://ejemplo.test/ahm-connect.json' );
```
