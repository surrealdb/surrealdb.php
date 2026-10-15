<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use SurrealDB\AgentMemory\DocumentFile;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\Exceptions\SurrealExceptionInterface;

/** Builds the bytes, filename and media type of an upload from a string, a path, a stream, or a PSR-7 upload. */
final class DocumentFileTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/agent-memory-document-file-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $name) {
            unlink($this->directory . '/' . $name);
        }

        rmdir($this->directory);
    }

    public function testFromStringDefaultsTheFilenameAndInfersTheType(): void
    {
        $file = DocumentFile::fromString('BYTES');
        $this->assertSame('BYTES', $file->contents);
        $this->assertSame(DocumentFile::DEFAULT_FILENAME, $file->filename);
        $this->assertNull($file->contentType);

        $this->assertSame('application/pdf', DocumentFile::fromString('x', 'scan.PDF')->contentType);
        $this->assertSame(DocumentFile::DEFAULT_FILENAME, DocumentFile::fromString('x', ' ')->filename);
        $this->assertSame('text/markdown', DocumentFile::fromString('x', 'notes.md', ' ')->contentType, 'a blank type counts as none');
    }

    public function testFromPathReadsTheFileAndNamesItAfterTheBasename(): void
    {
        file_put_contents($this->directory . '/notes.md', "# Notes\n");

        $file = DocumentFile::fromPath($this->directory . '/notes.md');

        $this->assertSame("# Notes\n", $file->contents);
        $this->assertSame('notes.md', $file->filename);
        $this->assertSame('text/markdown', $file->contentType);
        $this->assertSame('runbook.txt', DocumentFile::fromPath($this->directory . '/notes.md', 'runbook.txt')->filename);
    }

    public function testFromPathRejectsAMissingFileOrADirectory(): void
    {
        foreach ([$this->directory . '/missing.md', $this->directory] as $path) {
            try {
                DocumentFile::fromPath($path);
                $this->fail("Expected {$path} to be rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertInstanceOf(SurrealExceptionInterface::class, $e);
                $this->assertSame("Cannot read the document file \"{$path}\".", $e->getMessage());
            }
        }
    }

    public function testFromStreamRewindsAndReadsTheWholeStream(): void
    {
        $stream = Utils::streamFor('%PDF-1.7 bytes');
        $stream->read(2);

        $file = DocumentFile::fromStream($stream, 'scan.pdf');

        $this->assertSame('%PDF-1.7 bytes', $file->contents);
        $this->assertSame('scan.pdf', $file->filename);
        $this->assertSame('application/pdf', $file->contentType);
    }

    public function testFromStreamRejectsADetachedStream(): void
    {
        $stream = Utils::streamFor('bytes');
        $stream->detach();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot read the document stream: it is not readable.');

        DocumentFile::fromStream($stream);
    }

    public function testFromStreamWrapsAReadFailure(): void
    {
        $failure = new \RuntimeException('connection reset');
        $stream = new PumpStream(static fn (): never => throw $failure);

        try {
            DocumentFile::fromStream($stream);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Cannot read the document stream: connection reset', $e->getMessage());
            $this->assertSame($failure, $e->getPrevious());
        }
    }

    public function testFromUploadedFileUsesTheClientNameAndType(): void
    {
        $upload = new UploadedFile(Utils::streamFor('{"a":1}'), 7, UPLOAD_ERR_OK, 'data.txt', 'application/json');

        $file = DocumentFile::fromUploadedFile($upload);

        $this->assertSame('{"a":1}', $file->contents);
        $this->assertSame('data.txt', $file->filename);
        $this->assertSame('application/json', $file->contentType, 'the client type wins over inference');
    }

    public function testFromUploadedFileIgnoresAnOctetStreamClientType(): void
    {
        $markdown = new UploadedFile(Utils::streamFor('# Notes'), 7, UPLOAD_ERR_OK, 'notes.md', 'application/octet-stream');
        $blob = new UploadedFile(Utils::streamFor('BYTES'), 5, UPLOAD_ERR_OK, 'blob', 'application/octet-stream');
        $unnamed = new UploadedFile(Utils::streamFor('BYTES'), 5, UPLOAD_ERR_OK);

        $this->assertSame('text/markdown', DocumentFile::fromUploadedFile($markdown)->contentType);
        $this->assertNull(DocumentFile::fromUploadedFile($blob)->contentType);
        $this->assertSame(DocumentFile::DEFAULT_FILENAME, DocumentFile::fromUploadedFile($unnamed)->filename);
        $this->assertNull(DocumentFile::fromUploadedFile($unnamed)->contentType);
    }

    public function testFromUploadedFileRejectsAFailedUpload(): void
    {
        $partial = new UploadedFile(Utils::streamFor(''), 0, UPLOAD_ERR_PARTIAL, 'notes.md');

        try {
            DocumentFile::fromUploadedFile($partial);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Cannot read the uploaded document: upload error ' . UPLOAD_ERR_PARTIAL . '.', $e->getMessage());
        }

        $moved = new UploadedFile(Utils::streamFor('# Notes'), 7, UPLOAD_ERR_OK, 'notes.md');
        $moved->moveTo($this->directory . '/moved.md');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot read the uploaded document: Cannot retrieve stream after it has already been moved');

        DocumentFile::fromUploadedFile($moved);
    }

    public function testExplicitArgumentsWinOverTheClientValues(): void
    {
        $upload = new UploadedFile(Utils::streamFor('x'), 1, UPLOAD_ERR_OK, 'notes.md', 'text/markdown');

        $renamed = DocumentFile::fromUploadedFile($upload, 'notes.txt');
        $this->assertSame('notes.txt', $renamed->filename);
        $this->assertSame('text/markdown', $renamed->contentType, 'the client type still wins over inference');

        $retyped = DocumentFile::fromUploadedFile($upload, contentType: 'text/plain');
        $this->assertSame('notes.md', $retyped->filename);
        $this->assertSame('text/plain', $retyped->contentType);
    }

    public function testContentTypeWithLineBreaksIsRejected(): void
    {
        foreach (["text/plain\r\nX-Injected: 1", "text/plain\nX-Injected: 1"] as $contentType) {
            try {
                DocumentFile::fromString('x', 'notes.txt', $contentType);
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('A document content type cannot contain line breaks.', $e->getMessage());
            }
        }
    }

    public function testEmptyContentsAreAllowed(): void
    {
        file_put_contents($this->directory . '/empty.txt', '');

        $this->assertSame('', DocumentFile::fromString('')->contents);
        $this->assertSame('', DocumentFile::fromPath($this->directory . '/empty.txt')->contents);
        $this->assertSame('', DocumentFile::fromStream(Utils::streamFor(''))->contents);
    }

    public function testDebugInfoOmitsTheBytes(): void
    {
        $file = DocumentFile::fromString('SECRET-BYTES', 'notes.md');

        $this->assertSame(['filename' => 'notes.md', 'contentType' => 'text/markdown', 'size' => 12], $file->__debugInfo());
        $this->assertStringNotContainsString('SECRET-BYTES', print_r($file, true));
    }
}
