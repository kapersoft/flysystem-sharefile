<?php

namespace Kapersoft\FlysystemSharefile\Tests;

use Faker\Factory;
use Faker\Generator;
use Prophecy\Argument;
use League\Flysystem\Util;
use League\Flysystem\Config;
use GuzzleHttp\Psr7\Response;
use Kapersoft\Sharefile\Client;
use PHPUnit\Framework\TestCase;
use Kapersoft\Sharefile\Exceptions\BadRequest;
use Kapersoft\FlysystemSharefile\SharefileAdapter;

/**
 * Offline Flysystem ShareFile Adapter tests.
 *
 * @author   Jan Willem Kaper <kapersoft@gmail.com>
 * @license  MIT (see License.txt)
 *
 * @link     http://github.com/kapersoft/flysystem-sharefile
 */
class SharefileAdapterTest extends TestCase
{
    /**
     * ShareFile client.
     *
     * @var \Kapersoft\Sharefile\Client|\Prophecy\Prophecy\ObjectProphecy
     */
    protected $client;

    /**
     * ShareFile Adapter.
     *
     * @var \Kapersoft\FlysystemSharefile\\SharefileAdapter
     */
    protected $adapter;

    /**
     * Folder prefix.
     *
     * @var  string
     */
    protected $prefix;

    /**
     * Setup tests.
     */
    public function setUp()
    {
        $this->client = $this->prophesize(Client::class);

        $this->prefix = '/prefix';

        $this->adapter = new SharefileAdapter($this->client->reveal(), $this->prefix);
    }

    /**
     * Test for it_can_get_a_client.
     *
     * @test
     */
    public function it_can_get_a_client() // @codingStandardsIgnoreLine
    {
        $this->assertInstanceOf(Client::class, $this->adapter->getClient());
    }

    /**
     * Test for it_has_calls_to_get_meta_data.
     *
     * @test
     *
     * @param string $method   Method used for testing
     * @param string $filename Filename used for testing
     *
     * @dataProvider  metadataProvider
     */
    public function it_has_calls_to_get_meta_data(string $method, string $filename) // @codingStandardsIgnoreLine
    {
        $this->client->getItemByPath($this->applyPathPrefix($filename))->willReturn($this->mockSharefileItem($filename));

        $result = $this->adapter->{$method}($filename);

        $expectedResult = $this->calculateExpectedMetadata($filename);

        $this->assertSame($expectedResult, $result);
    }

    /**
     * Test for it_will_not_hold_metadata_after_failing.
     *
     * @test
     *
     * @param string $method   Method used for testing
     * @param string $filename Filename used for testing
     *
     * @dataProvider  metadataProvider
     */
    public function it_will_not_hold_metadata_after_failing(string $method, string $filename) // @codingStandardsIgnoreLine
    {
        $this->client->getItemByPath($this->applyPathPrefix($filename))->willThrow(
            new BadRequest(new Response(404))
        );

        $result = $this->adapter->{$method}($filename);

        $this->assertFalse($result);
    }

