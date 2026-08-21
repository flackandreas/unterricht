<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Fehler der KI-Anbindung.
 *
 * Traegt bewusst keine Details des Anbieters, damit weder API-Schluessel noch
 * Antwortinhalte ueber eine Fehlermeldung nach aussen gelangen koennen.
 */
class AIServiceException extends \RuntimeException {}
