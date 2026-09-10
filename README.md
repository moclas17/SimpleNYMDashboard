# Nym Node Monitor

Dashboard compacto para gateways y mixnodes Nym. Muestra staking, rendimiento, rewards pendientes y, cuando el nodo ofrece API HTTP, métricas directas y saldo de la wallet del servicio.

## Requisitos e instalación

- PHP 8.x con cURL y acceso HTTPS saliente.
- Apache, Nginx con PHP, XAMPP o el servidor de desarrollo de PHP.
- Navegador moderno con soporte BigInt.

Descarga o clona el repositorio, edita `config.php` y sirve la carpeta con PHP. Para una prueba local:

```sh
php -S 127.0.0.1:8088
```

Abre http://127.0.0.1:8088/. En XAMPP coloca la carpeta en `htdocs` y abre la ruta correspondiente. No requiere Composer, Node.js, base de datos ni claves privadas. **GitHub Pages no ejecuta PHP**; GitHub puede alojar el código, pero el dashboard necesita un servidor PHP.

## Configuración: un solo archivo

Edita exclusivamente `config.php` para agregar, quitar o reordenar nodos. La lista incluida reproduce los cinco nodos actuales. Cada entrada usa:

| Campo | Contenido |
| --- | --- |
| `name` | Nombre visible de la tarjeta. |
| `node_id` | ID numérico del explorador, como entero sin comillas. |
| `identity` | Clave pública de identidad del nodo, no la dirección de wallet. |
| `kind` | `gateway` o `mixnode`. |
| `api_url` | URL base opcional; `null` si no tiene webserver. |

Ejemplo de mixnode sin API:

```php
['name' => 'Mi mixnode', 'node_id' => 934,
 'identity' => 'DovWm8ShDSygGCggmoQNVMegkq4SbZ1w5NQFzeYq9VzD',
 'kind' => 'mixnode', 'api_url' => null],
```

El ID y la identidad deben corresponder al mismo nodo: el backend los comprueba para no mostrar datos de otro. Ambos aparecen en el explorador Nym/SpectreDAO. No repitas IDs ni identidades.

Para gateways, `api_url` admite un host o IP con esquema y puerto, por ejemplo `https://mi-nodo.example` o `http://203.0.113.10:8080`. Es la base del servicio, **sin `/api/swagger` ni `/api/v1`**. Se mantiene la verificación TLS y no se siguen redirecciones. La URL solo puede configurarse en el archivo del servidor, nunca mediante parámetros web. No uses URLs con credenciales.

`api_url => null` también funciona con gateways: solo omite las métricas directas y el saldo de wallet del servicio; conserva staking, rewards y pruebas de red. En mixnodes no se consulta una API directa.

`refresh_seconds` controla la actualización automática (mínimo 30 segundos). Tarjetas, orden y totales se generan automáticamente. `nodes => []` muestra un estado vacío. Los valores inválidos producen un error controlado y detalles en el log de PHP.

## Datos y alcance

- SpectreDAO: rendimiento, staking, actividad, configuración y wallet de bonding. Es la fuente utilizada también por el explorador oficial Nym.
- API oficial de gateways: pruebas de conectividad con fecha de ejecución.
- Contrato Mixnet de Nyx: recompensas del operador pendientes de cobrar, por ID de nodo.
- API directa opcional: salud, uptime, tráfico, paquetes, términos y wallet del servicio; saldo consultado en Nyx.

Los importes se muestran con dos decimales, pero las sumas usan enteros en unym antes de redondear. Rewards no son saldo bancario ni ganancias históricas. El total de rewards requiere respuesta de todos los nodos; nunca se convierte un error en cero. Los resúmenes directos solo incluyen gateways con `api_url` configurada. Las fechas de red y pruebas pueden ser anteriores a la consulta.

Las consultas son de lectura: no se cobra ni se firma ninguna transacción. El dashboard no tiene autenticación; al publicarlo, los nombres, direcciones y métricas mostrados serán visibles. No guardes secretos en `config.php`. Los valores que incluye son datos públicos; reemplázalos antes de compartir si prefieres otra lista.

## Archivos

- `config.php`: única lista editable de nodos e intervalo.
- `configuration.php`: validación compartida.
- `index.php`: interfaz y configuración pública mínima para el navegador.
- `nodes.php`: métricas directas y saldos.
- `explorer.php`: métricas de red, pruebas y rewards.
- `assets/`: estilos y JavaScript sin dependencias externas.

Para comprobar sintaxis: `php -l archivo.php`; opcionalmente `node --check assets/dashboard.js`.