    /**
     * Test for it_can_read_and_readstream.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function it_can_read_and_readstream(string $filename) // @codingStandardsIgnoreLine
    {
        $contents = $this->faker()->text;

        $this->client->getItemByPath($this->applyPathPrefix($filename))->willReturn(
            $this->mockSharefileItem($filename, [
                'Id' => '1',
                'Parent' => ['Id' => 1],
            ])
        );

        $this->client->getItemById(1)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Info' => ['CanDownload'   => 1],
        ]);

        $this->client->getItemContents(Argument::any())->willReturn($contents);

        $expectedResult = $this->calculateExpectedMetadata($filename, [
            'contents' => $contents,
         ]);
        $result = $this->adapter->read($filename);
        $this->assertsame($expectedResult, $result);

        $result = $this->adapter->readStream($filename);
        $this->assertInternalType('resource', $result['stream']);
        $result['stream'] = false;
        unset($expectedResult['contents']);
        $this->assertsame($expectedResult, $result);
    }

    /**
     * Test for it_can_list_contents.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function it_can_list_contents(string $filename) // @codingStandardsIgnoreLine
    {
        $client = $this->client;
        $directory = substr($filename, 0, -4);

        $client->getItemByPath($this->applyPathPrefix($directory))->willReturn(
            $this->mockSharefileItem('SubFolder', [
                'odata.type'   => 'ShareFile.Api.Models.Folder',
                'Id' => '1',
            ])
        );

        $sharefileItemFolder = $this->mockSharefileItem($directory.'/folder', [
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => '2',
        ]);
        $sharefileItemFile = $this->mockSharefileItem($directory.'/folder/file.txt', [
            'odata.type'   => 'ShareFile.Api.Models.File',
            'Id' => '3',
        ]);
        $client->getItemById(1, true)->will(function () use ($client, $sharefileItemFolder, $sharefileItemFile) {
            $client->getItemById(2, true)->willReturn([
                'FileCount' => 2,
                'Children' => [
                        $sharefileItemFile,
                ],
            ]);

            return [
                'FileCount' => 2,
                'Children' => [
                    $sharefileItemFolder,
                ],
            ];
        });

        $result = $this->adapter->listContents($directory, true);

        $expectedResult = [
            $this->calculateExpectedMetadata($directory.'/folder', [
                'mimetype' => 'inode/directory',
                'type' => 'dir',
            ]),
            $this->calculateExpectedMetadata($directory.'/folder/file.txt'),
        ];

        $this->assertsame($expectedResult, $result);
    }

    /**
     * Test for it_can_write_put_and_update.
     *
     * @test
     *
     * @param string $method   Method used for testing
     * @param string $filename Filename used for testing
     *
     * @dataProvider  updateProvider
     */
    public function it_can_write_put_and_update(string $method, string $filename) // @codingStandardsIgnoreLine
    {
        $filenamePrefix = $this->applyPathPrefix($filename);
        $dirname = Util::dirname($filenamePrefix);
        $contents = $this->faker()->text;
        $client = $this->client;

        $sharefileItem = $this->mockSharefileItem($filename);
        $client->getItemByPath($dirname)->will(function () use ($client, $filename, $filenamePrefix, $sharefileItem) {
            $client->getItemByPath($filenamePrefix)->willReturn(
                $sharefileItem
            );

            return [
                'odata.type'   => 'ShareFile.Api.Models.Folder',
                'Id' => 1,
                'Info' => ['CanUpload'   => 1],
            ];
        });

        $client->uploadFileStandard(Argument::any(), 1, Argument::any(), Argument::any())->willReturn('');

        $result = $this->adapter->{$method}($filename, $contents);

        $expectedResult = $this->calculateExpectedMetadata($filename, [
            'contents' => $contents,
        ]);

        $this->assertSame($expectedResult, $result);
    }

