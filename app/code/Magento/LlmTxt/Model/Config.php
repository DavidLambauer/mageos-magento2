<?php
/**
 * Copyright © Mage-OS, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);


namespace Magento\LlmTxt\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

final class Config
{
    private const XML_PATH_ENABLED = 'llmtxt/general/enabled';
    private const XML_PATH_CACHE_LIFETIME = 'llmtxt/general/cache_lifetime';
    private const XML_PATH_SITE_NAME = 'llmtxt/general/site_name';
    private const XML_PATH_SITE_DESCRIPTION = 'llmtxt/general/site_description';
    private const XML_PATH_CUSTOM_CONTENT = 'llmtxt/general/custom_content';
    private const XML_PATH_AUTO_CATEGORIES = 'llmtxt/content/auto_include_categories';
    private const XML_PATH_AUTO_CMS = 'llmtxt/content/auto_include_cms_pages';
    private const XML_PATH_FEATURED_COUNT = 'llmtxt/content/featured_products_count';
    private const XML_PATH_MAX_DESC_LENGTH = 'llmtxt/content/max_description_length';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getCacheLifetime(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_CACHE_LIFETIME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getSiteName(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SITE_NAME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getSiteDescription(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SITE_DESCRIPTION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getCustomContent(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_CUSTOM_CONTENT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function shouldAutoIncludeCategories(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_AUTO_CATEGORIES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function shouldAutoIncludeCmsPages(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_AUTO_CMS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getFeaturedProductsCount(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_FEATURED_COUNT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getMaxDescriptionLength(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_MAX_DESC_LENGTH,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
