<?php

namespace App\Http\Middleware;

use App\Support\ActiveEventContext;
use App\Support\CompetitionBootstrap;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompetitionContext
{
    /**
     * The competition engine requires an internal event context (event_id),
     * but the event is never exposed in the URL. On every competition request
     * this middleware guarantees an active competition event is resolved and
     * set as the active event context, so direct access/refresh keeps working.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(ActiveEventContext::class);

        if ($context->current() === null) {
            $context->set(app(CompetitionBootstrap::class)->ensureActiveCompetitionEvent());
        }

        return $next($request);
    }
}