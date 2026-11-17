<?php

require __DIR__ . '/../../vendor/autoload.php';

use ADT\ErrorLogger;
use Tracy\Debugger;
use Tracy\ILogger;

Debugger::$logDirectory = (string) getenv('ERROR_LOGGER_TEST_DIR');
$logger = ErrorLogger::install('errors@example.com');
$logger->mailer = function ($message, string $email, ?string $exceptionFile, ?string $exceptionMdFile): void {
	copy((string) $exceptionMdFile, Debugger::$logDirectory . '/attached.md');
};

function signIn(string $user, string $password, string ...$scopes): void
{
	throw new RuntimeException('Sign-in failed');
}

// built at runtime: the report shows this source too
$password = 'sec' . 'ret-password';
try {
	signIn('visible-user', $password, 'sec' . 'ret-scope');
} catch (Throwable $e) {
	Debugger::log($e, ILogger::EXCEPTION);
	file_put_contents(
		Debugger::$logDirectory . '/trace-restored',
		($e->getTrace()[0]['args'][1] ?? null) === $password ? 'yes' : 'no'
	);
}
