<?php

namespace Kapersoft\FlysystemSharefile\Tests;

use League\Flysystem\Util;
use League\Flysystem\Config;
use Kapersoft\Sharefile\Client;

/**
 * Online Flysystem ShareFile Adapter tests.
 *
 * @author   Jan Willem Kaper <kapersoft@gmail.com>
 * @license  MIT (see License.txt)
 *
 * @link     http://github.com/kapersoft/flysystem-sharefile
 */
class SharefileAdapterFunctionalTest extends TestCase
{
    /**
     * Test for itCanGetAClient.
     *
     * @test
     */
    public function itCanGetAClient()
    {
        $this->assertInstanceOf(Client::class, $this->adapter->getClient());
    }

    /**
     * Test for itCanFindFiles.
     *
     * @test
     *
     * @param string $name Filename used for testing
     *
     * @dataProvider  filesProvider
     */
    public function itCanFindFiles(string $name)
    {
        $contents = $this->faker()->text;
        $this->createResourceFile($name, $contents);

        $this->assertTrue((bool) $this->hasResource($name));
    }

    /**
     * Test for itFindFilesInSubfolders.
     *
     * @test
     *
     * @param string $path Path used for testing
     *
     * @dataProvider  withSubFolderProvider
     */
    public function itFindFilesInSubfolders(string $path)
    {
        $contents = $this->faker()->text;
        $this->createResourceFile($path, $contents);

        $this->assertTrue((bool) $this->hasResource($path));
    }

    /**
     * Test for itCanRead.
     *
     * @test
     *
     * @param string $name Filename used for testing
     *
     * @dataProvider filesProvider
     */
    public function itCanRead(string $name)
    {
        $contents = $this->faker()->text;
        $this->createResourceFile($name, $contents);

        $response = $this->adapter->read($name);

        $this->assertArraySubset([
            'type' => 'file',
            'path' => $name,
            'contents' => $contents,
        ], $response);
    }

    /**
     * Test for itCanReadstream.
     *
     * @test
     *
     * @param string $name Filename used for testing
     *
     * @dataProvider filesProvider
     */
    public function itCanReadstream(string $name)
    {
        $contents = $this->faker()->text;
        $this->createResourceFile($name, $contents);

        $response = $this->adapter->readstream($name);

        $this->assertArraySubset([
            'type' => 'file',
            'path' => $name,
        ], $response);

        $this->assertInternalType('resource', $response['stream']);
    }

    /**
     * Test for itCanListContents.
     *
     * @test
     *
     * @param string $path Path used for testing
     *
     * @dataProvider withSubFolderProvider
     */
    public function itCanListContents(string $path)
    {
        // Single file
        $contents = $this->faker()->text;
        $this->createResourceFile($path, $contents);

        $this->assertCount(1, $this->adapter->listContents(UTIL::dirname($path)));

        // Multiple files
        $this->createResourceFile(str_replace('/', '/first copy of ', $path), $contents);
        $this->createResourceFile(str_replace('/', '/second copy of ', $path), $contents);

        $this->assertCount(3, $this->adapter->listContents(UTIL::dirname($path)));
    }

    /**
     * Test for itCanGetMetadata.
     *
     * @test
     *
     * @param string $path Path used for testing
     *
     * @dataProvider withSubFolderProvider
     */
    public function itCanGetMetadata(string $path)
    {
        $contents = $this->faker()->text;
        $this->createResourceFile($path, $contents);

        $this->assertArraySubset(['type' => 'file', 'path' =>  $path], $this->adapter->getMetadata($path));
    }

    /**
     * Test for itCanGetSize.
     *
     * @test
     */
    public function itCanGetSize()
    {
        $contents = $this->faker()->text;
        $this->createResourceFile('foo', $contents);

        $this->assertSame(strlen($contents), $this->adapter->getSize('foo')['size']);
    }

    /**
     * Test for itCanGetMimetypes.
     *
     * @test
     */
    public function itCanGetMimetypes()
    {
        $this->createResourceFile('foo.json', 'bar');

        $this->assertSame('application/json', $this->adapter->getMimetype('foo.json')['mimetype']);
    }

    /**
     * Test for itCanGetTimestamps.
     *
     * @test
     */
    public function itCanGetTimestamps()
    {
        $this->createResourceFile('foo', 'bar');

        $this->assertLessThan(time() + 1, $this->adapter->getTimestamp('foo')['timestamp']);
        $this->assertGreaterThan(time() - 60, $this->adapter->getTimestamp('foo')['timestamp']);
    }

    /**
     * Test for itCanWrite.
     *
     * @test
     *
     * @param string $filename File used for testing
     *
     * @dataProvider filesProvider
     */
    public function itCanWrite(string $filename)
    {
        $contents = $this->faker()->text;

        $result = $this->adapter->write($filename, $contents, new Config);

        $this->assertArraySubset([
            'type' => 'file',
            'path' => $filename,
            'contents' => $contents,
            'mimetype' => Util::guessMimeType($filename, $contents),
        ], $result);

        $this->assertEquals($contents, $this->getResourceContent($filename));
    }

    /**
     * Test for itCanUpdate.
     *
     * @test
     *
     * @param string $filename File used for testing
     *
     * @dataProvider filesProvider
     */
    public function itCanUpdate(string $filename)
    {
        $contents = $this->faker()->text;

        $this->createResourceFile($filename, $contents);
        $this->assertEquals($contents, $this->getResourceContent($filename));

        $newContents = $this->faker()->text;
        $result = $this->adapter->update($filename, $newContents, new Config);

        $this->assertArraySubset([
            'type' => 'file',
            'path' => $filename,
            'contents' => $newContents,
            'mimetype' => Util::guessMimeType($filename, $contents),
        ], $result);

        $this->assertNotEquals($contents, $this->getResourceContent($filename));
        $this->assertEquals($newContents, $this->getResourceContent($filename));
    }

