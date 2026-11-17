<?php

namespace ADT\ErrorLogger\Tests;

use PHPUnit\Framework\TestCase;

final class MarkdownReportTest extends TestCase
{
	private string $dir;

	/** @var resource|null */
	private $server;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/error-logger-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}

	protected function tearDown(): void
	{
		if ($this->server) {
			proc_terminate($this->server);
			proc_close($this->server);
		}
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->dir);
	}

	/**
	 * Tracy writes the HTTP section of the report only outside CLI, so the request goes through
	 * the built-in web server.
	 */
	public function testSecretsOfWebRequestAreNotInAttachedMarkdown(): void
	{
		// built at runtime, the values must not be in any source shown by the report
		$secret = function (string $name): string {
			return 'sec' . 'ret-' . $name;
		};
		$port = $this->startServer();

		$context = stream_context_create(['http' => ['ignore_errors' => true, 'header' => implode("\r\n", [
			'Cookie: PHPSESSID=' . $secret('session') . '; app-debug=' . $secret('debug-token'),
			'Authorization: Bearer ' . $secret('bearer'),
			'Proxy-Authorization: Basic ' . $secret('proxy'),
			'X-Api-Key: ' . $secret('api-key'),
			'X-Visible: visible-header',
		])]]);
		file_get_contents("http://127.0.0.1:$port/sign-in?token=" . $secret('query') . '&page=visible-page', false, $context);

		$attached = (string) @file_get_contents($this->dir . '/attached.md');
		$this->assertStringContainsString('## HTTP Request', $attached);
		$this->assertStringContainsString('signIn', $attached);
		$this->assertStringContainsString('visible-user', $attached);
		$this->assertStringContainsString('visible-header', $attached);
		$this->assertStringContainsString('page=visible-page', $attached);

		$files = glob($this->dir . '/exception--*.md') ?: [];
		$this->assertCount(1, $files);
		foreach ([$attached, (string) file_get_contents($files[0])] as $markdown) {
			$this->assertStringNotContainsString($secret(''), $markdown);
		}
		$files = glob($this->dir . '/exception--*.html') ?: [];
		$this->assertCount(1, $files);
		$html = (string) file_get_contents($files[0]);
		$this->assertStringContainsString('visible-user', $html);
		// the URL, which Tracy takes straight from $_SERVER (the dump of $_GET is up to the scrubber of the project)
		$this->assertStringNotContainsString('token=' . $secret('query'), $html);
		foreach (['password', 'scope'] as $name) {
			$this->assertFalse(strpos($html, $secret($name)) !== false, "HTML report contains $name");
		}
		foreach (glob($this->dir . '/*.log') ?: [] as $file) {
			$this->assertStringNotContainsString($secret('query'), (string) file_get_contents($file), basename($file));
		}
		$this->assertSame('yes', @file_get_contents($this->dir . '/trace-restored'));
	}

	private function startServer(): int
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$name = (string) stream_socket_get_name($socket, false);
		$port = (int) substr($name, strrpos($name, ':') + 1);
		fclose($socket);

		$this->server = proc_open(
			[PHP_BINARY, '-d', 'zend.exception_ignore_args=0', '-S', "127.0.0.1:$port", __DIR__ . '/fixtures/web.php'],
			[1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes,
			null,
			['ERROR_LOGGER_TEST_DIR' => $this->dir] + getenv()
		);

		for ($i = 0; $i < 50; $i++) {
			if ($connection = @fsockopen('127.0.0.1', $port)) {
				fclose($connection);
				return $port;
			}
			usleep(100000);
		}
		$this->fail('The built-in web server did not start.');
	}
}
