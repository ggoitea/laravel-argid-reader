# Laravel ArgID Reader

[Español](README.es.md)

Laravel package to read Argentine DNI cards from **front and back images or PDFs**. It extracts the data available on the document by combining **PDF417/QR barcode** reading with **TD1 MRZ OCR** on the back side, and also extracts the **address** printed on the card.

The package returns a structured, immutable result and never resolves front/back conflicts silently.

## Features

- Reads JPG, JPEG, PNG, WebP and PDF files.
- Supports a single 2-page PDF for both sides, or two separate files.
- First tries the PDF417/QR barcodes (front and back).
- Falls back to MRZ TD1 OCR + address extraction when required fields are missing.
- Validates MRZ check digits, DNI, trámite, sex and CUIL.
- Merges barcode and MRZ data and reports conflicts.
- Does not log personal data.
- Cleans up temporary files internally.
- Ships with Laravel auto-discovery.

## How it works

1. `DniReadPipeline` runs the barcode stage first.
2. If `dni`, `tramite` and `sexo` were obtained, the result is returned.
3. Otherwise, the MRZ stage reads the back side with Tesseract, using several rotations/enhancements, and extracts the address.
4. `DniDataMerger` combines both sources. Differences in `dni`, `tramite` or `sexo` produce a `conflicto` result instead of picking a value silently.

## Requirements

### PHP

- PHP 8.3 or newer.
- Laravel 13.x.
- PHP extensions: `fileinfo`, `mbstring`.

### External binaries

This package wraps external command-line tools. They are **not** bundled with the package and must be available on `PATH`:

