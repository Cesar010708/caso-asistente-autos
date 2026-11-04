<?php
/**
 * Venta guiada sin modelo de lenguaje (versión genérica).
 *
 * «Encuentra tu auto» en cuatro pasos: presupuesto, plazas, uso y prioridad. Es un
 * flujo determinista: filtra y puntúa el catálogo con reglas, así la recomendación es
 * reproducible y no depende de cómo el modelo interprete el texto. Lo mismo se usa
 * como herramienta del asistente cuando la persona escribe en lugar de tocar opciones:
 * el modelo solo traduce su texto a estos parámetros.
 *
 * Las plazas son filtro duro; el presupuesto solo mueve el puntaje (un modelo que
 * se pasa no desaparece: baja). Un modelo sin precio capturado queda neutral y se
 * muestra con «POR CONFIRMAR», nunca con una cifra inventada.
 *
 * Ejecutar el ejemplo:  php venta-guiada.php
 */

declare(strict_types=1);

/** Intención → palabras clave del campo «uso recomendado» del catálogo. */
const INTENCIONES = [
    'terreno'   => ['terracería', 'terreno', 'brecha', 'rural', 'camino'],
    'familia'   => ['familia', 'niños', 'espacio', 'escuela'],
    'ciudad'    => ['ciudad', 'urbano', 'tráfico', 'compacto'],
    'carretera' => ['carretera', 'viajes', 'largas distancias'],
];

/** @return array<int, array{modelo:string, puntos:int, precio:string}> los 3 mejores */
function recomendar(array $catalogo, ?float $presupuesto, ?int $plazas, string $uso, string $prioridad): array
{
    $palabras = INTENCIONES[$uso] ?? [];
    $resultado = [];

    foreach ($catalogo as $m) {
        // Filtro duro: plazas mínimas.
        if ($plazas !== null && $m['plazas'] < $plazas) continue;

        $puntos = 0;
        // Presupuesto: solo aplica con precio conocido; los NULL quedan neutrales.
        if ($presupuesto !== null && $m['precio'] !== null) {
            $puntos += $m['precio'] <= $presupuesto ? 2 : -2;
        }
        foreach ($palabras as $p) {
            if (mb_stripos($m['uso'], $p) !== false) $puntos += 10;
        }
        if ($prioridad === 'seguridad' && $m['asistencias'])         $puntos += 8;
        if ($prioridad === 'economia' && $m['precio'] !== null)      $puntos += (int) max(0, 10 - $m['precio'] / 100000);
        if ($prioridad === 'terreno' && $m['altura_libre_mm'] >= 220) $puntos += 8;

        $resultado[] = [
            'modelo' => $m['modelo'],
            'puntos' => $puntos,
            'precio' => $m['precio'] === null ? 'POR CONFIRMAR' : '$' . number_format($m['precio']) . ' (desde)',
        ];
    }

    usort($resultado, fn ($a, $b) => $b['puntos'] <=> $a['puntos']);
    return array_slice($resultado, 0, 3);
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    // Catálogo ficticio.
    $catalogo = [
        ['modelo' => 'Aurora', 'plazas' => 5, 'precio' => 489000, 'uso' => 'Ciudad y viajes en familia',            'asistencias' => true,  'altura_libre_mm' => 200],
        ['modelo' => 'Cima',   'plazas' => 5, 'precio' => 612000, 'uso' => 'Terracería, brecha y caminos rurales',  'asistencias' => true,  'altura_libre_mm' => 240],
        ['modelo' => 'Brisa',  'plazas' => 4, 'precio' => 395000, 'uso' => 'Ciudad, compacto para tráfico',         'asistencias' => false, 'altura_libre_mm' => 160],
        ['modelo' => 'Sierra', 'plazas' => 7, 'precio' => null,   'uso' => 'Familia numerosa, viajes por carretera', 'asistencias' => true, 'altura_libre_mm' => 220],
    ];
    echo "Presupuesto 500 mil, 5 plazas, uso terreno, prioridad terreno:\n";
    foreach (recomendar($catalogo, 500000, 5, 'terreno', 'terreno') as $r) {
        printf("  %-7s %3d puntos  %s\n", $r['modelo'], $r['puntos'], $r['precio']);
    }
    echo "La Brisa queda fuera (4 plazas). La Cima se pasa del presupuesto y baja 2 puntos, pero\n";
    echo "sigue arriba por el uso; la Sierra no tiene precio y queda neutral.\n";
}
