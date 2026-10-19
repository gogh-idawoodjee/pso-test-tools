<?php

namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\Task;
use App\Support\Booking\OfferRelease;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The booking page's leave-page beacon: releases the open request's offers. Answers 204 whatever services says. */
class ReleaseAppointmentOffersController extends Controller
{
    public function __invoke(Request $request, string $task): Response
    {
        $validated = $request->validate(['appointment_request_id' => ['required', 'string', 'max:64']]);

        // Found without the ownership scope so another user's task is refused (403), not hidden.
        $record = Task::withoutGlobalScopes()->with('dataset')->findOrFail($task);
        $ownerId = Environment::withoutGlobalScopes()->whereKey($record->dataset?->environment_id)->value('user_id');
        abort_unless($ownerId !== null && (int) $ownerId === auth()->id(), 403);

        (new OfferRelease($record))->release($validated['appointment_request_id']);

        return response()->noContent();
    }
}
