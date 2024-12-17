<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use FoxORM\GibberishDetector\Gibberish;

final class GibberishTest extends TestCase
{
	protected string $libPath;

	protected function setUp(): void
	{
		$this->libPath = __DIR__ . '/fixtures/gibberish_matrix.txt';
	}

	public function testDetectsGibberish(): void
	{
		$result = Gibberish::test('aıe qwo ıak kqw', $this->libPath);
		$this->assertTrue($result, 'Failed to detect gibberish text.');
	}

	public function testDetectsNonGibberish(): void
	{
		$result = Gibberish::test('This is a normal sentence.', $this->libPath);
		$this->assertFalse($result, 'Incorrectly flagged valid text as gibberish.');
	}

	public function testTrainCreatesLibrary(): void
	{
		$this->assertFileExists($this->libPath, 'Training failed to create library file.');
		$data = unserialize(file_get_contents($this->libPath));
		$this->assertIsArray($data, 'Library data is not an array.');
		$this->assertArrayHasKey('matrix', $data);
		$this->assertArrayHasKey('threshold', $data);
	}

	public function testThrowsErrorForInvalidLibrary(): void
	{
		$this->expectException(InvalidArgumentException::class);
		Gibberish::test('test text', 'invalid/path/to/library.ser');
	}
}
