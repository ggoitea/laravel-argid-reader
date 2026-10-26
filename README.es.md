# Laravel ArgID Reader

[English](README.md)

Paquete para Laravel que lee el **DNI argentino** a partir de imágenes o PDFs del **frente y dorso**. Extrae la información disponible combinando la lectura de **códigos de barras PDF417/QR** con **OCR de la MRZ TD1** del dorso, e incluye la extracción del **domicilio** impreso en la tarjeta.

Devuelve un resultado estructurado e inmutable, y no resuelve conflictos entre frente y dorso de forma silenciosa.

## Características

- Lee archivos JPG, JPEG, PNG, WebP y PDF.
- Soporta un único PDF de 2 páginas para ambas caras, o dos archivos separados.
- Primero intenta los códigos de barras PDF417/QR (frente y dorso).
- Si faltan campos obligatorios, recurre a la MRZ TD1 con OCR y extrae el domicilio.
- Valida dígitos de control de la MRZ, DNI, trámite, sexo y CUIL.
- Fusiona los datos de barcode y MRZ y reporta conflictos.
- No registra datos personales en los logs.
- Limpia internamente los archivos temporales.
- Incluye autodescubrimiento para Laravel.

## Cómo funciona

1. `DniReadPipeline` ejecuta primero la etapa de códigos de barras.
2. Si obtuvo `dni`, `tramite` y `sexo`, devuelve el resultado.
3. Si no, la etapa MRZ lee el dorso con Tesseract, probando varias rotaciones y mejoras, y extrae el domicilio.
4. `DniDataMerger` combina ambas fuentes. Si hay diferencias en `dni`, `tramite` o `sexo`, el resultado es `conflicto` en lugar de elegir un valor arbitrariamente.

## Requisitos

### PHP

- PHP 8.3 o superior.
- Laravel 13.x.
- Extensiones de PHP: `fileinfo`, `mbstring`.

### Binarios externos

El paquete utiliza herramientas de línea de comandos externas. **No están incluidas** en el paquete y deben estar disponibles en el `PATH`:

