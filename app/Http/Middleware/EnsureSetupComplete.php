<?php

namespace App\Http\Middleware;

use App\Services\SetupState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the signed-in user to the next unfinished first-run step.
 * Managers get the real setup pages; cashiers get a "ask your manager" page.
 */
class EnsureSetupComplete
{
    public function __construct(protected SetupState $setup) {}

    /**
     * Usage: `setup.complete` (all steps) or `setup.complete:password` (only
     * require the bootstrap password to be replaced — used by the connect and
     * profile-selection pages themselves).
     */
    public function handle(Request $request, Closure $next, ?string $only = null): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $step = $this->setup->nextStepFor($user);
        if ($only === 'password' && $step !== SetupState::PASSWORD) {
            return $next($request);
        }
        if ($step === null) {
            return $next($request);
        }

        if ($step !== SetupState::PASSWORD && ! $user->isManager()) {
            return redirect()->route('setup.waiting');
        }

        return redirect()->route('setup.'.$step);
    }
}
