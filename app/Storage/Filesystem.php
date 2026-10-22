<?php

namespace App\Storage;

use League\Flysystem\Filesystem as ModernFilesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;

/** Preserve the 3.8 storage API and existing object keys on maintained Flysystem. */
class Filesystem
{
    private ModernFilesystem $filesystem;

    public function __construct(private FilesystemAdapter $adapter)
    {
        $this->filesystem = new ModernFilesystem($adapter);
    }

    public function getAdapter(): FilesystemAdapter
    {
        return $this->adapter;
    }
    public function has(string $path): bool
    {
        return $this->filesystem->fileExists($path);
    }
    public function readStream(string $path)
    {
        try {
            return $this->filesystem->readStream($path);
        } catch (UnableToReadFile $e) {
            throw new FileNotFoundException($path, 0, $e);
        }
    }
    public function read(string $path): string
    {
        try {
            return $this->filesystem->read($path);
        } catch (UnableToReadFile $e) {
            throw new FileNotFoundException($path, 0, $e);
        }
    }
    public function writeStream(string $path, $stream): bool
    {
        if ($this->filesystem->fileExists($path)) {
            throw new \RuntimeException('The storage object already exists.');
        }
        $this->filesystem->writeStream($path, $stream);

        return true;
    }
    public function delete(string $path): bool
    {
        $this->filesystem->delete($path);

        return true;
    }
    public function getSize(string $path): int
    {
        try {
            return $this->filesystem->fileSize($path);
        } catch (UnableToRetrieveMetadata $e) {
            throw new FileNotFoundException($path, 0, $e);
        }
    }
    public function getMimetype(string $path): string
    {
        try {
            return $this->filesystem->mimeType($path);
        } catch (UnableToRetrieveMetadata $e) {
            throw new FileNotFoundException($path, 0, $e);
        }
    }
    public function listWith(array $fields, string $path, bool $recursive = false): array
    {
        $items = [];
        foreach ($this->filesystem->listContents($path, $recursive) as $item) {
            $items[] = [
                'type' => $item->isFile() ? 'file' : 'dir',
                'path' => $item->path(),
                'size' => $item->isFile() ? $this->getSize($item->path()) : 0,
                'mimetype' => $item->isFile() ? $this->getMimetype($item->path()) : null,
                'extension' => pathinfo($item->path(), PATHINFO_EXTENSION),
            ];
        }

        return $items;
    }
}
