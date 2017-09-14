<?php

namespace Kapersoft\FlysystemSharefile;

use Exception;
use League\Flysystem\Util;
use League\Flysystem\Config;
use Kapersoft\Sharefile\Client;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;

/**
 * Flysysten ShareFile Adapter.
 *
 * @author   Jan Willem Kaper <kapersoft@gmail.com>
 * @license  MIT (see License.txt)
 *
 * @link     http://github.com/kapersoft/flysystem-sharefile
 */
class SharefileAdapter extends AbstractAdapter
{
//    use StreamedTrait;
    use NotSupportingVisibilityTrait;

    /** ShareFile access control constants */
    const CAN_ADD_FOLDER = 'CanAddFolder';
    const ADD_NODE = 'CanAddNode';
    const CAN_VIEW = 'CanView';
    const CAN_DOWNLOAD = 'CanDownload';
    const CAN_UPLOAD = 'CanUpload';
    const CAN_SEND = 'CanSend';
    const CAN_DELETE_CURRENT_ITEM = 'CanDeleteCurrentItem';
    const CAN_DELETE_CHILD_ITEMS = 'CanDeleteChildItems';
    const CAN_MANAGE_PERMISSIONS = 'CanManagePermissions';
    const CAN_CREATEOFFICE_DOCUMENTS = 'CanCreateOfficeDocuments';

    /**
     * ShareFile Client.
     *
     * @var  \Kapersoft\Sharefile\Client;
     * */
    protected $client;

    /**
     * Indicated if metadata should include the ShareFile item array.
     *
     * @var  bool
     * */
    protected $returnShareFileItem;

    /**
     * SharefileAdapter constructor.
     *
     * @param Client $client              Instance of Kapersoft\Sharefile\Client
     * @param string $prefix              Folder prefix
     * @param bool   $returnShareFileItem Indicated if getMetadata/listContents should return ShareFile item array.
     *
     * @param string $prefix
     */
    public function __construct(Client $client, string $prefix = '', bool $returnShareFileItem = false)
    {
        $this->client = $client;

        $this->returnShareFileItem = $returnShareFileItem;

        $this->setPathPrefix($prefix);
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        if (! $item = $this->getItemByPath($path)) {
            return false;
        }

        if (! $this->checkAccessControl($item, self::CAN_DOWNLOAD)) {
            return false;
        }

        $contents = $this->client->getItemContents($item['Id']);

        return $this->mapItemInfo($item, Util::dirname($path), $contents);
    }

