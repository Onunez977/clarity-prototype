<?php

declare(strict_types=1);

namespace App\Services\Zones;

use RuntimeException;

/**
 * A zone write produced no usable area, or a ZIP that ST_Union would silently drop.
 *
 * Thrown from inside the write transaction so the row rolls back. The message is
 * the text shown to the person saving the zone.
 */
class ZoneGeometryRejected extends RuntimeException {}
