<?php

namespace League\Flysystem\AkamaiNetStorage\Tests;

use League\Flysystem\AkamaiNetStorage\Exception\CustomMessageException;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use ReflectionClass;
use ReflectionMethod;

class AkamaiNetStorageAdapterTest extends \PHPUnit\Framework\TestCase
{
    protected $key = "key";

    protected $keyName = 'keyName';

    protected $host = 'testing.akamaihd.net.example.org';

    protected $cpCode = '123456';

    protected $prefix = 'test';

    protected $workingDir = 'working-dir';

    protected $baseUrl = 'company.akamaihd.net.example.org';

    protected FilesystemAdapter $adapter;

    protected array $config;

    /**
     * @var \League\Flysystem\Filesystem
     */
    private $fs;

    public function setUp(): void
    {
        if (!file_exists(__DIR__ . '/fixtures/')) {
            mkdir(__DIR__ . '/fixtures/', 0777, true);
        }

        $handler = new \League\Flysystem\AkamaiNetStorage\Handler\Authentication();
        $handler->setSigner((new \League\Flysystem\AkamaiNetStorage\Authentication())->setKey($this->key, $this->keyName));

        $stack = VcrHandler::turnOn(
            __DIR__ . '/fixtures/' . lcfirst(substr($this->getName(), 4)) . '.json'
        );

        $stack->push($handler, 'netstorage-handler');

        $client = new \Akamai\Open\EdgeGrid\Client([
            'base_uri' => $this->host,
            'handler' => $stack
        ]);

        $this->adapter = new \League\Flysystem\AkamaiNetStorage\AkamaiNetStorageAdapter(
            $client,
            $this->cpCode,
            $this->prefix,
            $this->baseUrl
        );

        $this->fs = new \League\Flysystem\Filesystem($this->adapter);

        $this->config = [];

        try {
            if (!$this->fs->fileExists($this->workingDir)) {
                $this->fs->createDirectory($this->workingDir);
            }
        } catch (\Exception $e) {
        }
    }

    public function tearDown(): void
    {
        try {
            $this->fs->deleteDirectory($this->workingDir);
        } catch (\Exception $e) {
        }
    }

    private function getName(): string {
        $id = $this->sortId();

        if (str_contains($id, '::')) {
            return substr($id, strpos($id, '::') + 2);
        }

        return $id;
    }

    public function testFileExists()
    {
        $file = $this->workingDir . '/example.txt';
        $this->fs->write($file, __METHOD__);
        $this->assertTrue($this->fs->fileExists($file));
    }

    public function testFileNotExists()
    {
        $file = $this->workingDir . '/non-existent.txt';
        $this->assertFalse($this->fs->fileExists($file));
    }

    public function testDirectoryExists()
    {
        $this->assertTrue($this->fs->directoryExists($this->workingDir));
    }

    public function testDirectoryNotExists()
    {
        $file = $this->workingDir . '/non-existent';
        $this->assertFalse($this->fs->directoryExists($file));

        $file = $this->workingDir . '/example.txt';
        $this->fs->write($file, __METHOD__);

        $file = $this->workingDir . '/example.txt';
        $this->assertFalse($this->fs->directoryExists($file));
    }

    public function testWrite()
    {
        $file = $this->workingDir . '/example.txt';
        $this->fs->write($file, __METHOD__);
        $this->assertSame(__METHOD__, $this->fs->read($file));
    }

    public function testWriteExistingFile()
    {
        $file = $this->workingDir . '/example.txt';
        $this->fs->write($file, __METHOD__);

        $this->expectException(CustomMessageException::class);
        $expectErrorMessage = 'Unable to write file at location: ' . $file . '. File already exists!';

        try {
            $this->fs->write($file, __METHOD__);
        } catch(UnableToWriteFile $exception) {
            if ($exception->getMessage() === $expectErrorMessage) {
                throw new CustomMessageException($exception->getMessage());
            }
            throw $exception;
        }
    }