| Herramienta | Binario | Uso |
|---|---|---|
| [ZXing-C++](https://github.com/zxing-cpp/zxing-cpp) | `ZXingReader` | Lectura de códigos PDF417 y QR |
| [Tesseract OCR](https://github.com/tesseract-ocr/tesseract) | `tesseract` | OCR de la MRZ y del domicilio |
| [ImageMagick](https://imagemagick.org/) | `magick`, `identify` | Inspección y preprocesamiento de imágenes |
| [Poppler](https://poppler.freedesktop.org/) | `pdfinfo`, `pdftoppm` | Conteo de páginas y rasterización de PDFs |

Tesseract necesita los datos de idioma **español (`spa`)** e **inglés (`eng`)**. Español se usa para la MRZ y el domicilio; inglés se usa como intento adicional para la MRZ.

#### Debian / Ubuntu

```bash
sudo apt-get update
sudo apt-get install -y \
    zxing-cpp-tools \
    tesseract-ocr tesseract-ocr-spa tesseract-ocr-eng \
    imagemagick \
    poppler-utils
```

#### macOS (Homebrew)

```bash
brew install zxing-cpp tesseract tesseract-lang imagemagick poppler
```

#### Verificar la instalación

```bash
ZXingReader --version
tesseract --version
tesseract --list-langs
magick -version
pdfinfo -v
pdftoppm -v
```

La salida de `tesseract --list-langs` debe incluir `spa` y `eng`.

## Instalación

```bash
composer require ggoitea/laravel-argidreader
```

### Autodescubrimiento en Laravel

Laravel descubre automáticamente `Ggoitea\LaravelArgIdReader\ArgIdReaderProvider` mediante la entrada `extra.laravel.providers` del `composer.json`.

Si tu aplicación tiene deshabilitado el descubrimiento de paquetes, registrá el provider manualmente en `bootstrap/providers.php`:

```php
return [
    // ...
    Ggoitea\LaravelArgIdReader\ArgIdReaderProvider::class,
];
```

## Uso

### Ejemplo básico

```php
use Ggoitea\LaravelArgIdReader\Contracts\ReadPipeline;
use Ggoitea\LaravelArgIdReader\Domain\DniImages;
use Ggoitea\LaravelArgIdReader\Domain\ReadStatus;

$reader = app(ReadPipeline::class);

$images = new DniImages(
    frente: storage_path('app/dni/frente.jpg'),
    dorso: storage_path('app/dni/dorso.jpg'),
);

$report = $reader->read($images);

if ($report->status === ReadStatus::Completo && $report->data !== null) {
    $data = $report->data->toArray();
}
```

También podés inyectar la implementación concreta:

```php
use Ggoitea\LaravelArgIdReader\Pipeline\DniReadPipeline;

$reader = app(DniReadPipeline::class);
```

### Ejemplo de controller con archivos subidos

Los archivos temporales de una subida normalmente no tienen extensión. Guardalos primero para que el resolver pueda detectar el formato y eliminálos después de leer:

```php
namespace App\Http\Controllers;

use Ggoitea\LaravelArgIdReader\Contracts\ReadPipeline;
use Ggoitea\LaravelArgIdReader\Domain\DniImages;
use Ggoitea\LaravelArgIdReader\Domain\ReadStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class DniController extends Controller
{
    public function __invoke(Request $request, ReadPipeline $reader): JsonResponse
    {
        $validated = $request->validate([
            'frente' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:25600'],
            'dorso' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:25600'],
        ]);

        $frente = $validated['frente']->store('dni-tmp', 'local');
        $dorso = $validated['dorso']->store('dni-tmp', 'local');

        try {
            $report = $reader->read(new DniImages(
                frente: Storage::disk('local')->path($frente),
                dorso: Storage::disk('local')->path($dorso),
            ));

            return match ($report->status) {
                ReadStatus::Completo => response()->json([
                    'status' => 'ok',
                    'data' => $report->data?->toArray(),
                ]),
                ReadStatus::Parcial => response()->json([
                    'status' => 'partial',
                    'data' => $report->data?->toArray(),
                    'missing_fields' => $report->missingFields,
                ], 422),
                ReadStatus::Conflicto => response()->json([
                    'status' => 'conflict',
                    'conflicts' => array_keys($report->conflicts),
                ], 409),
                default => response()->json([
                    'status' => $report->status->value,
                ], 422),
            };
        } finally {
            Storage::disk('local')->delete([$frente, $dorso]);
        }
    }
}
```

### Un único PDF con ambas caras

Si el frente y el dorso son dos páginas del mismo PDF, pasá la misma ruta dos veces. El pipeline usa la página 1 para el frente y la página 2 para el dorso automáticamente:

```php
$pdf = storage_path('app/dni/dni.pdf');

$report = $reader->read(new DniImages(
    frente: $pdf,
    dorso: $pdf,
));
```

### Resultado

```php
$data = $report->data?->toArray();
```

Ejemplo de estructura (datos sintéticos):

```json
{
    "dni": "12345678",
    "tramite": "000000000",
    "apellido": "APELLIDO",
    "nombre": "NOMBRE",
    "sexo": "M",
    "fecha_nacimiento": "1990-01-01",
    "emision": "2024-01-01",
    "expiracion": null,
    "ejemplar": "A",
    "nacionalidad": "ARG",
    "cuil": null,
    "tipo_tarjeta": "2024",
    "domicilio": "CALLE FALSA 123, CIUDAD, PROVINCIA"
}
```

Las fechas se formatean como `Y-m-d`. Los campos que no se pudieron leer quedan en `null`.

### Estados

| Estado | Significado |
|---|---|
| `completo` | Se obtuvieron `dni`, `tramite` y `sexo`. Puede venir solo del código de barras o de la fusión con MRZ. |
| `parcial` | Se leyó información, pero falta al menos un campo obligatorio. |
| `conflicto` | Frente y dorso difieren en `dni`, `tramite` o `sexo`. Revisar `$report->conflicts`. |
| `sin_codigo` | No se encontró un código de barras ni una MRZ utilizable. |
| `sin_datos` | Las etapas se ejecutaron pero no se extrajeron datos parseables. |
| `no_soportado` | Se reconoció un payload/formato pero no está soportado. |
| `error` | Error de documento, imagen o proceso externo. |

### Depurar las etapas

Cada etapa se reporta con estado, duración y metadatos no sensibles:

```php
foreach ($report->stages as $stage) {
    echo $stage->stage.': '.$stage->status->value.' ('.round($stage->durationSeconds * 1000).'ms)'.PHP_EOL;
    print_r($stage->meta);
}
```

El reporte final también expone:

```php
$report->missingFields; // list<string>
$report->conflicts;     // array<string, string>
$report->durationSeconds;
```

## Formatos soportados

### Payloads de código de barras

| Versión | Partes `@` | Estructura | Notas |
|---|---|---|---|
| DNI 2012 | 9 | `tramite@apellido@nombre@sexo@dni@ejemplar@dd/mm/YYYY@dd/mm/YYYY@sufijo` | Aporta sexo y deriva el CUIL. `tipo_tarjeta = 2012`. |
| DNI 2024 | 8 | `tramite@apellido@nombre@dni@ejemplar@dd/mm/yy@dd/mm/yy@jwt` | No aporta sexo ni CUIL. `tipo_tarjeta = 2024`. |
| Otros/legacy | otra | — | No soportados por los parsers actuales; devuelve `no_soportado`. |

### MRZ TD1

Se espera una MRZ TD1 de 3×30 en el dorso:

```text
IDARG12345678<1<<<<<<<<<<<<<<<
9001015M3001011ARG<<<<<<<<<<<8
APELLIDO<<NOMBRE<FEDERICO<<<<<
```

El parser repara confusiones típicas de OCR (`O/0`, `I/1`, `S/5`, etc.), valida los dígitos de control y resuelve siglos ambiguos.

### Domicilio

El paquete busca un bloque que comience en `DOMICILIO` y termine en `LUGAR DE NACIMIENTO` en el dorso. Reintenta con distintos preprocesamientos para mejorar la lectura.

## Implementaciones personalizadas

Cada capacidad externa está detrás de un contrato. Podés reemplazar cualquier binding después de que se registre el provider del paquete:

```php
use Ggoitea\LaravelArgIdReader\Contracts\BarcodeReader;

$this->app->bind(BarcodeReader::class, MyBarcodeReader::class);
```

Contratos disponibles:

- `BarcodeReader`
- `OcrReader`
- `ImagePreprocessor`
- `ImageProbe`
- `ProcessRunner`
- `ReadPipeline`

Esto permite usar otro CLI de códigos de barras, otro motor de OCR, otra librería de imágenes o un runner de procesos con tus propias políticas de timeout y logging.

## Privacidad y manejo de datos

- `ReadReport::toLogContext()` excluye datos personales y rutas de archivos.
- Los pipelines no registran el texto del OCR ni los payloads crudos.
- Las imágenes temporales creadas por el paquete se limpian en bloques `finally`.
- El paquete no persiste el resultado ni los archivos subidos.
- Eliminá las imágenes/PDFs originales después de procesarlos, salvo que tu aplicación esté legalmente autorizada a conservarlos.
- No subas imágenes reales de DNI ni datos personales reales a este repositorio.
- En Argentina, los datos del DNI son datos personales protegidos por la Ley 25.326. Aplicá las reglas de conservación y seguridad que correspondan a tu caso de uso.

## Troubleshooting

| Síntoma | Causa probable | Solución |
|---|---|---|
| `status = error`, `probe.* = error`, código `imagen_chica` | Imagen demasiado pequeña | Usar una imagen de al menos 300 px en el lado menor. |
| `status = error`, código `imagen_demasiado_grande` | Más de 40 millones de píxeles | Reducir la resolución antes de procesar. |
| `barcode.* = sin_codigo` y sin datos de MRZ | Foto borrosa, oscura o con reflejos | Mejorar la iluminación, evitar el flash y mantener la tarjeta derecha. |
| `parse.* = no_soportado` | Payload desconocido o esquema nuevo | Revisar el payload crudo en un entorno controlado y verificar la cantidad de `@`. |
| `status = conflicto` | Frente y dorso con valores distintos | Verificar que ambas imágenes correspondan a la misma tarjeta. |
| `parse.mrz = sin_datos` | MRZ ilegible o dígitos de control inválidos | Volver a capturar el dorso y mejorar el contraste del tercio inferior. |
| `domicilio` en `null` | El OCR no encontró el bloque de domicilio | Verificar que el dorso muestre `DOMICILIO` y `LUGAR DE NACIMIENTO`. |
| Errores de procesos externos | Faltan binarios | Ejecutar los comandos de verificación y revisar el `PATH`. |

## Testing

```bash
composer install
composer test
```

El runtime soporta PHP 8.3+, pero PHPUnit 13 requiere PHP 8.4.1+ para ejecutar la suite de desarrollo.

## Licencia

MIT. Ver [LICENSE](LICENSE).
