<?php

    namespace App\Exceptions;

    use Exception;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Auth\AuthenticationException;
    use Illuminate\Database\Eloquent\ModelNotFoundException;
    use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
    use Illuminate\Http\Exceptions\HttpResponseException;
    use Illuminate\Http\Request;
    use Illuminate\Session\TokenMismatchException;
    use Illuminate\Validation\ValidationException;
    use Illuminate\View\ViewException;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpKernel\Exception\HttpException;
    use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
    use Throwable;

    class Handler extends ExceptionHandler
    {
        /**
         * A list of the exception types that are not reported.
         *
         * @var array
         */
        protected $dontReport = [
            AuthenticationException::class,
            AuthorizationException::class,
            HttpException::class,
            ModelNotFoundException::class,
            ValidationException::class,
            TokenMismatchException::class,
            GeneralException::class,
        ];

        /**
         * A list of the inputs that are never flashed for validation exceptions.
         *
         * @var array
         */
        protected $dontFlash = [
            'password',
            'password_confirmation',
        ];

        /**
         * Report or log an exception.
         *
         *
         * @return void
         *
         * @throws Exception
         * @throws Throwable
         */
        public function report(Throwable $exception)
        {
            parent::report($exception);
        }

        /**
         * Render an exception into an HTTP response.
         *
         * @param Request $request
         * @return Response
         *
         * @throws Throwable
         */
        public function render($request, Throwable $exception)
        {

            // The application's JSON error envelope is unchanged:
            // {"status": "error", "message": ...}. What changed is the HTTP
            // status it travels with. This used to be 200 for EVERY exception,
            // so a JSON client could not tell "not found", "not signed in",
            // "invalid input" or a server fault from success without parsing the
            // body — and a tenancy denial answered a foreign Account's request
            // with a success status. The status now says what actually happened.
            if ($request->wantsJson()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
                ], $this->jsonStatusFor($exception));
            }

            if (config('app.env') != 'local') {
                if ($exception instanceof ViewException || $exception instanceof ModelNotFoundException) {
                    return response()->view('errors.500', compact('exception'), 500);
                }
                if ($exception instanceof AuthenticationException || $exception instanceof AuthorizationException) {
                    return response()->view('errors.401', compact('exception'), 401);
                }

                if ($exception instanceof TokenMismatchException) {
                    return response()->view('errors.419', compact('exception'), 419);
                }

                if ($exception instanceof HttpException) {
                    return response()->view('errors.404', compact('exception'), 404);
                }
            }

            if ($exception instanceof GeneralException) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
                ]); // or 422 or custom code
            }


            return parent::render($request, $exception);
        }

        /**
         * The HTTP status a JSON error response carries: the exception's own.
         *
         * LARAVEL'S CANONICAL MAPPING FIRST. `prepareException()` is the
         * framework's own translation of domain exceptions into HTTP ones —
         * ModelNotFound and RecordsNotFound become 404, a token mismatch 419, a
         * suspicious operation 404, a bad request 400 — so this reuses it rather
         * than restating a table that would drift from the framework.
         *
         * TWO APPLICATION CONVENTIONS ARE KEPT, DELIBERATELY, because "change
         * the status, not the contract" is the scope of this fix:
         *
         *   AuthorizationException → 401, unless the denial carries its own
         *   status. That is what this application's HTML branch already
         *   renders in every non-local environment (errors.401), and what its
         *   permission-denial tests assert. A page request and an AJAX request
         *   for the same denial must not disagree.
         *
         *   GeneralException → 200. It is not an HTTP exception and has no
         *   status of its own: it is this application's user-facing business
         *   error, thrown in well over a hundred places, and render() already
         *   answers it as a 200 JSON body for non-JSON requests below. The
         *   legacy AJAX screens read `status: error` from that 200. Giving it a
         *   4xx/5xx would be redefining a business-error contract, not
         *   correcting an HTTP status.
         *
         * Anything else that carries no HTTP status is an unexpected server
         * fault, and says so with 500.
         */
        private function jsonStatusFor(Throwable $exception): int
        {
            if ($exception instanceof GeneralException) {
                return Response::HTTP_OK;
            }

            if ($exception instanceof HttpResponseException) {
                return $exception->getResponse()->getStatusCode();
            }

            if ($exception instanceof AuthenticationException) {
                return Response::HTTP_UNAUTHORIZED;
            }

            if ($exception instanceof AuthorizationException) {
                return $exception->hasStatus() ? (int) $exception->status() : Response::HTTP_UNAUTHORIZED;
            }

            if ($exception instanceof ValidationException) {
                return (int) $exception->status;
            }

            $prepared = $this->prepareException($this->mapException($exception));

            if ($prepared instanceof HttpExceptionInterface) {
                return $prepared->getStatusCode();
            }

            return Response::HTTP_INTERNAL_SERVER_ERROR;
        }

    }
