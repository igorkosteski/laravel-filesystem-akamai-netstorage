<?php

declare(strict_types=1);

namespace League\Flysystem\AkamaiNetStorage;

use SimpleXMLElement;
use League\Flysystem\Config;
use GuzzleHttp\Psr7\StreamWrapper;
use League\Flysystem\PathPrefixer;
use Akamai\Open\EdgeGrid\Exception;
use League\Flysystem\FileAttributes;
use Psr\Http\Client\ClientInterface;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnableToDeleteFile;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FilesystemException;
use GuzzleHttp\Exception\RequestException;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToCheckFileExistence;
use Akamai\Open\EdgeGrid\Client as EdgeGridClient;
use League\Flysystem\UnableToDeleteDirectory;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;

class AkamaiNetStorageAdapter implements FilesystemAdapter
{
    /**
     * @var string $cpCode
     */
    private $cpCode;

    /**
     * @var EdgeGridClient $edgeGridClient
     */
    private $edgeGridClient;

    /**
     * @var PathPrefixer
     */
    private $prefixer;

    /**
     * @var PathPrefixer
     */
    private $cpCodePrefixer;

    /**
     * @var MimeTypeDetector
     */
    private $mimeTypeDetector;

    /**
     * @var string
     */
    private $baseUrl;

    /**
     *
     * @param ClientInterface $edgeGridClient
     * @param string $cpCode
     * @param string $prefix
     */
    public function __construct(ClientInterface $edgeGridClient, string $cpCode, string $pathPrefix = '', string $baseUrl = '')
    {
        $this->edgeGridClient = $edgeGridClient;

        if (ltrim($cpCode) === '') {
            throw new Exception('The cpCode is not set.');
        }

        $this->cpCode = ltrim($cpCode, '\\/');

        $this->cpCodePrefixer = new PathPrefixer(DIRECTORY_SEPARATOR . $this->cpCode);

        $prefix = DIRECTORY_SEPARATOR . $this->cpCode . DIRECTORY_SEPARATOR;
        if (trim($pathPrefix) !== "") {
            $prefix .= ltrim($pathPrefix, '\\/');
        }

        $this->prefixer = new PathPrefixer($prefix);

        $this->mimeTypeDetector = new FinfoMimeTypeDetector();

        $this->baseUrl = $baseUrl;
    }

    /**
     *
     * @param string $path
     * @return boolean
     * @throws FilesystemException
     */
    public function fileExists(string $path): bool
    {
        try {
            return $this->getMetadata($path) instanceof FileAttributes;
        } catch (UnableToCheckFileExistence $e) {
            return false;
        }
    }

    /**
     *
     * @param string $path
     * @return boolean
     * @throws FilesystemException
     */
    public function directoryExists(string $path): bool
    {
        try {
            return $this->getMetadata($path) instanceof DirectoryAttributes;
        } catch (UnableToCheckFileExistence $e) {
            return false;
        }
    }