    public function testWriteStream()
    {
        $fp = fopen('php://memory', 'w+');
        fputs($fp, __METHOD__);
        fseek($fp, 0);

        $file = $this->workingDir . '/example.txt';
        $this->fs->writeStream($file, $fp);

        $this->assertTrue($this->fs->fileExists($file));
        $this->assertSame(__METHOD__, $this->fs->read($file));
    }

    public function testWriteStreamExistingFile()
    {
        $this->testWriteStream();

        $fp = fopen('php://memory', 'w+');
        fputs($fp, __METHOD__);
        fseek($fp, 0);

        $file = $this->workingDir . '/example.txt';

        $this->expectException(CustomMessageException::class);
        $expectErrorMessage = 'Unable to write file at location: ' . $file . '. File already exists!';

        try {
            try {
                $this->fs->writeStream($file, $fp);
            } finally {
                $this->assertSame(
                    self::class . '::' . 'testWriteStream',
                    $this->fs->read($file)
                );
            }
        } catch(UnableToWriteFile $exception) {
            if ($exception->getMessage() === $expectErrorMessage) {
                throw new CustomMessageException($exception->getMessage());
            }
            throw $exception;
        }
    }

    public function testRead()
    {
        $file = $this->workingDir . '/example.txt';
        $this->fs->write($file, __METHOD__);
        $this->assertSame(__METHOD__, $this->fs->read($file));
    }

    public function testReadNonExistent()
    {
        $file = $this->workingDir . '/non-existent.txt';

        $this->expectException(CustomMessageException::class);
        $expectErrorMessage = 'Unable to read file from location: ' . $file . '.';

        try {
            $this->fs->read($file);
        } catch(UnableToReadFile $exception) {
            if (strpos($exception->getMessage(), $expectErrorMessage) === 0) {
                throw new CustomMessageException($exception->getMessage());
            }
            throw $exception;
        }
    }

    public function testReadStream()
    {
        $file = $this->workingDir . '/example.txt';
        $this->fs->write($file, __METHOD__);
        $this->assertSame(__METHOD__, stream_get_contents($this->fs->readStream($file)));
    }

    public function testReadStreamNonExistent()
    {
        $file = $this->workingDir . '/non-existent';

        $this->expectException(CustomMessageException::class);
        $expectErrorMessage = 'Unable to read file from location: ' . $file . '. ';

        try {
            $this->fs->readStream($file);
        } catch(UnableToReadFile $exception) {
            if (strpos($exception->getMessage(), $expectErrorMessage) === 0) {
                throw new CustomMessageException($exception->getMessage());
            }
            throw $exception;
        }
    }

    public function testDelete()
    {
        $file = $this->workingDir . '/example.txt';
        $this->fs->write($file, __METHOD__);

        $this->fs->delete($file);
        $this->assertFalse($this->fs->fileExists($file));
    }

    public function testUnableToDeleteFileDirectory()
    {
        $dir = $this->workingDir . '/test-dir';
        $this->fs->createDirectory($dir);

        $this->expectException(CustomMessageException::class);
        $expectErrorMessage = 'Unable to delete file located at: ' . $dir . '. The path is directory!';

        try {
            $this->fs->delete($dir);
        } catch(UnableToDeleteFile $exception) {
            if (strpos($exception->getMessage(), $expectErrorMessage) === 0) {
                throw new CustomMessageException($exception->getMessage());
            }
            throw $exception;
        }
    }

    public function testUnableToDeleteNonExistentFile()
    {
        $file = $this->workingDir . '/non-existent.txt';

        $this->expectException(CustomMessageException::class);
        $expectErrorMessage = 'Unable to delete file located at: ' . $file . '.';

        try {
            $this->fs->delete($file);
        } catch(UnableToDeleteFile $exception) {
            if (strpos($exception->getMessage(), $expectErrorMessage) === 0) {
                throw new CustomMessageException($exception->getMessage());
            }
            throw $exception;
        }
    }