    /**
     * Test for it_can_writestream_updatestream.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function it_can_writestream_updatestream(string $filename) // @codingStandardsIgnoreLine
    {
        $filenamePrefix = $this->applyPathPrefix($filename);
        $dirname = Util::dirname($filenamePrefix);
        $contents = $this->faker()->text;
        $client = $this->client;

        $sharefileItem = $this->mockSharefileItem($filename);
        $client->getItemByPath($dirname)->will(function () use ($client, $filename, $filenamePrefix, $sharefileItem) {
            $client->getItemByPath($filenamePrefix)->willReturn(
                $sharefileItem
            );

            return [
                'odata.type'   => 'ShareFile.Api.Models.Folder',
                'Id' => 1,
                'Info' => ['CanUpload'   => 1],
            ];
        });

        $client->uploadFileStandard(Argument::any(), 1, Argument::any(), Argument::any())->willReturn('');

        $resource = tmpfile();
        fseek($resource, 0);
        fwrite($resource, $contents);

        $expectedResult = $this->calculateExpectedMetadata($filename, [
            'contents' => $contents,
        ]);

        $this->assertSame($expectedResult, $this->adapter->writeStream($filename, $resource, new Config()));
        $this->assertSame($expectedResult, $this->adapter->updateStream($filename, $resource, new Config()));
    }

    /**
     * Test for it_can_move_a_file.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function it_can_move_a_file(string $filename) // @codingStandardsIgnoreLine
    {
        $filenamePrefix = $this->applyPathPrefix($filename);
        $newPath = '/targetfolder/new name of '.basename($filename);
        $newPathPrefix = $this->applyPathPrefix($newPath);
        $newPathParent = Util::dirname($newPathPrefix);

        $this->client->getItemByPath($newPathParent)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
            'Info' => ['CanUpload'   => 1],
        ]);
        $this->client->getItemByPath($filenamePrefix)->willReturn(
            $this->mockSharefileItem($filename, [
                'Id' => 2,
            ])
        );
        $this->client->getItemByPath($newPathPrefix)->willReturn(
            $this->mockSharefileItem($filename, [
                'Id' => 2,
            ])
        );

        $data = [
            'FileName' =>  basename($newPath),
            'Name' =>  basename($newPath),
            'Parent' =>  [
                'Id' => 1,
            ],
        ];
        $this->client->updateItem(2, $data)->willReturn([]);

        $result = $this->adapter->rename($filename, $newPath);

        $this->assertTrue($result);
    }

    /**
     * Test for it_will_return_false_when_a_move_has_failed.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function it_will_return_false_when_a_move_has_failed(string $filename) // @codingStandardsIgnoreLine
    {
        $filenamePrefix = $this->applyPathPrefix($filename);
        $newPath = '/targetfolder/new name of '.basename($filename);
        $newPathPrefix = $this->applyPathPrefix($newPath);
        $newPathParent = Util::dirname($newPathPrefix);

        $this->client->getItemByPath($newPathParent)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
            'Info' => ['CanUpload'   => 1],
        ]);
        $this->client->getItemByPath($filenamePrefix)->willReturn(
            $this->mockSharefileItem($filename, [
                'Id' => 2,
            ])
        );
        $this->client->getItemByPath($newPathPrefix)->willThrow(
            new BadRequest(new Response(404))
        );

        $data = [
            'FileName' =>  basename($newPath),
            'Name' =>  basename($newPath),
            'Parent' =>  [
                'Id' => 1,
            ],
        ];
        $this->client->updateItem(2, $data)->willReturn([]);

        $result = $this->adapter->rename($filename, $newPath);

        $this->assertFalse($result);
    }

    /**
     * Test for it_can_copy_a_file_to_a_new_folder.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function it_can_copy_a_file_to_a_new_folder(string $filename) // @codingStandardsIgnoreLine
    {
        $filename = basename($filename);
        $filenamePrefix = $this->applyPathPrefix($filename);
        $newPath = 'targetfolder/'.$filename;
        $newPathPrefix = $this->applyPathPrefix($newPath);
        $newPathParent = Util::dirname($newPathPrefix);

        $this->client->getItemByPath($newPathParent)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
            'Info' => ['CanUpload'   => 1],
        ]);

        $this->client->getItemByPath($filenamePrefix)->willReturn(
            $this->mockSharefileItem($filename, [
                'Id' => 2,
            ])
        );

        $this->client->copyItem(1, 2, true)->willReturn([]);

        $this->client->getItemByPath($newPathPrefix)->willReturn(
            $this->mockSharefileItem($newPath)
        );

        $result = $this->adapter->copy($filename, $newPath);

        $this->assertTrue($result);
    }

    /**
     * Test for it_can_copy_a_file_to_same_folder.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function it_can_copy_a_file_to_same_folder(string $filename) // @codingStandardsIgnoreLine
    {
        $filename = basename($filename);
        $filenamePrefix = $this->applyPathPrefix($filename);
        $newPath = 'copy of '.$filename;
        $newPathPrefix = $this->applyPathPrefix($newPath);
        $newPathParent = Util::dirname($newPathPrefix);

        $this->client->getItemByPath($newPathParent)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
            'Info' => ['CanUpload'   => 1],
        ]);

        $this->client->getItemByPath($filenamePrefix)->willReturn(
            $this->mockSharefileItem($filename, [
                'Id' => 2,
            ])
        );

        $this->client->getItemContents(2)->willReturn('foo');

        $this->client->uploadFileStandard(Argument::any(), 1, false, true)->willReturn('');

        $this->client->getItemByPath($newPathPrefix)->willReturn(
            $this->mockSharefileItem($newPath)
        );

        $result = $this->adapter->copy($filename, $newPath);

        $this->assertTrue($result);
    }

    /**
     * Test for it_can_delete_stuff.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function it_can_delete_stuff(string $filename) // @codingStandardsIgnoreLine
    {
        $filenamePrefix = $this->applyPathPrefix($filename);
        $dirname = Util::dirname($filename);
        $dirnamePrefix = $this->applyPathPrefix($dirname);

        $client = $this->client;
        $client->getItemByPath($filenamePrefix)->will(function () use ($client, $filenamePrefix) {
            $client->getItemByPath($filenamePrefix)->willThrow(
                new BadRequest(new Response(404))
            );

            return [
                'odata.type'   => 'ShareFile.Api.Models.File',
                'Id' => '2',
                'Parent' => ['Id' => 1],
            ];
        });

        $client->getItemByPath($dirnamePrefix)->will(function () use ($client, $dirnamePrefix) {
            $client->getItemByPath($dirnamePrefix)->willThrow(
                new BadRequest(new Response(404))
            );

            return [
                'odata.type'   => 'ShareFile.Api.Models.File',
                'Id' => '2',
                'Parent' => ['Id' => 1],
            ];
        });

        $this->client->getItemById(1)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Info' => ['CanDeleteChildItems'   => 1],
        ]);

        $this->client->deleteItem(2)->willReturn('');

        $this->assertTrue($this->adapter->delete($filename));
        $this->assertTrue($this->adapter->deleteDir($dirname));
    }

    /**
     * Test for it_can_create_a_directory.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function it_can_create_a_directory(string $filename) // @codingStandardsIgnoreLine
    {
        $path = substr($filename, 0, -4);
        $directory = basename($path);
        $pathPrefix = $this->applyPathPrefix($path);
        $pathParentPrefix = $this->applyPathPrefix(Util::dirname($path));

        $this->client->getItemByPath($pathParentPrefix)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
            'Info' => ['CanAddFolder'   => 1],
        ]);

        $this->client->createFolder(1, $directory, $directory, true)->willReturn([]);

        $this->client->getItemByPath($pathPrefix)->willReturn(
            $this->mockSharefileItem($path)
        );

        $result = $this->adapter->createDir($path);

        $expectedResult = $this->calculateExpectedMetadata($path);

        $this->assertSame($result, $expectedResult);
    }

    /**
     * Test for it_returns_false_when_parent_folder_is_not_found.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function it_returns_false_when_parent_folder_is_not_found(string $filename) // @codingStandardsIgnoreLine
    {
        $pathParentPrefix = $this->applyPathPrefix(Util::dirname($filename));

        $this->client->getItemByPath($pathParentPrefix)->willThrow(new BadRequest(new Response(404)));

        $this->assertFalse($this->adapter->write($filename, Argument::any(), new Config()));
        $this->assertFalse($this->adapter->update($filename, Argument::any(), new Config()));
        $this->assertFalse($this->adapter->rename(Argument::any(), $filename));
        $this->assertFalse($this->adapter->copy(Argument::any(), $filename));
        $this->assertFalse($this->adapter->createDir($filename, new Config()));
        $this->assertFalse($this->adapter->put($filename, Argument::any()));
    }

    /**
     * Test for it_returns_false_when_parent_folder_has_no_rights.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function it_returns_false_when_parent_folder_has_no_rights(string $filename) // @codingStandardsIgnoreLine
    {
        $pathParentPrefix = $this->applyPathPrefix(Util::dirname($filename));

        $this->client->getItemByPath($pathParentPrefix)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
            'Info' => [
                'CanAddFolder' => 0,
                'CanAddNode' => 0,
                'CanView' => 0,
                'CanDownload' => 0,
                'CanUpload' => 0,
                'CanSend' => 0,
                'CanDeleteCurrentItem' => 0,
                'CanDeleteChildItems' => 0,
                'CanManagePermissions' => 0,
                'CanCreateOfficeDocuments' => 0,
            ],
        ]);

        $this->assertFalse($this->adapter->write($filename, Argument::any(), new Config()));
        $this->assertFalse($this->adapter->update($filename, Argument::any(), new Config()));
        $this->assertFalse($this->adapter->rename(Argument::any(), $filename));
        $this->assertFalse($this->adapter->copy(Argument::any(), $filename));
        $this->assertFalse($this->adapter->createDir($filename, new Config()));
        $this->assertFalse($this->adapter->put($filename, Argument::any()));
    }

    /**
     * Test for it_returns_false_when_parent_folder_has_access_control.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function it_returns_false_when_parent_folder_has_access_control(string $filename) // @codingStandardsIgnoreLine
    {
        $pathParentPrefix = $this->applyPathPrefix(Util::dirname($filename));

        $this->client->getItemByPath($pathParentPrefix)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
        ]);

        $this->assertFalse($this->adapter->write($filename, Argument::any(), new Config()));
        $this->assertFalse($this->adapter->update($filename, Argument::any(), new Config()));
        $this->assertFalse($this->adapter->rename(Argument::any(), $filename));
        $this->assertFalse($this->adapter->copy(Argument::any(), $filename));
        $this->assertFalse($this->adapter->createDir($filename, new Config()));
        $this->assertFalse($this->adapter->put($filename, Argument::any()));
    }

    /**
     * Test for it_returns_false_when_item_is_not_found.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function it_returns_false_when_item_is_not_found(string $filename) // @codingStandardsIgnoreLine
    {
        $filenamePrefix = $this->applyPathPrefix($filename);

        $this->client->getItemByPath(Argument::any())->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
            'Info' => [
                'CanAddFolder' => 1,
                'CanAddNode' => 1,
                'CanView' => 1,
                'CanDownload' => 1,
                'CanUpload' => 1,
                'CanSend' => 1,
                'CanDeleteCurrentItem' => 1,
                'CanDeleteChildItems' => 1,
                'CanManagePermissions' => 1,
                'CanCreateOfficeDocuments' => 1,
            ],
        ]);

        $this->client->getItemByPath($filenamePrefix)->willThrow(new BadRequest(new Response(404)));

        $this->assertFalse($this->adapter->read($filename, Argument::any()));
        $this->assertFalse($this->adapter->listContents($filename, Argument::any()));
        $this->assertFalse($this->adapter->rename($filename, Argument::any()));
        $this->assertFalse($this->adapter->copy($filename, Argument::any()));
        $this->assertFalse($this->adapter->delete($filename));
        $this->assertFalse($this->adapter->deleteDir($filename));
        $this->assertFalse($this->adapter->readAndDelete($filename));
    }

    /**
     * Provider for filenames.
     *
     * @return array
     */
    public function filenameProvider(): array
    {
        return array_map(function (string $filename) {
            return [$filename];
        }, $this->filenames());
    }