    /**
     * Test for itCanWritestreamAndUpdatestream.
     *
     * @test
     *
     * @param string $filename File used for testing
     *
     * @dataProvider filesProvider
     */
    public function itCanWritestreamAndUpdatestream(string $filename)
    {
        $contents = $this->faker()->text;

        $stream = fopen('php://memory', 'rb+');
        fwrite($stream, $contents);
        rewind($stream);

        $this->adapter->writeStream($filename, $stream, new Config);
        $this->assertEquals($contents, $this->getResourceContent($filename));

        $newContents = $this->faker()->text;

        $stream = fopen('php://memory', 'rb+');
        fwrite($stream, $newContents);
        rewind($stream);

        $this->adapter->updateStream($filename, $stream, new Config);

        $this->assertNotEquals($contents, $this->getResourceContent($filename));
        $this->assertEquals($newContents, $this->getResourceContent($filename));
    }

    /**
     * Test for itCanRenameFiles.
     *
     * @test
     *
     * @param string $filename File used for testing
     *
     * @dataProvider filesProvider
     */
    public function itCanRenameFiles(string $filename)
    {
        $this->createResourceFile($filename, 'foo');
        $newFilename = $this->randomFileName();

        $result = $this->adapter->rename($filename, $newFilename);

        $this->assertTrue($result);
        // $this->assertFalse($this->hasResource($filename)); We'll leave this one out for now (see https://community.sharefilesupport.com/citrixsharefile/topics/uploading-files-with-webdav-and-renaming-files-with-api-results-in-empty-files)
        $this->assertTrue($this->hasResource($newFilename));
    }

    /**
     * Test for itCanCopyFiles.
     *
     * @test
     *
     * @param string $path    Source file
     * @param string $newpath Target file
     *
     * @dataProvider copyFilesProvider
     */
    public function itCanCopyFiles(string $path, string $newpath)
    {
        $this->createResourceFile($path, 'foo');
        $this->createResourceDir(UTIL::dirname($newpath));

        $result = $this->adapter->copy($path, $newpath);

        $this->assertTrue($result);
        $this->assertNotFalse($this->hasResource($path));
        $this->assertNotFalse($this->hasResource($newpath));
        $this->assertEquals($this->getResourceContent($path), $this->getResourceContent($newpath));
    }

    /**
     * Test for itCanDeleteFiles.
     *
     * @test
     *
     * @param string $filename File used for testing
     *
     * @dataProvider filesProvider
     */
    public function itCanDeleteFiles(string $filename)
    {
        $this->createResourceFile($filename, 'foo');

        $result = $this->adapter->delete($filename);

        $this->assertTrue($result);
        $this->assertFalse($this->hasResource($filename));
    }

    /**
     * Test for itCanCreateAndDeleteDirectories.
     *
     * @test
     *
     * @param string $filename File used for testing
     *
     * @dataProvider filesProvider
     */
    public function itCanCreateAndDeleteDirectories(string $filename)
    {
        $path = substr($filename, 0, -4);

        $result = $this->adapter->createDir($path, new Config);
        $this->assertTrue($this->hasResource($path));
        $this->assertArraySubset(['type' => 'dir', 'path' => $path], $result);

        $result = $this->adapter->deleteDir($path);
        $this->assertTrue($result);
        $this->assertFalse($this->hasResource($path));
    }

    /**
     * Test for itCanPut.
     *
     * @test
     *
     * @param string $filename File used for testing
     *
     * @dataProvider filesProvider
     */
    public function itCanPut(string $filename)
    {
        $contents = $this->faker()->text;
        $this->createResourceFile($filename, $contents);

        $newContents = $this->faker()->text;

        $result = $this->adapter->put($filename, $newContents, new Config);

        $this->assertArraySubset([
            'type' => 'file',
            'path' => $filename,
            'contents' => $newContents,
            'mimetype' => Util::guessMimeType($filename, $contents),
        ], $result);

        $this->assertEquals($newContents, $this->getResourceContent($filename));
    }

    /**
     * Test for itCanReadAndDeleteFiles.
     *
     * @test
     *
     * @param string $filename File used for testing
     *
     * @dataProvider filesProvider
     */
    public function itCanReadAndDeleteFiles(string $filename)
    {
        $contents = $this->faker()->text;
        $this->createResourceFile($filename, $contents);

        $response = $this->adapter->readAndDelete($filename);

        $this->assertSame($contents, $response);

        $this->assertFalse($this->hasResource($filename));
    }

    /**
     * Test for itCanFail.
     *
     * @test
     */
    public function itCanFail()
    {
        $this->assertFalse($this->adapter->has('/Foo'));
        $this->assertFalse($this->adapter->read('/Foo'));
        $this->assertFalse($this->adapter->listContents('/Foo'));
        $this->assertFalse($this->adapter->getMetadata('/Foo'));
        $this->assertFalse($this->adapter->getSize('/Foo'));
        $this->assertFalse($this->adapter->getMimetype('/Foo'));
        $this->assertFalse($this->adapter->getTimestamp('/Foo'));
        $this->assertFalse($this->adapter->rename('/Foo', '/Bar'));
        $this->assertFalse($this->adapter->copy('/Foo', '/Bar'));
        $this->assertFalse($this->adapter->delete('/Foo'));
        $this->assertFalse($this->adapter->createDir('/Foo/Bar'));
        $this->assertFalse($this->adapter->delete('/Foo'));
        $this->assertFalse($this->adapter->readAndDelete('/Foo'));
    }
}
