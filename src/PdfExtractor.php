<?php
declare(strict_types=1);

/**
 * Extrae texto de un PDF y detecta campos relevantes (nombre, dirección,
 * fecha, teléfono, email).
 *
 * Estrategia:
 *  1) Intenta `pdftotext` (Poppler) vía shell si está disponible — rápido.
 *  2) Si existe `vendor/autoload.php`, prueba smalot/pdfparser.
 *  3) Si ninguna funciona, devuelve texto vacío (el caller decide).
 *
 * Para PDFs escaneados (sin capa de texto) habría que añadir OCR (Tesseract).
 * No se incluye de serie para no forzar dependencia del sistema, pero la
 * clase deja el hook `extractTextWithOcr()` preparado.
 */
final class PdfExtractor
{
    public function extract(string $pdfPath): array
    {
        if (!is_file($pdfPath)) {
            return ['text' => '', 'fields' => [], 'source' => 'missing'];
        }

        $text   = '';
        $source = 'none';

        // 1) pdftotext
        $txt = $this->extractWithPdftotext($pdfPath);
        if ($txt !== null && trim($txt) !== '') {
            $text = $txt; $source = 'pdftotext';
        }

        // 2) smalot/pdfparser
        if ($text === '') {
            $txt = $this->extractWithSmalot($pdfPath);
            if ($txt !== null && trim($txt) !== '') {
                $text = $txt; $source = 'smalot';
            }
        }

        // 3) OCR (opcional, requiere tesseract + pdftoppm).
        if ($text === '') {
            $txt = $this->extractTextWithOcr($pdfPath);
            if ($txt !== null && trim($txt) !== '') {
                $text = $txt; $source = 'tesseract';
            }
        }

        return [
            'text'   => $text,
            'fields' => $this->detectFields($text),
            'source' => $source,
        ];
    }

    private function extractWithPdftotext(string $pdfPath): ?string
    {
        if (!function_exists('shell_exec')) return null;
        // Verifica disponibilidad.
        $probe = @shell_exec((stripos(PHP_OS, 'WIN') === 0 ? 'where pdftotext' : 'command -v pdftotext'));
        if (!$probe) return null;

        $cmd = sprintf('pdftotext -layout -enc UTF-8 %s -', escapeshellarg($pdfPath));
        $out = @shell_exec($cmd . ' 2>&1');
        return is_string($out) ? $out : null;
    }

    private function extractWithSmalot(string $pdfPath): ?string
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (!is_file($autoload)) return null;
        require_once $autoload;
        if (!class_exists(\Smalot\PdfParser\Parser::class)) return null;

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($pdfPath);
            return $pdf->getText();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function extractTextWithOcr(string $pdfPath): ?string
    {
        if (!function_exists('shell_exec')) return null;
        $hasPdftoppm  = @shell_exec((stripos(PHP_OS, 'WIN') === 0 ? 'where pdftoppm'  : 'command -v pdftoppm'));
        $hasTesseract = @shell_exec((stripos(PHP_OS, 'WIN') === 0 ? 'where tesseract' : 'command -v tesseract'));
        if (!$hasPdftoppm || !$hasTesseract) return null;

        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ocr_' . bin2hex(random_bytes(4));
        @mkdir($tmpDir, 0775, true);
        $prefix = $tmpDir . DIRECTORY_SEPARATOR . 'page';

        @shell_exec(sprintf('pdftoppm -r 200 %s %s -png 2>&1',
            escapeshellarg($pdfPath), escapeshellarg($prefix)));

        $all = '';
        foreach (glob($prefix . '-*.png') ?: [] as $img) {
            $txt = @shell_exec(sprintf('tesseract %s - -l spa 2>&1', escapeshellarg($img)));
            if (is_string($txt)) $all .= $txt . "\n";
            @unlink($img);
        }
        @rmdir($tmpDir);
        return $all !== '' ? $all : null;
    }

    /**
     * Heurísticas basadas en regex para detectar campos comunes en contratos.
     * No es perfecto; está pensado como autocompletado sugerido que el usuario
     * valida después.
     */
    private function detectFields(string $text): array
    {
        $fields = [
            'name'    => null,
            'address' => null,
            'date'    => null,
            'email'   => null,
            'phone'   => null,
        ];
        if ($text === '') return $fields;

        // Email
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m)) {
            $fields['email'] = $m[0];
        }

        // Teléfono (ES: 9 dígitos, permitiendo prefijo y separadores)
        if (preg_match('/(?:\+?34[\s\-.]?)?(?:[6-9]\d{2}[\s\-.]?\d{3}[\s\-.]?\d{3})/', $text, $m)) {
            $fields['phone'] = preg_replace('/\s+/', ' ', trim($m[0]));
        }

        // Fecha: formatos comunes en español.
        $fields['date'] = $this->detectDate($text);

        // Nombre: patrones tipo "D./Dña./Don/Doña <Nombre Apellidos>"
        if (preg_match('/(?:D[\.\s]|Do[nñ]a?\s+|Nombre\s*[:\-]\s*|Cliente\s*[:\-]\s*)([A-ZÁÉÍÓÚÑ][\p{L}\-\']+(?:\s+[A-ZÁÉÍÓÚÑ][\p{L}\-\']+){1,4})/u', $text, $m)) {
            $fields['name'] = trim($m[1]);
        }

        // Dirección: "Calle/Avda./Av./Plaza/Paseo/C/ <texto hasta salto>"
        if (preg_match('/((?:C\/|Calle|Avda\.?|Avenida|Plaza|Paseo|Pº|Camino|Carretera|Ctra\.?)\s+[^\n\r]{3,120})/iu', $text, $m)) {
            $addr = trim($m[1]);
            // Cortar en coma final de CP/ciudad si hay mucho ruido.
            $fields['address'] = preg_replace('/\s{2,}/', ' ', $addr);
        }

        return $fields;
    }

    private function detectDate(string $text): ?string
    {
        // ISO: 2026-03-01
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        // dd/mm/yyyy o dd-mm-yyyy
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})\b/', $text, $m)) {
            $y = (int)$m[3]; if ($y < 100) $y += 2000;
            return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]);
        }
        // "1 de marzo de 2026"
        $meses = ['enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,
                  'julio'=>7,'agosto'=>8,'septiembre'=>9,'setiembre'=>9,'octubre'=>10,
                  'noviembre'=>11,'diciembre'=>12];
        if (preg_match('/\b(\d{1,2})\s+de\s+([a-záéíóú]+)\s+de\s+(\d{4})\b/iu', $text, $m)) {
            $mes = mb_strtolower($m[2], 'UTF-8');
            if (isset($meses[$mes])) {
                return sprintf('%04d-%02d-%02d', (int)$m[3], $meses[$mes], (int)$m[1]);
            }
        }
        return null;
    }
}