| Tool | Binary | Used for |
|---|---|---|
| [ZXing-C++](https://github.com/zxing-cpp/zxing-cpp) | `ZXingReader` | PDF417 and QR barcode reading |
| [Tesseract OCR](https://github.com/tesseract-ocr/tesseract) | `tesseract` | MRZ and address OCR |
| [ImageMagick](https://imagemagick.org/) | `magick`, `identify` | Image probing and preprocessing |
| [Poppler](https://poppler.freedesktop.org/) | `pdfinfo`, `pdftoppm` | PDF page count and rasterization |

Tesseract needs the **Spanish (`spa`)** and **English (`eng`)** language data. Spanish is used for MRZ/address, English is used as an additional MRZ attempt.

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

#### Verify the installation

```bash
ZXingReader --version
tesseract --version
tesseract --list-langs
magick -version
pdfinfo -v
pdftoppm -v
```

`tesseract --list-langs` must include both `spa` and `eng`.

## Installation

```bash
composer require ggoitea/laravel-argidreader
```

### Laravel auto-discovery

Laravel discovers `Ggoitea\LaravelArgIdReader\ArgIdReaderProvider` automatically through the `extra.laravel.providers` entry in `composer.json`.

If your application has package discovery disabled, register the provider manually in `bootstrap/providers.php`:

```php
return [
    // ...
    Ggoitea\LaravelArgIdReader\ArgIdReaderProvider::class,
];
```

## Usage

### Basic example

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

You can also inject the concrete implementation:

```php
use Ggoitea\LaravelArgIdReader\Pipeline\DniReadPipeline;

$reader = app(DniReadPipeline::class);
```

### Controller example with uploaded files

Uploaded temporary files usually have no extension. Store them first so the resolver can detect the format, and delete them after reading:

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

### One PDF with both sides

If the front and back are two pages of the same PDF, pass the same path twice. The pipeline uses page 1 for the front and page 2 for the back automatically:

```php
$pdf = storage_path('app/dni/dni.pdf');

$report = $reader->read(new DniImages(
    frente: $pdf,
    dorso: $pdf,
));
```

### Result

```php
$data = $report->data?->toArray();
```

Example shape (synthetic data):

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

Dates are formatted as `Y-m-d`. Fields that could not be read are `null`.

### Statuses

| Status | Meaning |
|---|---|
| `completo` | `dni`, `tramite` and `sexo` were obtained. May come from barcodes only or from barcode + MRZ. |
| `parcial` | Some data was read, but at least one required field is missing. |
| `conflicto` | Front and back sources disagree on `dni`, `tramite` or `sexo`. Check `$report->conflicts`. |
| `sin_codigo` | Neither a usable barcode nor MRZ was found. |
| `sin_datos` | The stages ran but no parseable data was extracted. |
| `no_soportado` | A payload/format was recognized but is not supported. |
| `error` | Document, image or external process error. |

### Debugging stages

Every pipeline stage is reported with a status, duration and non-sensitive metadata:

```php
foreach ($report->stages as $stage) {
    echo $stage->stage.': '.$stage->status->value.' ('.round($stage->durationSeconds * 1000).'ms)'.PHP_EOL;
    print_r($stage->meta);
}
```

The final report also exposes:

```php
$report->missingFields; // list<string>
$report->conflicts;     // array<string, string>
$report->durationSeconds;
```

## Supported formats

### Barcode payloads

| Version | `@` parts | Structure | Notes |
|---|---|---|---|
| DNI 2012 | 9 | `tramite@apellido@nombre@sexo@dni@ejemplar@dd/mm/YYYY@dd/mm/YYYY@sufijo` | Provides sex and derives CUIL. `tipo_tarjeta = 2012`. |
| DNI 2024 | 8 | `tramite@apellido@nombre@dni@ejemplar@dd/mm/yy@dd/mm/yy@jwt` | Does not provide sex/CUIL. `tipo_tarjeta = 2024`. |
| Other/legacy | other | — | Not supported by the current parsers; returns `no_soportado`. |

### MRZ TD1

The back side is expected to contain a 3×30 TD1 MRZ:

```text
IDARG12345678<1<<<<<<<<<<<<<<<
9001015M3001011ARG<<<<<<<<<<<8
APELLIDO<<NOMBRE<FEDERICO<<<<<
```

The parser repairs common OCR confusions (`O/0`, `I/1`, `S/5`, etc.), validates check digits and resolves ambiguous centuries.

### Address

The package looks for a block starting at `DOMICILIO` and ending at `LUGAR DE NACIMIENTO` on the back side. It retries with different preprocessing to improve the scan.

## Custom implementations

Every external capability is behind a contract. You can override any binding after the package provider is registered:

```php
use Ggoitea\LaravelArgIdReader\Contracts\BarcodeReader;

$this->app->bind(BarcodeReader::class, MyBarcodeReader::class);
```

Available contracts:

- `BarcodeReader`
- `OcrReader`
- `ImagePreprocessor`
- `ImageProbe`
- `ProcessRunner`
- `ReadPipeline`

This is useful if you want to use a different barcode CLI, a different OCR engine, a different image library, or a process runner with your own timeout/logging policy.

## Privacy and data handling

- `ReadReport::toLogContext()` excludes personal data and file paths.
- Pipelines do not log OCR text or raw payloads.
- Temporary images created by the package are cleaned up in `finally` blocks.
- The package does not persist the result or the uploaded files.
- Delete the original images/PDFs after processing unless your application is legally allowed to keep them.
- Do not commit real DNI images or real personal data to this repository.
- In Argentina, DNI data is personal data protected by Ley 25.326. Apply the retention and security rules that correspond to your use case.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `status = error`, `probe.* = error`, code `imagen_chica` | Image is too small | Use an image with at least 300 px on the shorter side. |
| `status = error`, code `imagen_demasiado_grande` | More than 40 million pixels | Reduce the image resolution before processing. |
| `barcode.* = sin_codigo` and no MRZ data | Blurry, dark or glared photo | Improve lighting, avoid flash reflections, keep the card straight. |
| `parse.* = no_soportado` | Unknown payload or new schema | Inspect the raw payload in a controlled environment and check the `@` count. |
| `status = conflicto` | Front and back contain different values | Verify that both images belong to the same card. |
| `parse.mrz = sin_datos` | MRZ is unreadable or check digits fail | Retake the back side, improve contrast on the lower third. |
| `domicilio` is `null` | OCR did not find the address block | Make sure the back side shows `DOMICILIO` and `LUGAR DE NACIMIENTO`. |
| External process errors | Missing binaries | Run the verification commands above and check `PATH`. |

## Testing

```bash
composer install
composer test
```

The runtime supports PHP 8.3+, but PHPUnit 13 requires PHP 8.4.1+ to run the development test suite.

## License

MIT. See [LICENSE](LICENSE).