    /**
     * {@inheritdoc}
     */
    public function readStream($path)
    {
        if (! $item = $this->getItemByPath($path)) {
            return false;
        }

        if (! $this->checkAccessControl($item, self::CAN_DOWNLOAD)) {
            return false;
        }

        $url = $this->client->getItemDownloadUrl($item['Id']);

        $stream = fopen($url['DownloadUrl'], 'r');

        return $this->mapItemInfo($item, Util::dirname($path), null, $stream);
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        if (! $item = $this->getItemByPath($directory)) {
            return false;
        }

        return $this->buildItemList($item, $directory, $recursive);
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        if (! $item = $this->getItemByPath($path)) {
            return false;
        }
        $metadata = $this->mapItemInfo($item, Util::dirname($path));

        if (in_array($path, ['/', ''], true)) {
            $metadata['path'] = $path;
        }

        return $metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getmetaData($path);
    }

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config = null)
    {
        return $this->uploadFile($path, $contents, true);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config = null)
    {
        return $this->uploadFile($path, $resource, true);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config = null)
    {
        return $this->uploadFile($path, $contents, true);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config = null)
    {
        return $this->uploadFile($path, $resource, true);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        if (! $targetFolderItem = $this->getItemByPath(Util::dirname($newpath))) {
            return false;
        }

        if (! $this->checkAccessControl($targetFolderItem, self::CAN_UPLOAD)) {
            return false;
        }

        if (! $item = $this->getItemByPath($path)) {
            return false;
        }

        $data = [
            'FileName' =>  basename($newpath),
            'Name' =>  basename($newpath),
            'Parent' =>  [
                'Id' => $targetFolderItem['Id'],
            ],
        ];

        $this->client->updateItem($item['Id'], $data);

        return is_array($this->has($newpath));
    }

    /**
     * {@inheritdoc}
     */
    public function copy($path, $newpath)
    {
        if (! $targetFolderItem = $this->getItemByPath(Util::dirname($newpath))) {
            return false;
        }

        if (! $this->checkAccessControl($targetFolderItem, self::CAN_UPLOAD)) {
            return false;
        }

        if (! $item = $this->getItemByPath($path)) {
            return false;
        }

        if (strcasecmp(Util::dirname($path), Util::dirname($newpath)) != 0 &&
            strcasecmp(basename($path), basename($newpath)) == 0) {
            $this->client->copyItem($targetFolderItem['Id'], $item['Id'], true);
        } else {
            $contents = $this->client->getItemContents($item['Id']);
            $this->uploadFile($newpath, $contents, true);
        }

        return is_array($this->has($newpath));
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        return $this->deleteDir($path);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        if (! $item = $this->getItemByPath($dirname)) {
            return false;
        }

        if (! $this->checkAccessControl($item, self::CAN_DELETE_CURRENT_ITEM)) {
            return false;
        }

        $this->client->deleteItem($item['Id']);

        return $this->has($dirname) === false;
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($dirname, Config $config = null)
    {
        $parentFolder = Util::dirname($dirname);
        $folder = basename($dirname);

        if (! $parentFolderItem = $this->getItemByPath($parentFolder)) {
            return false;
        }

        if (! $this->checkAccessControl($parentFolderItem, self::CAN_ADD_FOLDER)) {
            return false;
        }

        $this->client->createFolder($parentFolderItem['Id'], $folder, $folder, true);

        return $this->has($dirname);
    }

    /**
     * {@inheritdoc}
     */
    public function put($path, $contents)
    {
        return $this->uploadFile($path, $contents, true);
    }

    /**
     * {@inheritdoc}
     */
    public function readAndDelete($path)
    {
        if (! $item = $this->getItemByPath($path)) {
            return false;
        }

        if (! $this->checkAccessControl($item, self::CAN_DOWNLOAD) ||
            ! $this->checkAccessControl($item, self::CAN_DELETE_CURRENT_ITEM)) {
            return false;
        }

        $itemContents = $this->client->getItemContents($item['Id']);

        $this->delete($path);

        return $itemContents;
    }

    /**
     * Returns ShareFile client.
     *
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Upload a file to ShareFile.
     *
     * @param string          $path      File path
     * @param resource|string $contents  Resource or contents of the file
     * @param bool            $overwrite Overwrite file when it exists
     *
     * @return array|false
     */
    protected function uploadFile(string $path, $contents, bool $overwrite = false)
    {
        if (! $parentFolderItem = $this->getItemByPath(Util::dirname($path))) {
            return false;
        }

        if (! $this->checkAccessControl($parentFolderItem, self::CAN_UPLOAD)) {
            return false;
        }

        if (is_string($contents)) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $contents);
            rewind($stream);
        } else {
            $stream = $contents;
        }

        $this->client->uploadFileStreamed($stream, $parentFolderItem['Id'], basename($path), false, $overwrite);

        if ($metadata = $this->getMetadata($path)) {
            if (is_string($contents)) {
                $metadata['contents'] = $contents;
            }

            return $metadata;
        }

        return false;
    }

    /**
     * Map ShareFile item to FlySystem metadata.
     *
     * @param array       $item     ShareFile item
     * @param string      $path     Base path
     * @param string|null $contents Contents of the file (optional)
     * @param mixed|null  $stream   Resource handle of the file (optional)
     *
     * @return array
     */
    protected function mapItemInfo(array $item, string $path = '', string $contents = null, $stream = null): array
    {
        $timestamp = $item['ClientModifiedDate'] ?? $item['ClientCreatedDate'] ??
            $item['CreationDate'] ?? $item['ProgenyEditDate'] ?? '';
        $timestamp = ! empty($timestamp) ? strtotime($timestamp) : false;

        if ($path == '.') {
            $path = '';
        }
        $path = trim($path.'/'.$item['FileName'], '/');

        if ($this->isShareFileApiModelsFile($item)) {
            $mimetype = Util::guessMimeType($item['FileName'], $contents);
            $type = 'file';
        } else {
            $mimetype = 'inode/directory';
            $type = 'dir';
        }

        return array_merge(
            [
                'timestamp' => $timestamp,
                'path' => $path,
                'mimetype' => $mimetype,
                'dirname' => pathinfo($path, PATHINFO_DIRNAME),
                'extension' => pathinfo($item['FileName'], PATHINFO_EXTENSION),
                'filename' => pathinfo($item['FileName'], PATHINFO_FILENAME),
                'basename' => pathinfo($item['FileName'], PATHINFO_FILENAME),
                'type' => $type,
                'size' => $item['FileSizeBytes'],
                'contents' =>  ! empty($contents) ? $contents : false,
                'stream' => ! empty($stream) ? $stream : false,
            ],
            $this->returnShareFileItem ? ['sharefile_item' => $item] : []
        );
    }

    /**
     * Map list of ShareFile items with metadata.
     *
     * @param array  $items List of ShareFile items
     * @param string $path  Base path
     *
     * @return array
     */
    protected function mapItemList(array $items, string $path):array
    {
        return array_map(
            function ($item) use ($path) {
                return $this->mapItemInfo($item, $path);
            },
            $items
        );
    }

    /**
     * Build metadata list from ShareFile item.
     *
     * @param array  $item       ShareFile item
     * @param string $path       Path of the given ShareFile item
     * @param bool   $recursive  Recursive mode
     *
     * @return array
     */
    protected function buildItemList(array $item, string $path, bool $recursive = false):array
    {
        if ($this->isShareFileApiModelsFile($item)) {
            return [];
        }

        $children = $this->client->getItemById($item['Id'], true);

        if ($children['FileCount'] < 2 || ! isset($children['Children'])) {
            return [];
        }

        $children = $this->removeAllExceptFilesAndFolders($children['Children']);

        $itemList = $this->mapItemList($children, $path);

        if ($recursive) {
            foreach ($children as $child) {
                $path = $path.'/'.$child['FileName'];

                $itemList = array_merge(
                    $itemList,
                    $this->buildItemList($child, $path, true)
                );
            }
        }

        return $itemList;
    }

    /**
     * Remove all items except files and folders in the given array of ShareFile items.
     *
     * @param array $items Array of ShareFile items
     *
     * @return array
     */
    protected function removeAllExceptFilesAndFolders(array $items):array
    {
        return array_filter(
            $items,
            function ($item) {
                return $this->isShareFileApiModelsFolder($item) || $this->isShareFileApiModelsFile($item);
            }
        );
    }

    /**
     * Check if ShareFile item is a ShareFile.Api.Models.Folder type.
     *
     * @param array $item
     *
     * @return bool
     */
    protected function isShareFileApiModelsFolder(array $item):bool
    {
        return $item['odata.type'] == 'ShareFile.Api.Models.Folder';
    }

    /**
     * Check if ShareFile item is a ShareFile.Api.Models.File type.
     *
     * @param array $item
     *
     * @return bool
     */
    protected function isShareFileApiModelsFile(array $item):bool
    {
        return $item['odata.type'] == 'ShareFile.Api.Models.File';
    }

    /**
     * Get ShareFile item using path.
     *
     * @param string $path Path of the requested file
     *
     * @return array|false
     *
     * @throws Exception
     */
    protected function getItemByPath(string $path)
    {
        if ($path == '.') {
            $path = '';
        }
        $path = '/'.trim($this->applyPathPrefix($path), '/');

        try {
            $item = $this->client->getItemByPath($path);
            if ($this->isShareFileApiModelsFolder($item) || $this->isShareFileApiModelsFile($item)) {
                return $item;
            }
        } catch (exception $e) {
            return false;
        }

        return false;
    }

    /**
     * Check access control of a ShareFile item.
     *
     * @param array  $item ShareFile item
     * @param string $rule Access rule
     *
     * @return bool
     */
    protected function checkAccessControl(array $item, string $rule):bool
    {
        if ($this->isShareFileApiModelsFile($item)) {
            $item = $this->client->getItemById($item['Parent']['Id']);
            if ($rule == self::CAN_DELETE_CURRENT_ITEM) {
                $rule = self::CAN_DELETE_CHILD_ITEMS;
            }
        }

        if (isset($item['Info'][$rule])) {
            return $item['Info'][$rule] == 1;
        } else {
            return false;
        }
    }
}
