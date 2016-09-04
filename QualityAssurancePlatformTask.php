<?php

/**
 * File for automated Quality Assurance checks for the platform.
 *
 * @file
 * Contains NextEuropa\Phing\QualityAssurancePlatformTask.
 */

namespace NextEuropa\Phing;

/**
 * Quality assurance task class for the platform.
 */
class QualityAssurancePlatformTask extends QualityAssuranceTask
{
    /**
     * Function to start the quality assurance checks.
     *
     * @param array $selected Array containing the filepathnames.
     *
     * @return void
     */
    public function startQa($selected)
    {
        foreach ($selected as $filepathname) {
            $pathinfo = pathinfo($filepathname);
            // Print header of module, feature or theme.
            echo "\n";
            echo SELF::MAGENTA . SELF::SEPERATOR_DOUBLE;
            echo $pathinfo['dirname'] . "\n";
            echo SELF::MAGENTA . SELF::SEPERATOR_DOUBLE;
            $this->checkCron($pathinfo);
            $this->checkGitDiffUpdateHook($pathinfo);
            $this->checkBypassCodingStandards($pathinfo);
            $this->checkTodos($pathinfo);
            $this->checkCodingStandards($pathinfo);
            echo "\n";
        }
        echo "\n";
        echo SELF::MAGENTA . SELF::SEPERATOR_DOUBLE;
        echo $this->makeFile;
        echo SELF::MAGENTA . SELF::SEPERATOR_DOUBLE;
        $this->checkGitDiffSiteMake($this->makeFile);
    }
}