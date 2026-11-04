<?php
/**
 * Bucle de function calling con herramientas en PHP (versión genérica).
 *
 * El modelo conversa y ELIGE qué herramienta usar; PHP la ejecuta contra los datos
 * reales y le devuelve el resultado. Donde hay cifras, fechas o catálogo, decide el
 * código: el modelo nunca calcula un precio ni interpreta una fecha por su cuenta.
 *
 * Detalles:
 *   - Máximo 3 rondas: si el modelo sigue pidiendo herramientas, se corta.
 *   - Las tarjetas para la interfaz se ACUMULAN entre llamadas, sin repetir y con tope,
 *     para que «compara A contra B» (dos llamadas) muestre las dos.
 *   - Una herramienta desconocida o con argumentos inválidos devuelve un error que el
 *     modelo puede leer, en vez de tumbar la conversación.
 *
 * Para ejecutarlo sin clave de API, el ejemplo usa un «modelo guionizado» que devuelve
 * respuestas fijas con el mismo formato que la API de chat de OpenAI.
 *
 * Ejecutar el ejemplo:  php bucle-de-herramientas.php
 */

declare(strict_types=1);

const MAX_RONDAS  = 3;
const MAX_TARJETAS = 3;

/** Herramientas: definición (JSON Schema para el modelo) + implementación en PHP. */
function herramientas(): array
{
    return [
        'comparar_versiones' => [
            'definicion' => [
                'type' => 'function',
                'function' => [
                    'name'        => 'comparar_versiones',
                    'description' => 'Devuelve todas las versiones de un modelo del catálogo.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => ['modelo' => ['type' => 'string']],
                        'required'   => ['modelo'],
                    ],
                ],
            ],
            'ejecutar' => function (array $args): array {
                $catalogo = [
                    'Aurora' => [['version' => 'Base', 'precio' => 489000], ['version' => 'Touring', 'precio' => null]],
                    'Cima'   => [['version' => 'Sport', 'precio' => 612000]],
                ];
                $modelo = (string) ($args['modelo'] ?? '');
                if (!isset($catalogo[$modelo])) {
                    return ['error' => "El modelo «{$modelo}» no está en el catálogo."];
                }
                return ['modelos' => [['modelo' => $modelo, 'versiones' => $catalogo[$modelo]]]];
            },
        ],
    ];
}

/**
 * @param callable(array $mensajes, array $definiciones): array $modelo  devuelve el mensaje del asistente
 * @return array{respuesta:string, tarjetas:array}
 */
function conversar(array $mensajes, callable $modelo): array
{
    $registro = herramientas();
    $definiciones = array_column($registro, 'definicion');
    $tarjetas = [];
    $respuesta = '';

    for ($ronda = 0; $ronda < MAX_RONDAS; $ronda++) {
        $msg = $modelo($mensajes, $definiciones);
        $mensajes[] = $msg;

        if (empty($msg['tool_calls'])) {
            $respuesta = trim((string) ($msg['content'] ?? ''));
            break;
        }

        foreach ($msg['tool_calls'] as $llamada) {
            $nombre = $llamada['function']['name'] ?? '';
            $args   = json_decode($llamada['function']['arguments'] ?? '{}', true) ?: [];
            $resultado = isset($registro[$nombre])
                ? ($registro[$nombre]['ejecutar'])($args)
                : ['error' => "Herramienta desconocida: {$nombre}"];

            foreach ($resultado['modelos'] ?? [] as $tarjeta) {       // acumular sin repetir, con tope
                $ya = in_array($tarjeta['modelo'], array_column($tarjetas, 'modelo'), true);
                if (!$ya && count($tarjetas) < MAX_TARJETAS) {
                    $tarjetas[] = $tarjeta;
                }
            }
            $mensajes[] = [
                'role'         => 'tool',
                'tool_call_id' => $llamada['id'] ?? '',
                'content'      => json_encode($resultado, JSON_UNESCAPED_UNICODE),
            ];
        }
    }

    return ['respuesta' => $respuesta ?: '¿Me das un poco más de detalle para ayudarte?', 'tarjetas' => $tarjetas];
}

/** Precio para mostrar: sin cifra capturada, nunca se inventa. */
function precioVisible(?int $precio): string
{
    return $precio === null ? 'POR CONFIRMAR' : '$' . number_format($precio) . ' (precio desde)';
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    // «Modelo guionizado»: primera ronda pide dos herramientas; segunda responde con texto.
    $guion = [
        ['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'comparar_versiones', 'arguments' => '{"modelo":"Aurora"}']],
            ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'comparar_versiones', 'arguments' => '{"modelo":"Cima"}']],
        ]],
        ['role' => 'assistant', 'content' => 'Te dejo las dos comparativas: Aurora tiene dos versiones y Cima una.'],
    ];
    $modelo = function () use (&$guion) { return array_shift($guion); };

    $r = conversar([['role' => 'user', 'content' => 'Compara Aurora contra Cima']], $modelo);
    echo "Respuesta: {$r['respuesta']}\n";
    foreach ($r['tarjetas'] as $t) {
        foreach ($t['versiones'] as $v) {
            printf("  Tarjeta: %s %s — %s\n", $t['modelo'], $v['version'], precioVisible($v['precio']));
        }
    }
}
