<?php

declare(strict_types=1);

/*
 * This file is part of the package sci/sci-api.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Sci\SciApi\Service;

use Sci\SciApi\Constants;
use Sci\SciApi\Validator\ContentBlockValidator;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Imaging\IconProvider\BitmapIconProvider;
use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ConfigurationService
{
    public static function configuration(): array
    {
        $cache = GeneralUtility::makeInstance(CacheManager::class)
            ->getCache(Constants::CACHE);

        if (false === $configuration = $cache->get(Constants::CACHE_CONFIGURATION_ENTRY)) {
            $configuration = self::configurationUncached();
            $cache->set(Constants::CACHE_CONFIGURATION_ENTRY, $configuration, [], 0);
        }

        return $configuration;
    }

    protected static function configurationUncached(): array
    {
        $cbsFinder = new Finder();
        $cbsFinder->directories()->in(Environment::getPublicPath() . Constants::BASEPATH);

        $contentBlockConfiguration = [];
        foreach ($cbsFinder as $cbDir) {
            $_cbConfiguration = self::configurationForContentBlock($cbDir);

            $contentBlockConfiguration [$_cbConfiguration['CType']] = $_cbConfiguration;
        }

        return $contentBlockConfiguration;
    }

    protected static function configurationForContentBlock(\Symfony\Component\Finder\SplFileInfo $splPath): array
    {
        // directory paths (full)
        $realPath = $splPath->getRealPath() . DIRECTORY_SEPARATOR;
        $languageRealPath = $realPath . 'src' . DIRECTORY_SEPARATOR . 'Language' . DIRECTORY_SEPARATOR;

        // directory paths (relative to publicPath())
        $path = Constants::BASEPATH . $splPath->getBasename() . DIRECTORY_SEPARATOR;
        $languagePath = $path . 'src' . DIRECTORY_SEPARATOR . 'Language' . DIRECTORY_SEPARATOR;

        // file paths
        $composerJsonPath = $realPath . 'composer.json';
        $editorInterfaceYamlPath = $realPath . 'EditorInterface.yaml';

        // composer.json
        if (!is_readable($composerJsonPath)) {
            $composerJson = null;
        } else {
            $composerJson = json_decode(file_get_contents($composerJsonPath), true);
        }

        // CType
        if (null === $composerJson) {
            // fallback: use directory name
            $ctype = 'cb_novendor_' . $splPath->getBasename();
        } else {
            [$vendor, $packageName] = explode('/', $composerJson['name']);
            $ctype = $vendor . '_' . $packageName;
        }

        // EditorInterface.yaml
        if (!is_readable($editorInterfaceYamlPath)) {
            throw new \Exception($editorInterfaceYamlPath . ' not found');
        }
        $editorInterfaceYaml = Yaml::parseFile($editorInterfaceYamlPath);

        // .xlf
        $editorInterfaceXlf = is_readable($languageRealPath . 'Default.xlf')
            ? $languagePath . 'Default.xlf'
            : $languagePath . 'EditorInterface.xlf';
        if (!is_readable($editorInterfaceYamlPath)) {
            $editorInterfaceXlf = false;
        }

        $frontendXlf = is_readable($languageRealPath . 'Default.xlf')
            ? $languagePath . 'Default.xlf'
            : $languagePath . 'Frontend.xlf';
        if (!is_readable($editorInterfaceYamlPath)) {
            $frontendXlf = false;
        }

        // icon
        $iconPath = null;
        $iconProviderClass = null;
        foreach (['svg', 'png', 'gif'] as $ext) {
            if (is_readable($realPath . 'ContentBlockIcon.' . $ext)) {
                $iconPath = $path . 'ContentBlockIcon.' . $ext;
                $iconProviderClass = $ext === 'svg'
                    ? SvgIconProvider::class
                    : BitmapIconProvider::class;
                break;
            }
        }
        if ($iconPath === null) {
            throw new \Exception(
                sprintf('No icon found for content block %s', $ctype)
            );
        }

        $cbConfiguration = [
            'path' => $realPath,
            'icon' => $iconPath,
            'iconProviderClass' => $iconProviderClass,
            'CType' => $ctype,
            'EditorInterface.xlf' => $editorInterfaceXlf,
            'Frontend.xlf' => $frontendXlf,
            'yaml' => $editorInterfaceYaml,
        ];

        // validate (throws on error)
        GeneralUtility::makeInstance(ContentBlockValidator::class)
            ->validate($cbConfiguration);

        return $cbConfiguration;
    }
}
