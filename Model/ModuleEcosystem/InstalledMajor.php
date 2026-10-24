<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */

declare(strict_types=1);

namespace MageMe\Core\Model\ModuleEcosystem;

use Magento\Framework\Module\ModuleListInterface;

/**
 * The major of a module as it is installed here: the leading integer of its setup_version. Null whenever
 * that cannot be told — the module is not enabled, declares no version, or the version starts with no
 * positive integer. The one reading of a major: the catalog's generations and the add-on filter both use it.
 */
class InstalledMajor
{
    private ModuleListInterface $moduleList;

    public function __construct(ModuleListInterface $moduleList)
    {
        $this->moduleList = $moduleList;
    }

    public function of(string $moduleName): ?int
    {
        return $this->ofVersion($this->versionOf($moduleName));
    }

    /**
     * ModuleListInterface only carries ENABLED modules and setup_version is optional, so this
     * returns null often enough that the null path is the important one.
     */
    public function versionOf(string $moduleName): ?string
    {
        $info = $this->moduleList->getOne($moduleName);
        if (!is_array($info) || !isset($info['setup_version'])) {
            return null;
        }
        $version = (string)$info['setup_version'];

        return $version === '' ? null : $version;
    }

    /**
     * Leading integer of a version string: "3.6.0", "v4.0.0", "4.0.0-beta1" → 3, 4, 4.
     *
     * Major 0 is refused: a catalog never names 0 (AddonCompatibility keeps only positive majors), so a
     * 0.x module would otherwise match nothing.
     */
    public function ofVersion(?string $version): ?int
    {
        if ($version === null || $version === '') {
            return null;
        }
        if (preg_match('/^v?(\d+)/', trim($version), $m) !== 1) {
            return null;
        }
        $major = (int)$m[1];

        return $major > 0 ? $major : null;
    }
}