    public function testListContents()
    {
        $file1 = $this->workingDir . '/example1.txt';
        $this->fs->write($file1, __METHOD__);

        $file2 = $this->workingDir . '/example2.txt';
        $this->fs->write($file2, __METHOD__);

        $contents = $this->fs->listContents($this->workingDir . '/', false)->toArray();
        $this->assertContainsOnlyInstancesOf(FileAttributes::class, $contents);
        $this->assertCount(2, $contents);
        $this->assertEquals('text/plain', $contents[0]->mimeType());
        $this->assertEquals('text/plain', $contents[1]->mimeType());

        $subDir = $this->workingDir . '/test2';
        $this->fs->createDirectory($subDir);

        $file3 = $subDir . '/example3.html';
        $this->fs->write($file3, '<h1>HI</h1>');

        $contents2 = $this->fs->listContents($this->workingDir . '/', true)->toArray();
        $this->assertCount(4, $contents2);
        $this->assertInstanceOf(DirectoryAttributes::class, $contents2[2]);
        $this->assertEquals(2, $contents2[2]->extraMetadata()['files']);

        $this->assertInstanceOf(FileAttributes::class, $contents2[3]);
        $this->assertEquals('text/html', $contents2[3]->mimeType());
    }

    public function testDeleteDirectory()
    {
        $directory = $this->workingDir . '/test';
        $this->fs->createDirectory($directory);

        $file1 = $directory . '/example1.txt';
        $this->fs->write($file1, __METHOD__);

        $file2 = $directory . '/example2.txt';
        $this->fs->write($file2, __METHOD__);

        $subDir1 = $directory . '/test1';
        $this->fs->createDirectory($subDir1);

        $file3 = $subDir1 . '/example3.html';
        $this->fs->write($file3, '<h1>HI</h1>');

        $subDir2 = $directory . '/test2';
        $this->fs->createDirectory($subDir2);

        $file3 = $subDir2 . '/example4.html';
        $this->fs->write($file3, '<h1>HI 5</h1>');

        $this->fs->deleteDirectory($directory);

        $this->assertFalse($this->fs->directoryExists($directory));
    }

    public function testCreateDirectory()
    {
        $directory = $this->workingDir . '/test3';
        $this->fs->createDirectory($directory);
        $this->assertTrue($this->fs->directoryExists($directory));
    }

    public function testMimeType()
    {
        $file = $this->workingDir . '/example.txt';
        $this->fs->write($file, __METHOD__);
        $this->assertSame('text/plain', $this->fs->mimeType($file));
    }

    public function testLastModified()
    {
        $file = $this->workingDir . '/example.txt';
        $this->fs->write($file, __METHOD__);
        $this->assertLessThanOrEqual(time(), $this->fs->lastModified($file));
    }

    public function testFileSize()
    {
        $file = $this->workingDir . '/example.txt';
        $this->fs->write($file, __METHOD__);
        $this->assertSame(81, $this->fs->fileSize($file));
    }

    public function testCopy()
    {
        $file = $this->workingDir . '/example.txt';
        $this->fs->write($file, __METHOD__);

        $file2 = $this->workingDir . '/example2.txt';
        $this->fs->copy($file, $file2);

        $this->assertTrue($this->fs->fileExists($file));
        $this->assertTrue($this->fs->fileExists($file2));
    }

    public function testMove()
    {
        $file = $this->workingDir . '/example.txt';
        $this->fs->write($file, __METHOD__);

        $file2 = $this->workingDir . '/example2.txt';
        $this->fs->move($file, $file2);

        $this->assertFalse($this->fs->fileExists($file));
        $this->assertTrue($this->fs->fileExists($file2));
    }

    public function testGetUrl()
    {
        $file = $this->workingDir . '/example.txt';
        $this->fs->write($file, __METHOD__);

        $this->assertEquals($this->baseUrl . '/' . $file, $this->adapter->getUrl($file));
    }
}
