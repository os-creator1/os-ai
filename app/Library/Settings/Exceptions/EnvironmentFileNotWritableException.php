<?php

namespace App\Library\Settings\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A Platform setting could not be persisted because the environment (.env) file cannot be written or did not hold the
 * value after the write. Previously such a failure was silent and the page reported success.
 */
class EnvironmentFileNotWritableException extends RuntimeException
{
    public static function for(string $envKey): self
    {
        return new self("The setting {$envKey} could not be saved: the server's environment file is not writable by the web server. Nothing was changed. Ask your administrator to make the shared .env file writable by the PHP user.");
    }

    public function render(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 500);
        }

        return redirect()->back()->with(['status' => 'error', 'message' => $this->getMessage()]);
    }
}
