# Publicar una versión

Las webs con AHM Connect instalado leen `ahm-connect.json` de la rama `main` y
descargan el zip adjunto a la release de GitHub. Todo el flujo depende de que
esos tres números de versión coincidan.

## Pasos

1. **Subir la versión en el plugin** — dos sitios en `ahm-connect.php`:
   - cabecera `Version:` (línea 6)
   - `define( 'RMAI_VERSION', ... )`

2. **Actualizar `ahm-connect.json`**:
   - `version` → la nueva
   - `download_url` → la URL del zip de la nueva release (cambia el tag)
   - `last_updated` → fecha de hoy
   - `sections.changelog` → qué cambia

3. **Construir el zip** (la raíz debe ser la carpeta `ahm-connect/`):

   ```bash
   ./build.sh
   ```

4. **Publicar**:

   ```bash
   git add -A && git commit -m "release: v3.6.0"
   git tag v3.6.0
   git push origin main --tags
   gh release create v3.6.0 ahm-connect.zip --title "v3.6.0" --notes "..."
   ```

El `ahm-connect.json` se sirve desde `main`, así que el aviso de actualización
aparece en cuanto se hace push — antes incluso de crear la release. **Crea la
release antes de hacer push**, o durante unos minutos las webs verán la
actualización con un enlace de descarga que aún no existe.

## Cómo lo ven las webs

WordPress consulta las actualizaciones cada 12 h. Para forzarlo: en
**Plugins**, en la fila de AHM Connect, enlace **"Buscar actualizaciones"**.

El manifiesto se cachea 12 h en un transient de red (`rmai_update_manifest`);
si falla la descarga se cachea el fallo 1 h para no reintentar en cada pantalla.

## Probar contra staging

En el `wp-config.php` del sitio de pruebas:

```php
define( 'RMAI_UPDATE_MANIFEST', 'https://ejemplo.test/ahm-connect.json' );
```