    /**
     * Provider for update methods and filenames.
     *
     * @return array
     */
    public function updateProvider():array
    {
        $provider = [];
        foreach ($this->filenames() as $filename) {
            $provider[] = ['write', $filename];
            $provider[] = ['update', $filename];
            $provider[] = ['put', $filename];
        }

        return $provider;
    }

    /**
     * Provider for metadata methods and filenames.
     *
     * @return array
     */
    public function metadataProvider(): array
    {
        $provider = [];
        foreach ($this->filenames() as $filename) {
            $provider[] = ['getMetadata', $filename];
            $provider[] = ['getTimestamp', $filename];
            $provider[] = ['getSize', $filename];
            $provider[] = ['has', $filename];
        }

        return $provider;
    }

    /**
     * List of filenames.
     *
     * @return array
     */
    protected function filenames()
    {
        return [
            'test/test.txt',
            'тёст/тёст.txt',
            'test 1/test.txt',
            'test/test 1.txt',
            'test  1/test  2.txt',
            $this->faker()->word.'/'.$this->randomFileName(),
            $this->faker()->word.'/'.$this->randomFileName(),
            $this->faker()->word.'/'.$this->randomFileName(),
            $this->faker()->word.'/'.$this->randomFileName(),
            $this->faker()->word.'/'.$this->randomFileName(),
        ];
    }

