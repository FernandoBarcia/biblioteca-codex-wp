# Biblioteca Codex para WordPress

Plugin que guarda tu biblioteca de lecturas en tu propio WordPress y la conecta con la app **Biblioteca Codex** para iPhone y iPad.

La app funciona sin este plugin, guardando los libros en el dispositivo. Con él, los libros viven en tu web: los ves y los cambias desde la app o desde el escritorio de WordPress, y no dependen de un solo dispositivo.

## Qué hace

- Añade a WordPress el contenido **Biblioteca**, con una ficha por libro o manga: autor, estado de lectura, fechas, ISBN, páginas, saga, veredicto, distinciones y portada.
- Ofrece la API que usa la app (`/wp-json/fb-books/v1/`).
- Busca los datos de un libro por ISBN o por título en Open Library y, si pones una clave propia, en Google Books.
- Guarda las portadas en Medios, convertidas a WebP cuando el servidor lo permite.

Los libros son **privados**: no tienen página pública ni aparecen en las búsquedas de la web. Solo se ven en el escritorio y en la app.

## Requisitos

- WordPress 6.4 o posterior y PHP 8.0 o posterior.
- La web debe usar **HTTPS**. La app no se conecta a direcciones sin cifrar y WordPress solo ofrece contraseñas de aplicación con HTTPS.

## Instalación

1. Descarga el ZIP del plugin.
2. En WordPress, ve a Plugins → Añadir nuevo → Subir plugin, elige el ZIP y actívalo.
3. Aparece el menú **Biblioteca** en el escritorio.

## Conectar la app

1. En WordPress, abre tu perfil (Usuarios → Perfil) y, en «Contraseñas de aplicación», crea una llamada «Biblioteca Codex». Cópiala: solo se muestra una vez.
2. En la app, abre Ajustes → Conexión con WordPress.
3. Escribe la dirección de tu web (con `https://`), tu usuario de WordPress y esa contraseña.

En Biblioteca → Ajustes tienes la dirección exacta que hay que escribir y un aviso si falta HTTPS o las contraseñas de aplicación están desactivadas.

### Si la app no conecta

- **Contraseñas de aplicación desactivadas.** Algunos plugins de seguridad las apagan. Vuelve a activarlas en sus ajustes.
- **El servidor no pasa la autenticación a WordPress.** Ocurre en algunos alojamientos. Guarda de nuevo Ajustes → Enlaces permanentes para que WordPress reescriba el `.htaccess`. Si sigue igual, añade esta línea al principio del `.htaccess`:

  ```
  SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
  ```

## Quién ve qué

- Un **autor** o un **colaborador** ve y cambia solo sus propios libros. Un colaborador guarda borradores y no puede publicar.
- Un **editor** o un **administrador** ve y cambia los libros de todos.

En una web de una sola persona esto no se nota: cada uno tiene su biblioteca.

## Ajustes

En Biblioteca → Ajustes:

- **Objetivo de lecturas del año**, que la app muestra en el perfil. Cuentan los libros leídos y las series de manga completas; los tomos sueltos no suman.
- **Clave de Google Books**, opcional. Sin ella los libros se buscan solo en Open Library.
- **Libros de ejemplo**: añade trece libros y mangas a un usuario, para probar la app.

## Privacidad

El plugin no envía nada a su autor ni usa servicios de análisis. Solo se conecta con el exterior en estos casos:

- **Open Library** (`openlibrary.org`): al buscar un libro se envía su ISBN o su título.
- **Google Books** (`googleapis.com`): solo si guardas una clave propia; se envían el ISBN o el título y esa clave.
- **La dirección de una portada**: si das una portada como dirección, el servidor la descarga a Medios.

## Relación con el plugin Biblioteca

Este plugin usa los mismos nombres internos que el plugin completo «Biblioteca» (el contenido `fb_book`, sus campos y la API `fb-books/v1`), que además dibuja la biblioteca en la web. Una biblioteca pasa de uno a otro sin cambios. Si los dos están activos, Biblioteca Codex se queda quieto y avisa.

Lo que este plugin no incluye: las imágenes de los veredictos, la página pública de la biblioteca, los sellos, los adornos, la galería de obras maestras y el resumen del año.

## Desarrollo

`tests/api-test.php` comprueba la API desde dentro de WordPress, sin servidor web. Se ejecuta en una instalación de pruebas, porque crea y borra libros:

```
wp user create autora autora@example.test --role=author
wp user create otro otro@example.test --role=author
wp user create colabora colabora@example.test --role=contributor
wp eval-file wp-content/plugins/biblioteca-codex/tests/api-test.php
```

`bin/empaquetar.sh` crea el ZIP para instalar, sin las pruebas.

## Licencia

El plugin se distribuye bajo la licencia GPL, versión 2 o posterior (ver `LICENSE`).

No incluye imágenes: los trofeos y las medallas de los veredictos los pone la app.