    /**
     *
     * @param string $path
     * @param string $contents
     * @param Config $config
     * @return void
     * @throws UnableToWriteFile
     */
    public function write(string $path, string $contents, Config $config): void
    {
        if ($this->fileExists($path)) {
            throw UnableToWriteFile::atLocation($path, 'File already exists!');
        }

        try {
            $this->ensurePath($path);

            // Upload the file
            $this->edgeGridClient->put($this->preparePath($path), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue(
                        'upload',
                        (is_string($contents)) ? ['sha1' => sha1($contents)] : null
                    ),
                    'Content-Length' => is_string($contents) ? strlen($contents) : fstat($contents)['size'],
                ],
                'body' => $contents,
            ]);
        } catch (GuzzleException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage());
        }
    }

    /**
     *
     * @param string $path
     * @param resource $contents
     * @param Config $config
     * @return void
     * @throws UnableToWriteFile
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, (string) stream_get_contents($contents), $config);
    }

    /**
     *
     * @param string $path
     * @return string
     * @throws UnableToReadFile
     */
    public function read(string $path): string
    {
        try {
            $response = $this->edgeGridClient->get($this->preparePath($path), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('download'),
                ]
            ]);

            return (string) $response->getBody();
        } catch (RequestException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage());
        }
    }

    /**
     *
     * @param string $path
     * @return resource
     * @throws UnableToReadFile
     */
    public function readStream(string $path)
    {
        try {
            $response = $this->edgeGridClient->get($this->preparePath($path), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('download'),
                ]
            ]);

            $stream = StreamWrapper::getResource($response->getBody());

            fseek($stream, 0);

            return $stream;
        } catch (RequestException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage());
        }
    }

    /**
     *
     * @param string $path
     * @throws UnableToDeleteFile
     */
    public function delete(string $path): void
    {
        try {
            $action = 'delete';

            $meta = $this->getMetadata($path);

            if ($meta->type() === StorageAttributes::TYPE_DIRECTORY) {
                throw UnableToDeleteFile::atLocation($path, 'The path is directory!');
            }

            $this->edgeGridClient->post($this->preparePath($path), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue($action),
                ]
            ]);
        } catch (UnableToCheckFileExistence $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        } catch (Exception $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     *
     * @param string $prefix
     * @return void
     * @throws UnableToDeleteDirectory
     */
    public function deleteDirectory(string $path): void
    {
        if (!$this->directoryExists($path)) {
            return;
        }

        try {
            $meta = $this->getMetadata($path);

            if ($meta->type() !== StorageAttributes::TYPE_DIRECTORY) {
                throw UnableToDeleteDirectory::atLocation($path, 'The path is file.');
            }

            $items = $this->listContents($path, true);

            $directories = [];
            foreach ($items as $item) {

                $itemPath = $this->prefixer->stripPrefix($this->cpCodePrefixer->prefixDirectoryPath($item->path()));

                if ($item instanceof FileAttributes) {
                    $this->delete(rtrim($itemPath, '\\/'));
                } else {
                    $directories[] = $itemPath;
                }
            }

            $directories[] = $path;

            foreach ($directories as $directory) {
                $this->deleteDirectoryApi($directory);
            }
        } catch (ClientException $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     *
     * @param string $path
     * @param boolean $deep
     * @return iterable<StorageAttributes>
     * @throws FilesystemException
     */
    public function listContents(string $path, bool $deep): iterable
    {
        try {
            $response = $this->edgeGridClient->get($this->preparePath($path), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('dir')
                ]
            ]);

            $xml = simplexml_load_string((string) $response->getBody());

            $baseDir = (string) $xml['directory'];

            $dirs = [];

            foreach ($xml->file as $file) {
                if ((string) $file['type'] === StorageAttributes::TYPE_DIRECTORY) {
                    $directoryData = $this->handleDirectoryData($baseDir, $file);

                    $dirs[] = $directoryData;

                    if (!$deep) {
                        continue;
                    }

                    if ($directoryData->extraMetadata()['files'] ?? 0 > 0) {
                        $subDirectory = $this->prefixer->stripPrefix($this->cpCodePrefixer->prefixDirectoryPath($directoryData->path()));

                        foreach ($this->listContents($subDirectory, $deep) as $child) {
                            $dirs[] = $child;
                        }
                    }
                } else {
                    $dirs[] = $this->handleFileMetaData($baseDir, $file);
                }
            }
        } catch (RequestException $e) {
            if ($e->getCode() !== 412) {
                throw UnableToCheckFileExistence::forLocation($path);
            }
            throw UnableToReadFile::fromLocation($path);
        }
        return $dirs;
    }

    /**
     *
     * @param string $path
     * @param Config $config
     * @return void
     * @throws UnableToCreateDirectory
     */
    public function createDirectory(string $path, Config $config): void
    {
        try {
            if (rtrim($path) === '') {
                throw UnableToCreateDirectory::atLocation($path);
            }

            $this->ensurePath($path);

            $this->edgeGridClient->put($this->preparePath($path), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('mkdir'),
                ]
            ]);
        } catch (ClientException $e) {
            if ($e->getCode() == 409) {
                throw UnableToCreateDirectory::atLocation($path, 'Directory already exists!');
            }

            throw UnableToCreateDirectory::atLocation($path, $e->getMessage());
        }
    }

    /**
     *
     * @param string $path
     * @param string $visibility
     * @return void
     * @throws InvalidVisibilityProvided
     */
    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, get_class($this) . ' does not support visibility.');
    }

    /**
     * @param string $path
     * @return FileAttributes
     * @throws UnableToRetrieveMetadata
     */
    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, get_class($this) . ' does not support visibility.');
    }

    /**
     *
     * @param string $path
     * @return FileAttributes
     * @throws UnableToRetrieveMetadata
     */
    public function mimeType(string $path): FileAttributes
    {
        $preparedPath = $this->preparePath($path);

        try {
            if (rtrim($path) === '') {
                throw UnableToCreateDirectory::atLocation($path);
            }

            $response = $this->edgeGridClient->head($this->preparePath($path), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('download')
                ]
            ]);

            return new FileAttributes($preparedPath, null, null, null, $response->getHeader('Content-Type')[0]);
        } catch (ClientException $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage());
        }
    }

    /**
     *
     * @param string $path
     * @return FileAttributes
     * @throws UnableToRetrieveMetadata
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    /**
     *
     * @param string $path
     * @return FileAttributes
     * @throws UnableToRetrieveMetadata
     */
    public function fileSize(string $path): FileAttributes
    {
        try {
            return $this->getMetadata($path);
        } catch (RequestException $e) {
            throw UnableToRetrieveMetadata::filesize($path, $e->getMessage());
        }
    }

    /**
     *
     * @param string $source
     * @param string $destination
     * @param Config $config
     * @return void
     * @throws UnableToMoveFile
     */
    public function move(string $source, string $destination, Config $config): void
    {
        if (!$this->fileExists($source) || $this->fileExists($destination)) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }

        try {
            $this->copy($source, $destination, $config);

            $this->delete($source, $config);
        } catch (Exception $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     *
     * @param string $source
     * @param string $destination
     * @param Config $config
     * @return void
     * @throws UnableToCopyFile
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        if (!$this->fileExists($source) || $this->fileExists($destination)) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }

        try {
            $stream = $this->readStream($source);

            if ($stream === false || !is_resource($stream)) {
                throw UnableToCopyFile::fromLocationTo($source, $destination);
            }

            $this->writeStream($destination, $stream, new Config());
        } catch (Exception $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * Get url
     *
     * @param string $path
     * @return string
     */
    public function getUrl(string $path): string
    {
        if (trim($this->baseUrl) === '') {
            throw new Exception('baseUrl is not set!');
        }

        return $this->baseUrl . '/' . $path;
    }

    /**
     *
     * @param string $path
     * @return string
     */
    private function preparePath(string $path): string
    {
        return $this->prefixer->prefixPath($path);
    }

    /**
     *
     * @param string $path
     * @return void
     */
    private function ensurePath(string $path): void
    {
        $path = $this->preparePath($path);

        /* Check full path exists */
        $segments = [];
        $checkPath = $path;
        while ($checkPath = dirname($checkPath)) {
            if ($checkPath . '/' === $this->prefixer->prefixPath('') || empty(ltrim($checkPath, '\\/.')) || $this->fileExists($checkPath)) {
                break;
            }

            $segments[] = $checkPath;
        }

        // Create paths that do not exist yet
        if (count($segments)) {
            foreach (array_reverse($segments) as $segment) {
                $segment = substr($segment, strlen($this->prefixer->prefixPath('')));
                try {
                    $this->createDirectory($segment, new Config());
                } catch (UnableToCreateDirectory $e) {
                    continue;
                }
            }
        }
    }

    /**
     *
     * @param string $path
     * @return StorageAttributes
     * @throws UnableToCheckFileExistence
     */
    private function getMetadata(string $path): StorageAttributes
    {
        try {
            $response = $this->edgeGridClient->get($this->preparePath($path), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('stat')
                ]
            ]);
        } catch (RequestException $e) {
            throw UnableToCheckFileExistence::forLocation($path, $e);
        }

        $xml = simplexml_load_string((string) $response->getBody());

        return $this->handleFileMetaData((string) $xml['directory'], (sizeof($xml->file) > 0) ? $xml->file : null);
    }

    /**
     *
     * @param $action
     * @param array|null $options
     * @return string
     */
    private function getAcsActionHeaderValue($action, array $options = null)
    {
        $header = 'version=1&action=' . rawurlencode($action);
        $header .= ($options !== null ? '&' . http_build_query($options) : '');
        if (in_array($action, ['dir', 'download', 'du', 'stat'])) {
            $header .= '&format=xml';
        }

        return $header;
    }

    /**
     *
     * @param string $baseDir
     * @param SimpleXMLElement $file
     * @return StorageAttributes
     */
    private function handleFileMetaData(string $baseDir, ?SimpleXMLElement $file = null): StorageAttributes
    {
        if ((string) $file['type'] === StorageAttributes::TYPE_DIRECTORY) {
            return $this->handleDirectoryData($baseDir, $file);
        }

        $meta = [
            'type' => (string) $file['type'],
            'path' => (string) $baseDir . '/' . (string) $file['name'],
            'visibility' => 'public',
            'timestamp' => (int) $file['mtime'],
        ];

        $attributes = $file->attributes();
        if ($attributes != null) {
            foreach ($attributes as $attr => $value) {
                $attr = (string) $attr;
                if (!isset($meta[$attr])) {
                    $meta[(string) $attr] = (string) $value;
                }
            }
        }

        if (isset($meta['path'])) {
            $meta['path'] = $this->cpCodePrefixer->stripPrefix($meta['path']);
        }

        if (!isset($meta['mimetype']) && $meta['type'] !== 'dir') {
            $meta['mimetype'] = $this->mimeTypeDetector->detectMimeTypeFromPath((string) $meta['path']);
        }

        $meta['size'] = $meta['size'] ?? 0;

        return new FileAttributes(
            $meta['path'],
            (int) $meta['size'],
            $meta['visibility'],
            $meta['timestamp'] ?? null,
            $meta['mimetype'] ?? null,
        );
    }

    /**
     *
     * @param string $baseDir
     * @param SimpleXMLElement $file
     * @return StorageAttributes
     */
    private function handleDirectoryData(string $baseDir, ?SimpleXMLElement $directory = null): StorageAttributes
    {
        $meta = [
            'path' => (string) $baseDir . '/' . (string) $directory['name'],
            'visibility' => 'public',
            'timestamp' => (int) $directory['mtime'],
            'extraMetadata' => [
                'files' => 0
            ]
        ];

        $meta['path'] = $this->cpCodePrefixer->stripPrefix($meta['path']);

        if (isset($directory['files'])) {
            $meta['extraMetadata']['files'] = (int) $directory['files'];
        }

        return new DirectoryAttributes(
            $meta['path'],
            $meta['visibility'],
            $meta['timestamp'] ?? null,
            $meta['extraMetadata'] ?? []
        );
    }

    /**
     *
     * @param string $path
     * @throws UnableToDeleteDirectory
     */
    private function deleteDirectoryApi(string $path): void
    {
        try {
            $action = 'rmdir';

            $meta = $this->getMetadata($path);

            if ($meta->type() !== StorageAttributes::TYPE_DIRECTORY) {
                throw UnableToDeleteFile::atLocation($path, 'The path is file!');
            }

            $this->edgeGridClient->post($this->preparePath($path), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue($action),
                ]
            ]);
        } catch (UnableToCheckFileExistence $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        } catch (ClientException $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }
}