    /**
     * Creates mock ShareFile item.
     *
     * @param   string $filename Filename
     * @param   array  $extra    Additional properties
     * @return  array
     */
    protected function mockSharefileItem(string $filename, array $extra = []):array
    {
        return array_merge(
            [
                'odata.type'   => 'ShareFile.Api.Models.File',
                'ClientModifiedDate' => '2017-09-04T21:48:44Z',
                'FileName' => basename($filename),
                'FileSizeBytes' => 1024,
            ],
            $extra
        );
    }

    /**
     * Calculates expected metadata.
     *
     * @param   string $filename Filename
     * @param   array  $extra    Additional properties
     * @return  array
     */
    protected function calculateExpectedMetadata(string $filename, array $extra = []):array
    {
        return array_merge(
            [
                'timestamp' => 1504561724,
                'path' => $filename,
                'mimetype' => Util::guessMimeType($filename, ''),
                'dirname' => pathinfo($filename, PATHINFO_DIRNAME),
                'extension' => pathinfo($filename, PATHINFO_EXTENSION),
                'filename' => pathinfo($filename, PATHINFO_FILENAME),
                'basename' => pathinfo($filename, PATHINFO_FILENAME),
                'type' => 'file',
                'size' => 1024,
                'contents' => false,
                'stream' => false,
            ],
            $extra
        );
    }

    /**
     * Return Faker Generator.
     *
     * @return Generator
     */
    protected function faker():Generator
    {
        return Factory::create();
    }

    /**
     * Generate random filename.
     *
     * @return string
     */
    protected function randomFileName()
    {
        return $this->faker()->name.'.'.$this->faker()->fileExtension;
    }

    /**
     * Calculate full path with prefix.
     *
     * @param string $path Path
     *
     * @return string
     */
    protected function applyPathPrefix(string $path):string
    {
        return '/'.trim($this->prefix, '/').'/'.trim($path, '/');
    }
}
