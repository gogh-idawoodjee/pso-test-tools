<?php

use App\Support\PsoServices\PsoServicesSessionClient;
use App\Support\PsoServices\PsoServicesSessionException;
use App\Traits\PSOInteractionsTrait;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\PsoServicesFake;

it('logs on through pso-services and returns the session token', function () {
    Http::fake(PsoServicesFake::session('fresh-token'));

    $token = app(PsoServicesSessionClient::class)->logon('https://example.test', 'acc-1', 'test-user', 'secret-password');

    expect($token)->toBe('fresh-token');

    Http::assertSent(static fn ($request) => $request->method() === 'POST'
        && $request->url() === PsoServicesFake::sessionUrl()
        && $request->hasHeader('baseUrl', 'https://example.test')
        && $request->hasHeader('accountId', 'acc-1')
        && $request->hasHeader('username', 'test-user')
        && $request->hasHeader('password', 'secret-password'));
});

it('logs off through pso-services with the token header', function () {
    Http::fake(PsoServicesFake::session());

    app(PsoServicesSessionClient::class)->logoff('https://example.test', 'tok-abc');

    Http::assertSent(static fn ($request) => $request->method() === 'DELETE'
        && $request->url() === PsoServicesFake::sessionUrl()
        && $request->hasHeader('token', 'tok-abc')
        && $request->hasHeader('baseUrl', 'https://example.test'));
});

it('throws with the gateway error text and status on a 401 logon', function () {
    Http::fake([PsoServicesFake::sessionUrl() => Http::response(['error' => 'Unauthorized. Please check your session or login credentials.', 'details' => null], 401)]);

    try {
        app(PsoServicesSessionClient::class)->logon('https://example.test', 'acc-1', 'bad-user', 'bad-password');
        $this->fail('Expected a PsoServicesSessionException');
    } catch (PsoServicesSessionException $exception) {
        expect($exception->statusCode)->toBe(401);
        expect($exception->getMessage())->toContain('Unauthorized');
    }
});

it('surfaces the first validation error when pso-services answers 422', function () {
    Http::fake([PsoServicesFake::sessionUrl() => Http::response([
        'message' => 'Production URLs are not allowed',
        'errors' => ['baseUrl' => ['Production URLs are not allowed']],
    ], 422)]);

    try {
        app(PsoServicesSessionClient::class)->logon('https://prod.example.test', 'acc-1', 'user', 'password');
        $this->fail('Expected a PsoServicesSessionException');
    } catch (PsoServicesSessionException $exception) {
        expect($exception->statusCode)->toBe(422);
        expect($exception->getMessage())->toContain('Production URLs are not allowed');
    }
});

it('throws a status-zero exception when pso-services cannot be reached', function () {
    Http::fake([PsoServicesFake::sessionUrl() => static function () {
        throw new ConnectionException('Connection timed out');
    }]);

    try {
        app(PsoServicesSessionClient::class)->logon('https://example.test', 'acc-1', 'user', 'password');
        $this->fail('Expected a PsoServicesSessionException');
    } catch (PsoServicesSessionException $exception) {
        expect($exception->statusCode)->toBe(0);
        expect($exception->getMessage())->toContain('could not be reached');
    }
});

it('throws when the logon reply carries no session token', function () {
    Http::fake([PsoServicesFake::sessionUrl() => Http::response(['data' => 'Auth was successful.', 'message' => [], 'status' => 200], 200)]);

    expect(static fn () => app(PsoServicesSessionClient::class)->logon('https://example.test', 'acc-1', 'user', 'password'))
        ->toThrow(PsoServicesSessionException::class, 'without a session token');
});

it('keeps authenticatePSO() returning null with error_value 401 on bad credentials', function () {
    Http::fake(PsoServicesFake::session(logonStatus: 401));

    $probe = new class
    {
        use PSOInteractionsTrait;

        public function authenticate(): ?string
        {
            return $this->authenticatePSO('https://example.test', 'acc-1', 'user', 'password');
        }

        public function errorValue(): ?int
        {
            return $this->error_value;
        }

        public function errorMessage(): ?string
        {
            return $this->session_error_message;
        }
    };

    expect($probe->authenticate())->toBeNull();
    expect($probe->errorValue())->toBe(401);
    expect($probe->errorMessage())->not->toBeNull();
});

it('reports error_value 500 when pso-services is unreachable', function () {
    Http::fake([PsoServicesFake::sessionUrl() => static function () {
        throw new ConnectionException('Connection timed out');
    }]);

    $probe = new class
    {
        use PSOInteractionsTrait;

        public function authenticate(): ?string
        {
            return $this->authenticatePSO('https://example.test', 'acc-1', 'user', 'password');
        }

        public function errorValue(): ?int
        {
            return $this->error_value;
        }
    };

    expect($probe->authenticate())->toBeNull();
    expect($probe->errorValue())->toBe(500);
});
