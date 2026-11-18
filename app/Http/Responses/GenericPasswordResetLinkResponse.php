<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

class GenericPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    /**
     * Answer a failed reset-link request exactly as a successful one, so the form can't reveal which accounts exist.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $status = __(Password::RESET_LINK_SENT);

        return $request->wantsJson()
            ? new JsonResponse(['message' => $status], 200)
            : back()->with('status', $status);
    }
}
