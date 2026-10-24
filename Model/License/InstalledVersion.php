<?php
/**
 * MageMe
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MageMe.com license that is
 * available through the world-wide-web at this URL:
 * https://mageme.com/license
 *
 * Copyright (c) MageMe (https://mageme.com)
 **/

declare(strict_types=1);

namespace MageMe\Core\Model\License;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Filesystem\Driver\File;

/**
 * The Composer version of one installed module, read from that module's own composer.json.
 *
 * Framework's PackageInfo would give the same answer, but it parses the composer.json of every
 * module to answer for one, and this is read on admin pages.
 */
class InstalledVersion
{
    /** @var ComponentRegistrarInterface */
    private $componentRegistrar;
    /** @var File */
    private $file;
    /** @var array<string, string> */
    private $memo = [];

    public function __construct(ComponentRegistrarInterface $componentRegistrar, File $file)
    {
        $this->componentRegistrar = $componentRegistrar;
        $this->file               = $file;
    }

    /**
     * @return string exact "version" from composer.json, '' when the module or the field is missing
     */
    public function get(string $moduleName): string
    {
        if (!array_key_exists($moduleName, $this->memo)) {
            $this->memo[$moduleName] = $this->read($moduleName);
        }

        return $this->memo[$moduleName];
    }

    private function read(string $moduleName): string
    {
        $dir = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName);
        if (!is_string($dir) || $dir === '') {
            return '';
        }
        $path = $dir . '/composer.json';

        try {
            if (!$this->file->isExists($path)) {
                return '';
            }
            $data = json_decode($this->file->fileGetContents($path), true);
        } catch (\Throwable $e) {
            return '';
        }

        return is_array($data) && isset($data['version']) && is_string($data['version'])
            ? $data['version']
            : '';
    }
}
