<?php

namespace App\Core\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

/**
 * Class Handler
 * 
 * Gère les exceptions globales et les transforme en réponses API appropriées
 */
class Handler extends ExceptionHandler
{
    /**
     * Les exceptions qui ne doivent pas être signalées
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * Inscrivez les callables de rendu d'exceptions personnalisées
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
