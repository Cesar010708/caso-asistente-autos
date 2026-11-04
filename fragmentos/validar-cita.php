<?php
/**
 * Validación de citas en código, no en el modelo (versión genérica).
 *
 * Un modelo de lenguaje que interpreta fechas se equivoca: «el miércoles 15» puede
 * terminar siendo un domingo. Por eso el formulario de la prueba de manejo vive dentro
 * del chat con opciones para elegir, y toda fecha pasa por esta validación antes de
 * guardarse: fecha real y futura, sin domingos y dentro del horario de atención, todo
 * en la zona horaria del negocio. Los mensajes de error están escritos para que el
 * propio asistente los use al pedir otra fecha.
 *
 * Ejecutar las comprobaciones:  php validar-cita.php
 */

declare(strict_types=1);

const ZONA = 'America/Mexico_City';

/** @return array{ok:bool, fecha?:string, hora?:string, error?:string} */
function validarCita(string $fecha, string $hora, ?DateTimeImmutable $ahora = null): array
{
    $tz = new DateTimeZone(ZONA);
    $ahora ??= new DateTimeImmutable('now', $tz);

    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, $tz);
    if (!$d || $d->format('Y-m-d') !== $fecha) {
        return ['ok' => false, 'error' => 'La fecha no es válida. Pídela de nuevo (por ejemplo: «el próximo viernes»).'];
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hora, $m)) {
        return ['ok' => false, 'error' => 'La hora no es válida. Pídela de nuevo (por ejemplo: «a las 11:00»).'];
    }
    [$hh, $mm] = [(int) $m[1], (int) $m[2]];
    $hora = sprintf('%02d:%02d', $hh, $mm);

    if ($fecha < $ahora->format('Y-m-d')) {
        return ['ok' => false, 'error' => 'Esa fecha ya pasó. Pide una fecha futura.'];
    }
    if ((int) $d->format('N') === 7) {
        return ['ok' => false, 'error' => 'Los domingos está cerrado. Ofrece otro día, de lunes a sábado.'];
    }
    if ($hh < 9 || $hh > 18 || ($hh === 18 && $mm > 0)) {
        return ['ok' => false, 'error' => 'El horario de atención es de 09:00 a 18:00.'];
    }
    if ($fecha === $ahora->format('Y-m-d') && $hora <= $ahora->format('H:i')) {
        return ['ok' => false, 'error' => 'Esa hora de hoy ya pasó. Ofrece una más tarde o el día siguiente.'];
    }
    return ['ok' => true, 'fecha' => $fecha, 'hora' => $hora . ':00'];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $ahora = new DateTimeImmutable('2026-07-15 12:30', new DateTimeZone(ZONA));   // miércoles
    $casos = [
        'jueves a las 11:00'          => [validarCita('2026-07-16', '11:00', $ahora)['ok'], true],
        '«miércoles 19» (es domingo)' => [validarCita('2026-07-19', '11:00', $ahora)['ok'], false],
        'fecha inexistente (31 de junio)' => [validarCita('2026-06-31', '11:00', $ahora)['ok'], false],
        'ayer'                        => [validarCita('2026-07-14', '11:00', $ahora)['ok'], false],
        'hoy a una hora que ya pasó'  => [validarCita('2026-07-15', '10:00', $ahora)['ok'], false],
        'hoy más tarde'               => [validarCita('2026-07-15', '16:30', $ahora)['ok'], true],
        'fuera de horario (19:00)'    => [validarCita('2026-07-16', '19:00', $ahora)['ok'], false],
        'hora sin cero (9:00)'        => [validarCita('2026-07-16', '9:00', $ahora)['hora'] ?? null, '09:00:00'],
    ];
    $fallas = 0;
    foreach ($casos as $nombre => [$obtenido, $esperado]) {
        $ok = $obtenido === $esperado;
        $fallas += $ok ? 0 : 1;
        printf("%s  %s\n", $ok ? 'OK   ' : 'FALLA', $nombre);
    }
    exit($fallas ? 1 : 0);
}
