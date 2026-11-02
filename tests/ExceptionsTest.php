<?php

namespace MrMySQL\YoutubeTranscript\Tests;

use MrMySQL\YoutubeTranscript\Exception\YoutubeTranscriptExceptionInterface;
use PHPUnit\Framework\TestCase;

class ExceptionsTest extends TestCase
{
    public function testEveryLibraryExceptionImplementsTheMarkerInterface(): void
    {
        $files = glob(__DIR__ . '/../src/Exception/*Exception.php');
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $class = 'MrMySQL\\YoutubeTranscript\\Exception\\' . basename($file, '.php');
            $this->assertTrue(
                is_subclass_of($class, YoutubeTranscriptExceptionInterface::class),
                $class . ' must implement YoutubeTranscriptExceptionInterface'
            );
        }
    }
}
