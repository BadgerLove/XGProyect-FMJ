<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->renderable(function (LegacyView $e) {
            return response($e->getView());
        });

        // 419 "Page Expired" = the form's security token belongs to a session that no longer exists
        // (front page left open in a tab, restored by the phone, or its session cleaned up). It was a
        // dead end with no link back (2026-10-01): send people to the front page to log in again,
        // keeping the username they typed. If they are actually still logged in, the front page
        // forwards them straight into the game.
        $this->renderable(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }

            return redirect('/')
                ->withInput($request->only('username'))
                ->withErrors(['username' => __('home/welcome.hm_login_expired')], 'login');
        });

        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
